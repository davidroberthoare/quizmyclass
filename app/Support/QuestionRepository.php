<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use JsonException;
use PDO;
use RuntimeException;

final class QuestionRepository
{
    private const TYPES = [
        'multiple_choice',
        'multiple_select',
        'true_false',
        'short_text',
        'numeric',
    ];

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $roomId, int $actorId, array $input): array
    {
        $room = $this->editableRoom($roomId);
        $questionData = $this->validate($input);
        $isOwner = (int) $room['owner_id'] === $actorId;

        $this->database->beginTransaction();
        try {
            if (!$isOwner) {
                $membership = $this->database->prepare(
                    'INSERT OR IGNORE INTO room_memberships (room_id, user_id, role, created_at) ' .
                    'VALUES (:room_id, :user_id, :role, :created_at)'
                );
                $membership->execute([
                    'room_id' => $roomId,
                    'user_id' => $actorId,
                    'role' => 'student',
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);

                $countStatement = $this->database->prepare(
                    'SELECT COUNT(*) FROM questions WHERE room_id = :room_id AND author_id = :author_id'
                );
                $countStatement->execute(['room_id' => $roomId, 'author_id' => $actorId]);
                if ((int) $countStatement->fetchColumn() >= (int) $room['student_question_cap']) {
                    throw new RuntimeException('You have reached this room’s question limit.');
                }
            }

            $total = $this->database->prepare(
                'SELECT COUNT(*) FROM questions WHERE room_id = :room_id'
            );
            $total->execute(['room_id' => $roomId]);
            if ((int) $total->fetchColumn() >= (int) $room['max_questions']) {
                throw new RuntimeException('This room has reached its question limit.');
            }

            $position = $this->database->prepare(
                'SELECT COALESCE(MAX(position), 0) + 1 FROM questions WHERE room_id = :room_id'
            );
            $position->execute(['room_id' => $roomId]);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $insert = $this->database->prepare(
                'INSERT INTO questions ' .
                '(room_id, author_id, type, prompt_html, explanation_html, choices_json, answer_json, ' .
                'points, status, position, created_at, updated_at) ' .
                'VALUES (:room_id, :author_id, :type, :prompt_html, :explanation_html, :choices_json, ' .
                ':answer_json, :points, :status, :position, :created_at, :updated_at)'
            );
            $insert->execute([
                'room_id' => $roomId,
                'author_id' => $actorId,
                'type' => $questionData['type'],
                'prompt_html' => $questionData['prompt_html'],
                'explanation_html' => $questionData['explanation_html'],
                'choices_json' => $questionData['choices_json'],
                'answer_json' => $questionData['answer_json'],
                'points' => $questionData['points'],
                'status' => 'draft',
                'position' => (int) $position->fetchColumn(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->database->lastInsertId();
            $this->audit($actorId, $roomId, 'question.created', $id);
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        $question = $this->findForEditor($id, $actorId);
        if ($question === null) {
            throw new RuntimeException('Question could not be loaded after creation.');
        }
        return $question;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $questionId, int $actorId, array $input): array
    {
        $question = $this->findRaw($questionId);
        $room = $this->editableRoom((int) $question['room_id']);
        $isOwner = (int) $room['owner_id'] === $actorId;
        if (!$isOwner && (int) $question['author_id'] !== $actorId) {
            throw new RuntimeException('You may edit only your own questions.');
        }
        $questionData = $this->validate($input);

        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'UPDATE questions SET type = :type, prompt_html = :prompt_html, ' .
                'explanation_html = :explanation_html, choices_json = :choices_json, ' .
                'answer_json = :answer_json, points = :points, status = :status, updated_at = :updated_at ' .
                'WHERE id = :id'
            );
            $statement->execute([
                'type' => $questionData['type'],
                'prompt_html' => $questionData['prompt_html'],
                'explanation_html' => $questionData['explanation_html'],
                'choices_json' => $questionData['choices_json'],
                'answer_json' => $questionData['answer_json'],
                'points' => $questionData['points'],
                'status' => 'draft',
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'id' => $questionId,
            ]);
            $this->audit($actorId, (int) $question['room_id'], 'question.updated', $questionId);
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        $updated = $this->findForEditor($questionId, $actorId);
        if ($updated === null) {
            throw new RuntimeException('Updated question could not be loaded.');
        }
        return $updated;
    }

    public function moderate(int $questionId, int $actorId, string $status): void
    {
        if (!in_array($status, ['draft', 'approved', 'rejected', 'hidden'], true)) {
            throw new RuntimeException('Moderation status is invalid.');
        }
        $question = $this->findRaw($questionId);
        $room = $this->editableRoom((int) $question['room_id']);
        $this->assertOwner($room, $actorId);

        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'UPDATE questions SET status = :status, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute([
                'status' => $status,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'id' => $questionId,
            ]);
            $this->audit($actorId, (int) $question['room_id'], 'question.' . $status, $questionId);
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function move(int $questionId, int $actorId, string $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Question move direction is invalid.');
        }
        $question = $this->findRaw($questionId);
        $room = $this->editableRoom((int) $question['room_id']);
        $this->assertOwner($room, $actorId);

        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $neighbor = $this->database->prepare(
            "SELECT id, position FROM questions WHERE room_id = :room_id " .
            "AND position $operator :position ORDER BY position $order LIMIT 1"
        );
        $neighbor->execute([
            'room_id' => $question['room_id'],
            'position' => $question['position'],
        ]);
        $other = $neighbor->fetch();
        if (!is_array($other)) {
            return;
        }

        $this->database->beginTransaction();
        try {
            $update = $this->database->prepare('UPDATE questions SET position = :position WHERE id = :id');
            $update->execute(['position' => $other['position'], 'id' => $questionId]);
            $update->execute(['position' => $question['position'], 'id' => $other['id']]);
            $this->audit($actorId, (int) $question['room_id'], 'question.moved_' . $direction, $questionId);
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function reorderTo(int $questionId, int $actorId, int $targetPosition): void
    {
        $question = $this->findRaw($questionId);
        $room = $this->editableRoom((int) $question['room_id']);
        $this->assertOwner($room, $actorId);

        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM questions WHERE room_id = :room_id'
        );
        $countStatement->execute(['room_id' => $question['room_id']]);
        $count = (int) $countStatement->fetchColumn();
        $targetPosition = max(1, min($count, $targetPosition));
        $currentPosition = (int) $question['position'];
        if ($targetPosition === $currentPosition) {
            return;
        }

        $this->database->beginTransaction();
        try {
            if ($targetPosition < $currentPosition) {
                $statement = $this->database->prepare(
                    'UPDATE questions SET position = position + 1, updated_at = :updated_at ' .
                    'WHERE room_id = :room_id AND position >= :target_position AND position < :current_position'
                );
            } else {
                $statement = $this->database->prepare(
                    'UPDATE questions SET position = position - 1, updated_at = :updated_at ' .
                    'WHERE room_id = :room_id AND position > :current_position AND position <= :target_position'
                );
            }
            $statement->execute([
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'room_id' => $question['room_id'],
                'target_position' => $targetPosition,
                'current_position' => $currentPosition,
            ]);
            $update = $this->database->prepare(
                'UPDATE questions SET position = :position, updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                'position' => $targetPosition,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'id' => $questionId,
            ]);
            $this->audit($actorId, (int) $question['room_id'], 'question.reordered', $questionId);
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listForRoom(int $roomId, int $viewerId, bool $owner): array
    {
        $visibility = $owner
            ? ''
            : 'AND (q.status != \'hidden\' OR q.author_id = :viewer_id)';
        $statement = $this->database->prepare(
            'SELECT q.*, u.display_name AS author_name ' .
            'FROM questions q JOIN users u ON u.id = q.author_id ' .
            "WHERE q.room_id = :room_id $visibility ORDER BY q.position, q.id"
        );
        $parameters = ['room_id' => $roomId];
        if (!$owner) {
            $parameters['viewer_id'] = $viewerId;
        }
        $statement->execute($parameters);

        $questions = $statement->fetchAll();
        foreach ($questions as &$question) {
            $question['can_edit'] = $owner || (int) $question['author_id'] === $viewerId;
            $question['can_see_answer'] = $question['can_edit'];
            if (!$question['can_see_answer']) {
                $question['answer_json'] = '';
                $question['explanation_html'] = '';
            }
        }
        unset($question);
        return $questions;
    }

    /** @return array<string, mixed>|null */
    public function findForEditor(int $questionId, int $actorId): ?array
    {
        $question = $this->findRawOrNull($questionId);
        if ($question === null) {
            return null;
        }
        $room = $this->findRoom((int) $question['room_id']);
        if ((int) $room['owner_id'] !== $actorId && (int) $question['author_id'] !== $actorId) {
            throw new RuntimeException('You may edit only your own questions.');
        }
        if ($room['state'] !== 'editing') {
            throw new RuntimeException('Questions cannot be edited after a room is activated.');
        }
        return $question;
    }

    /** @return array<string, mixed> */
    private function validate(array $input): array
    {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Question type is invalid.');
        }

        $sanitizer = new RichTextSanitizer();
        $prompt = $sanitizer->clean((string) ($input['prompt_html'] ?? ''));
        $explanation = $sanitizer->clean((string) ($input['explanation_html'] ?? ''), true);
        $points = filter_var($input['points'] ?? 1, FILTER_VALIDATE_FLOAT);
        if ($points === false || $points < 0.1 || $points > 100) {
            throw new RuntimeException('Points must be between 0.1 and 100.');
        }

        $choices = [];
        $answer = [];
        if ($type === 'multiple_choice' || $type === 'multiple_select') {
            $choices = $this->choices((string) ($input['choices'] ?? ''));
            $rawCorrect = $input['correct'] ?? [];
            $rawCorrect = is_array($rawCorrect) ? $rawCorrect : [$rawCorrect];
            $correct = [];
            foreach ($rawCorrect as $value) {
                $index = filter_var($value, FILTER_VALIDATE_INT);
                if ($index === false || $index < 0 || $index >= count($choices)) {
                    throw new RuntimeException('A correct answer selection is invalid.');
                }
                $correct[] = $index;
            }
            $correct = array_values(array_unique($correct));
            if ($type === 'multiple_choice' && count($correct) !== 1) {
                throw new RuntimeException('Multiple choice requires exactly one correct answer.');
            }
            if ($type === 'multiple_select' && $correct === []) {
                throw new RuntimeException('Select at least one correct answer.');
            }
            $answer = $type === 'multiple_choice'
                ? ['correct_index' => $correct[0]]
                : ['correct_indexes' => $correct];
        } elseif ($type === 'true_false') {
            $value = (string) ($input['correct'] ?? '');
            if (!in_array($value, ['true', 'false'], true)) {
                throw new RuntimeException('Choose true or false as the correct answer.');
            }
            $answer = ['correct' => $value === 'true'];
        } elseif ($type === 'short_text') {
            $accepted = preg_split('/\r\n|\r|\n/', trim((string) ($input['accepted_answers'] ?? '')));
            $accepted = array_values(array_unique(array_filter(
                array_map(static fn (string $value): string => trim($value), $accepted ?: []),
                static fn (string $value): bool => $value !== '',
            )));
            if ($accepted === [] || count($accepted) > 20 || max(array_map('strlen', $accepted)) > 200) {
                throw new RuntimeException('Provide 1 to 20 accepted answers, each at most 200 characters.');
            }
            $answer = ['accepted_answers' => $accepted];
        } elseif ($type === 'numeric') {
            $correct = filter_var($input['numeric_answer'] ?? null, FILTER_VALIDATE_FLOAT);
            $tolerance = filter_var($input['numeric_tolerance'] ?? 0, FILTER_VALIDATE_FLOAT);
            if ($correct === false || abs($correct) > 1000000000) {
                throw new RuntimeException('Numeric answer is invalid.');
            }
            if ($tolerance === false || $tolerance < 0 || $tolerance > 1000000000) {
                throw new RuntimeException('Numeric tolerance is invalid.');
            }
            $answer = ['correct' => $correct, 'tolerance' => $tolerance];
        }

        try {
            return [
                'type' => $type,
                'prompt_html' => $prompt,
                'explanation_html' => $explanation,
                'choices_json' => json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'answer_json' => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'points' => $points,
            ];
        } catch (JsonException $exception) {
            throw new RuntimeException('Question data could not be encoded.', 0, $exception);
        }
    }

    /** @return list<string> */
    private function choices(string $input): array
    {
        $choices = preg_split('/\r\n|\r|\n/', trim($input));
        $choices = array_values(array_unique(array_filter(
            array_map(static fn (string $value): string => trim($value), $choices ?: []),
            static fn (string $value): bool => $value !== '',
        )));
        if (count($choices) < 2 || count($choices) > 10
            || max(array_map('strlen', $choices)) > 300
        ) {
            throw new RuntimeException('Provide 2 to 10 unique choices, each at most 300 characters.');
        }
        return $choices;
    }

    /** @return array<string, mixed> */
    private function findRaw(int $questionId): array
    {
        $question = $this->findRawOrNull($questionId);
        if ($question === null) {
            throw new RuntimeException('Question not found.');
        }
        return $question;
    }

    /** @return array<string, mixed>|null */
    private function findRawOrNull(int $questionId): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM questions WHERE id = :id');
        $statement->execute(['id' => $questionId]);
        $question = $statement->fetch();
        return is_array($question) ? $question : null;
    }

    /** @return array<string, mixed> */
    private function editableRoom(int $roomId): array
    {
        $room = $this->findRoom($roomId);
        if ($room['state'] !== 'editing') {
            throw new RuntimeException('Questions cannot be changed after a room is activated.');
        }
        return $room;
    }

    /** @return array<string, mixed> */
    private function findRoom(int $roomId): array
    {
        $statement = $this->database->prepare('SELECT * FROM rooms WHERE id = :id');
        $statement->execute(['id' => $roomId]);
        $room = $statement->fetch();
        if (!is_array($room)) {
            throw new RuntimeException('Room not found.');
        }
        return $room;
    }

    /** @param array<string, mixed> $room */
    private function assertOwner(array $room, int $actorId): void
    {
        if ((int) $room['owner_id'] !== $actorId) {
            throw new RuntimeException('Only the room owner may perform this action.');
        }
    }

    private function audit(int $actorId, int $roomId, string $action, int $questionId): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
            'VALUES (:actor_user_id, :room_id, :action, :created_at, :details_json)'
        );
        $statement->execute([
            'actor_user_id' => $actorId,
            'room_id' => $roomId,
            'action' => $action,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'details_json' => json_encode(
                ['question_id' => $questionId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ]);
    }
}
