<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

/** Student attempts: start/resume, autosave, grading, and expiry. Every attempt belongs to one revision. */
final class AttemptRepository
{
    /** Seconds past the deadline during which an autosave is still accepted (clock skew, slow networks). */
    private const GRACE_SECONDS = 10;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * What may this user do right now?
     *
     * @param array<string, mixed> $room
     * @param array<string, mixed>|null $revision
     * @return array{status: string, message: string, used: int, max: int, attempt: array<string, mixed>|null}
     */
    public function availability(array $room, ?array $revision, int $userId): array
    {
        $none = ['used' => 0, 'max' => 0, 'attempt' => null];
        if ($revision === null || $room['state'] !== 'active') {
            return ['status' => 'not_active', 'message' => 'This quiz is not open for attempts.'] + $none;
        }
        $settings = QuizRepository::revisionSettings($revision);
        $max = (int) $settings['max_attempts'];

        $open = $this->openAttempt((int) $revision['id'], $userId);
        $used = $this->attemptCount((int) $revision['id'], $userId);
        $base = ['used' => $used, 'max' => $max, 'attempt' => $open];
        if ($open !== null) {
            return ['status' => 'resume', 'message' => 'You have an attempt in progress.'] + $base;
        }
        $now = QuizRepository::now();
        if (!empty($settings['open_at']) && $now < $settings['open_at']) {
            return ['status' => 'not_open', 'message' => 'This quiz has not opened yet.'] + $base;
        }
        if (!empty($settings['close_at']) && $now >= $settings['close_at']) {
            return ['status' => 'window_closed', 'message' => 'The deadline for this quiz has passed.'] + $base;
        }
        if ($max > 0 && $used >= $max) {
            return ['status' => 'no_attempts', 'message' => 'You have used all your attempts.'] + $base;
        }
        return ['status' => 'ready', 'message' => ''] + $base;
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $revision
     * @return array<string, mixed>
     */
    public function startOrResume(array $room, array $revision, int $userId): array
    {
        $this->finalizeDueForUser((int) $room['id'], $userId);
        $availability = $this->availability($room, $revision, $userId);
        if ($availability['status'] === 'resume') {
            return $availability['attempt'];
        }
        if ($availability['status'] !== 'ready') {
            throw new RuntimeException($availability['message']);
        }

        $settings = QuizRepository::revisionSettings($revision);
        $started = time();
        $deadline = null;
        if (!empty($settings['duration_minutes'])) {
            $deadline = $started + ((int) $settings['duration_minutes']) * 60;
        }
        if (!empty($settings['close_at'])) {
            $close = strtotime((string) $settings['close_at']);
            $deadline = $deadline === null ? $close : min($deadline, $close);
        }

        $attemptId = 0;
        Database::transactional($this->database, function () use (
            &$attemptId, $room, $revision, $userId, $started, $deadline
        ): void {
            if ((int) $room['owner_id'] !== $userId) {
                $this->database->prepare(
                    'INSERT OR IGNORE INTO room_memberships (room_id, user_id, role, created_at) ' .
                    "VALUES (:room, :user, 'student', :now)"
                )->execute(['room' => $room['id'], 'user' => $userId, 'now' => QuizRepository::now()]);
            }
            $number = $this->database->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM quiz_attempts WHERE revision_id = :rev AND user_id = :user'
            );
            $number->execute(['rev' => $revision['id'], 'user' => $userId]);
            $this->database->prepare(
                'INSERT INTO quiz_attempts (revision_id, room_id, user_id, attempt_number, seed, started_at, deadline_at) ' .
                'VALUES (:rev, :room, :user, :number, :seed, :started, :deadline)'
            )->execute([
                'rev' => $revision['id'],
                'room' => $room['id'],
                'user' => $userId,
                'number' => (int) $number->fetchColumn(),
                'seed' => random_int(1, 2147483646),
                'started' => gmdate('Y-m-d\TH:i:s\Z', $started),
                'deadline' => $deadline === null ? null : gmdate('Y-m-d\TH:i:s\Z', $deadline),
            ]);
            $attemptId = (int) $this->database->lastInsertId();
        });

        $attempt = $this->find($attemptId);
        if ($attempt === null) {
            throw new RuntimeException('The attempt could not be created.');
        }
        return $attempt;
    }

