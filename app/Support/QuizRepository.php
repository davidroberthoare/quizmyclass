<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

/** Quiz settings and lifecycle: editing -> active -> closed, with an immutable revision per activation. */
final class QuizRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $timezone = 'UTC',
    ) {
    }

    /**
     * @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    public static function settingsFor(array $room): array
    {
        return QuizSettings::fromStored((string) ($room['settings_json'] ?? '{}'));
    }

    /** @param array<string, mixed> $input */
    public function saveSettings(int $roomId, int $actorId, array $input): void
    {
        $room = $this->ownedRoom($roomId, $actorId);
        if ($room['state'] !== 'editing') {
            throw new RuntimeException('Quiz settings can only change while the quiz is being edited.');
        }
        $settings = QuizSettings::validate($input, $this->timezone);
        Database::transactional($this->database, function () use ($roomId, $actorId, $settings): void {
            $this->database
                ->prepare('UPDATE rooms SET settings_json = :json, updated_at = :now WHERE id = :id')
                ->execute([
                    'json' => json_encode($settings, JSON_THROW_ON_ERROR),
                    'now' => self::now(),
                    'id' => $roomId,
                ]);
            $this->audit($actorId, $roomId, 'quiz.settings_updated', []);
        });
    }

    /**
     * Freeze approved questions and current settings into a new revision and open the quiz.
     *
     * @return int the new revision id
     */
    public function activate(int $roomId, int $actorId): int
    {
        $room = $this->ownedRoom($roomId, $actorId);
        if ($room['state'] !== 'editing') {
            throw new RuntimeException('Only a quiz in editing mode can be activated.');
        }
        $settings = self::settingsFor($room);
        $problems = $this->activationProblems($roomId, $settings);
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $revisionId = 0;
        Database::transactional(
            $this->database,
            function () use (&$revisionId, $roomId, $actorId, $settings): void {
                $questions = $this->approvedSnapshot($roomId);
                $number = $this->database->prepare(
                    'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM quiz_revisions WHERE room_id = :id'
                );
                $number->execute(['id' => $roomId]);
                $revisionNumber = (int) $number->fetchColumn();
                $this->database->prepare(
                    'INSERT INTO quiz_revisions (room_id, revision_number, settings_json, questions_json, created_by, created_at) ' .
                    'VALUES (:room_id, :number, :settings, :questions, :actor, :now)'
                )->execute([
                    'room_id' => $roomId,
                    'number' => $revisionNumber,
                    'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                    'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'actor' => $actorId,
                    'now' => self::now(),
                ]);
                $revisionId = (int) $this->database->lastInsertId();
                $this->setState($roomId, 'active');
                $this->audit($actorId, $roomId, 'quiz.activated', [
                    'revision' => $revisionNumber,
                    'questions' => count($questions),
                ]);
            },
        );
        return $revisionId;
    }

    /** Stop accepting attempts. Unfinished attempts are graded and submitted as they stand. */
    public function close(int $roomId, int $actorId): void
    {
        $room = $this->ownedRoom($roomId, $actorId);
        if ($room['state'] !== 'active') {
            throw new RuntimeException('Only an active quiz can be closed.');
        }
        $this->transition($roomId, $actorId, 'closed', 'quiz.closed');
    }

    /** Return to editing so questions and settings can change; earlier attempts stay with their revision. */
    public function reopenForEditing(int $roomId, int $actorId): void
    {
        $room = $this->ownedRoom($roomId, $actorId);
        if ($room['state'] === 'editing') {
            throw new RuntimeException('This quiz is already being edited.');
        }
        $this->transition($roomId, $actorId, 'editing', 'quiz.reopened');
    }

    /** @return list<string> reasons the quiz cannot be activated yet (empty when ready) */
    public function activationProblems(int $roomId, array $settings): array
    {
        $statement = $this->database->prepare(
            "SELECT COUNT(*) FROM questions WHERE room_id = :id AND status = 'approved'"
        );
        $statement->execute(['id' => $roomId]);
        $approved = (int) $statement->fetchColumn();
        $needed = max(1, (int) ($settings['min_approved'] ?? 1));
        $problems = [];
        if ($approved < $needed) {
            $problems[] = sprintf(
                'Approve at least %d question%s before activating (%d approved so far).',
                $needed,
                $needed === 1 ? '' : 's',
                $approved,
            );
        }
        if (!empty($settings['open_at']) && !empty($settings['close_at'])
            && $settings['close_at'] <= $settings['open_at']
        ) {
            $problems[] = 'The close time must be after the open time.';
        }
        if (!empty($settings['close_at']) && $settings['close_at'] <= self::now()) {
            $problems[] = 'The close time has already passed.';
        }
        return $problems;
    }

    /** @return array<string, mixed>|null */
    public function latestRevision(int $roomId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM quiz_revisions WHERE room_id = :id ORDER BY revision_number DESC LIMIT 1'
        );
        $statement->execute(['id' => $roomId]);
        $revision = $statement->fetch();
        return is_array($revision) ? $revision : null;
    }

    /** @return array<string, mixed>|null */
    public function revision(int $revisionId): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM quiz_revisions WHERE id = :id');
        $statement->execute(['id' => $revisionId]);
        $revision = $statement->fetch();
        return is_array($revision) ? $revision : null;
    }

    /**
     * @param array<string, mixed> $revision
     * @return list<array<string, mixed>>
     */
    public static function revisionQuestions(array $revision): array
    {
        $questions = json_decode((string) $revision['questions_json'], true);
        return is_array($questions) ? $questions : [];
    }

    /**
     * @param array<string, mixed> $revision
     * @return array<string, mixed>
     */
    public static function revisionSettings(array $revision): array
    {
        return QuizSettings::fromStored((string) $revision['settings_json']);
    }

    /** @return list<array<string, mixed>> */
    private function approvedSnapshot(int $roomId): array
    {
        $statement = $this->database->prepare(
            "SELECT id, type, prompt_html, explanation_html, choices_json, answer_json, points " .
            "FROM questions WHERE room_id = :id AND status = 'approved' ORDER BY position, id"
        );
        $statement->execute(['id' => $roomId]);
        $snapshot = [];
        foreach ($statement->fetchAll() as $row) {
            $snapshot[] = [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'prompt_html' => $row['prompt_html'],
                'explanation_html' => $row['explanation_html'],
                'choices' => json_decode((string) $row['choices_json'], true) ?: [],
                'answer' => json_decode((string) $row['answer_json'], true) ?: [],
                'points' => (float) $row['points'],
            ];
        }
        return $snapshot;
    }

    private function transition(int $roomId, int $actorId, string $state, string $action): void
    {
        Database::transactional($this->database, function () use ($roomId, $actorId, $state, $action): void {
            // Grade unfinished attempts so no one is left mid-quiz on content that is going away.
            (new AttemptRepository($this->database))->finalizeAllOpen($roomId);
            $this->setState($roomId, $state);
            $this->audit($actorId, $roomId, $action, []);
        });
    }

    private function setState(int $roomId, string $state): void
    {
        $this->database
            ->prepare('UPDATE rooms SET state = :state, updated_at = :now WHERE id = :id')
            ->execute(['state' => $state, 'now' => self::now(), 'id' => $roomId]);
    }

    /** @return array<string, mixed> */
    private function ownedRoom(int $roomId, int $actorId): array
    {
        $statement = $this->database->prepare('SELECT * FROM rooms WHERE id = :id');
        $statement->execute(['id' => $roomId]);
        $room = $statement->fetch();
        if (!is_array($room)) {
            throw new RuntimeException('Quiz not found.');
        }
        if ((int) $room['owner_id'] !== $actorId) {
            throw new RuntimeException('Only the quiz owner may do this.');
        }
        return $room;
    }

    /** @param array<string, mixed> $details */
    private function audit(int $actorId, int $roomId, string $action, array $details): void
    {
        $this->database->prepare(
            'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
            'VALUES (:actor, :room, :action, :now, :details)'
        )->execute([
            'actor' => $actorId,
            'room' => $roomId,
            'action' => $action,
            'now' => self::now(),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