    /** @return array<string, mixed>|null */
    public function find(int $attemptId): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM quiz_attempts WHERE id = :id');
        $statement->execute(['id' => $attemptId]);
        $attempt = $statement->fetch();
        return is_array($attempt) ? $attempt : null;
    }

    /**
     * Load an attempt that must belong to $userId, finishing it first if time ran out.
     *
     * @return array<string, mixed>
     */
    public function findOwned(int $attemptId, int $userId): array
    {
        $attempt = $this->find($attemptId);
        if ($attempt === null || (int) $attempt['user_id'] !== $userId) {
            throw new RuntimeException('Attempt not found.');
        }
        return $this->finalizeIfDue($attempt);
    }

    /**
     * Save one answer. A blank/invalid answer clears it.
     *
     * @return array<string, mixed> the attempt (status may have changed if time ran out)
     */
    public function saveAnswer(int $attemptId, int $userId, int $questionId, mixed $raw): array
    {
        $attempt = $this->find($attemptId);
        if ($attempt === null || (int) $attempt['user_id'] !== $userId) {
            throw new RuntimeException('Attempt not found.');
        }
        if ($attempt['status'] === 'in_progress' && $this->pastDeadline($attempt, self::GRACE_SECONDS)) {
            return $this->finalizeIfDue($attempt);
        }
        if ($attempt['status'] !== 'in_progress') {
            throw new RuntimeException('This attempt has already been submitted.');
        }
        $question = $this->questionMap($attempt)[$questionId] ?? null;
        if ($question === null) {
            throw new RuntimeException('That question is not part of this quiz.');
        }
        $value = Grader::normalize($question, $raw);
        if ($value === null) {
            $this->database
                ->prepare('DELETE FROM quiz_answers WHERE attempt_id = :a AND question_id = :q')
                ->execute(['a' => $attemptId, 'q' => $questionId]);
        } else {
            $this->database->prepare(
                'INSERT INTO quiz_answers (attempt_id, question_id, answer_json, updated_at) ' .
                'VALUES (:a, :q, :json, :now) ' .
                'ON CONFLICT(attempt_id, question_id) DO UPDATE SET answer_json = :json, updated_at = :now'
            )->execute([
                'a' => $attemptId,
                'q' => $questionId,
                'json' => json_encode($value, JSON_THROW_ON_ERROR),
                'now' => QuizRepository::now(),
            ]);
        }
        return $attempt;
    }

    /**
     * Submit an attempt. Optional final answers (question id => raw answer) are saved first.
     *
     * @param array<int|string, mixed> $finalAnswers
     * @return array<string, mixed>
     */
    public function submit(int $attemptId, int $userId, array $finalAnswers = []): array
    {
        $attempt = $this->findOwned($attemptId, $userId);
        if ($attempt['status'] !== 'in_progress') {
            return $attempt;
        }
        Database::transactional($this->database, function () use ($attempt, $userId, $finalAnswers): void {
            foreach ($finalAnswers as $questionId => $raw) {
                if (is_numeric($questionId)) {
                    try {
                        $this->saveAnswer((int) $attempt['id'], $userId, (int) $questionId, $raw);
                    } catch (RuntimeException) {
                        // A stale or unknown question id must not block the submission.
                    }
                }
            }
            $this->gradeAndClose($attempt, 'submitted');
        });
        return $this->find($attemptId) ?? $attempt;
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public function finalizeIfDue(array $attempt): array
    {
        if ($attempt['status'] === 'in_progress' && $this->pastDeadline($attempt, 0)) {
            Database::transactional($this->database, fn () => $this->gradeAndClose($attempt, 'expired'));
            return $this->find((int) $attempt['id']) ?? $attempt;
        }
        return $attempt;
    }

    /** Grade every unfinished attempt in a quiz (used when it is closed or reopened). Caller owns the transaction. */
    public function finalizeAllOpen(int $roomId): void
    {
        $statement = $this->database->prepare(
            "SELECT * FROM quiz_attempts WHERE room_id = :room AND status = 'in_progress'"
        );
        $statement->execute(['room' => $roomId]);
        foreach ($statement->fetchAll() as $attempt) {
            $this->gradeAndClose($attempt, 'submitted');
        }
    }

    private function finalizeDueForUser(int $roomId, int $userId): void
    {
        $statement = $this->database->prepare(
            "SELECT * FROM quiz_attempts WHERE room_id = :room AND user_id = :user AND status = 'in_progress'"
        );
        $statement->execute(['room' => $roomId, 'user' => $userId]);
        foreach ($statement->fetchAll() as $attempt) {
            $this->finalizeIfDue($attempt);
        }
    }

    /**
     * Grade saved answers against the frozen revision. Caller owns the transaction.
     *
     * @param array<string, mixed> $attempt
     */
    private function gradeAndClose(array $attempt, string $status): void
    {
        $questions = $this->questionMap($attempt);
        $answers = $this->answers((int) $attempt['id']);
        $total = 0.0;
        $max = 0.0;
        $update = $this->database->prepare(
            'UPDATE quiz_answers SET is_correct = :ok, points_awarded = :points WHERE attempt_id = :a AND question_id = :q'
        );
        foreach ($questions as $id => $question) {
            $max += (float) $question['points'];
            if (!array_key_exists($id, $answers)) {
                continue;
            }
            $result = Grader::grade($question, $answers[$id]);
            $total += $result['points'];
            $update->execute([
                'ok' => $result['correct'] ? 1 : 0,
                'points' => $result['points'],
                'a' => $attempt['id'],
                'q' => $id,
            ]);
        }
        $finished = $status === 'expired' && !empty($attempt['deadline_at'])
            ? (string) $attempt['deadline_at']
            : QuizRepository::now();
        $this->database->prepare(
            'UPDATE quiz_attempts SET status = :status, submitted_at = :at, score = :score, max_score = :max WHERE id = :id'
        )->execute([
            'status' => $status,
            'at' => $finished,
            'score' => $total,
            'max' => $max,
            'id' => $attempt['id'],
        ]);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<int, array<string, mixed>> question id => snapshot question
     */
    public function questionMap(array $attempt): array
    {
        $statement = $this->database->prepare('SELECT questions_json FROM quiz_revisions WHERE id = :id');
        $statement->execute(['id' => $attempt['revision_id']]);
        $questions = json_decode((string) $statement->fetchColumn(), true);
        $map = [];
        foreach (is_array($questions) ? $questions : [] as $question) {
            $map[(int) $question['id']] = $question;
        }
        return $map;
    }

    /** @return array<int, mixed> question id => decoded answer */
    public function answers(int $attemptId): array
    {
        $statement = $this->database->prepare('SELECT question_id, answer_json FROM quiz_answers WHERE attempt_id = :a');
        $statement->execute(['a' => $attemptId]);
        $answers = [];
        foreach ($statement->fetchAll() as $row) {
            $answers[(int) $row['question_id']] = json_decode((string) $row['answer_json'], true);
        }
        return $answers;
    }

    /** @return array<int, array<string, mixed>> question id => answer row including grading */
    public function gradedAnswers(int $attemptId): array
    {
        $statement = $this->database->prepare('SELECT * FROM quiz_answers WHERE attempt_id = :a');
        $statement->execute(['a' => $attemptId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $row['answer'] = json_decode((string) $row['answer_json'], true);
            $rows[(int) $row['question_id']] = $row;
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $roomId, int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT a.*, r.revision_number FROM quiz_attempts a JOIN quiz_revisions r ON r.id = a.revision_id ' .
            'WHERE a.room_id = :room AND a.user_id = :user ORDER BY a.id DESC'
        );
        $statement->execute(['room' => $roomId, 'user' => $userId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function openAttempt(int $revisionId, int $userId): ?array
    {
        $statement = $this->database->prepare(
            "SELECT * FROM quiz_attempts WHERE revision_id = :rev AND user_id = :user AND status = 'in_progress' LIMIT 1"
        );
        $statement->execute(['rev' => $revisionId, 'user' => $userId]);
        $attempt = $statement->fetch();
        return is_array($attempt) ? $attempt : null;
    }

    private function attemptCount(int $revisionId, int $userId): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM quiz_attempts WHERE revision_id = :rev AND user_id = :user'
        );
        $statement->execute(['rev' => $revisionId, 'user' => $userId]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $attempt */
    private function pastDeadline(array $attempt, int $graceSeconds): bool
    {
        return !empty($attempt['deadline_at'])
            && time() > strtotime((string) $attempt['deadline_at']) + $graceSeconds;
    }
}
