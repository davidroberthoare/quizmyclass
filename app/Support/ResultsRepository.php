<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

/** Teacher-facing reads over submitted attempts. The quiz owner's own attempts are excluded from statistics. */
final class ResultsRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function revisions(int $roomId): array
    {
        $statement = $this->database->prepare(
            'SELECT r.id, r.revision_number, r.created_at, ' .
            "(SELECT COUNT(*) FROM quiz_attempts a WHERE a.revision_id = r.id AND a.status != 'in_progress') AS attempts " .
            'FROM quiz_revisions r WHERE r.room_id = :id ORDER BY r.revision_number DESC'
        );
        $statement->execute(['id' => $roomId]);
        return $statement->fetchAll();
    }

    /**
     * One row per student: attempt count, best attempt, latest activity.
     *
     * @return list<array<string, mixed>>
     */
    public function students(int $revisionId, int $ownerId): array
    {
        $statement = $this->database->prepare(
            'SELECT u.id AS user_id, u.display_name, u.email, ' .
            'COUNT(a.id) AS attempts, MAX(a.score) AS best_score, MAX(a.max_score) AS max_score, ' .
            'MAX(a.submitted_at) AS last_at ' .
            'FROM quiz_attempts a JOIN users u ON u.id = a.user_id ' .
            "WHERE a.revision_id = :rev AND a.status != 'in_progress' AND a.user_id != :owner " .
            'GROUP BY u.id ORDER BY u.display_name COLLATE NOCASE'
        );
        $statement->execute(['rev' => $revisionId, 'owner' => $ownerId]);
        return $statement->fetchAll();
    }

    /**
     * @return array{students: int, attempts: int, in_progress: int, average_best: float|null, highest: float|null, lowest: float|null}
     */
    public function summary(int $revisionId, int $ownerId): array
    {
        $students = $this->students($revisionId, $ownerId);
        $percentages = [];
        $attempts = 0;
        foreach ($students as $student) {
            $attempts += (int) $student['attempts'];
            if ((float) $student['max_score'] > 0) {
                $percentages[] = 100 * (float) $student['best_score'] / (float) $student['max_score'];
            }
        }
        $progress = $this->database->prepare(
            "SELECT COUNT(*) FROM quiz_attempts WHERE revision_id = :rev AND status = 'in_progress' AND user_id != :owner"
        );
        $progress->execute(['rev' => $revisionId, 'owner' => $ownerId]);
        return [
            'students' => count($students),
            'attempts' => $attempts,
            'in_progress' => (int) $progress->fetchColumn(),
            'average_best' => $percentages === [] ? null : array_sum($percentages) / count($percentages),
            'highest' => $percentages === [] ? null : max($percentages),
            'lowest' => $percentages === [] ? null : min($percentages),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function attemptsForStudent(int $revisionId, int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM quiz_attempts WHERE revision_id = :rev AND user_id = :user ORDER BY attempt_number'
        );
        $statement->execute(['rev' => $revisionId, 'user' => $userId]);
        return $statement->fetchAll();
    }

    /**
     * Per-question statistics over all submitted attempts, plus how often each choice was picked.
     *
     * @param list<array<string, mixed>> $questions snapshot questions
     * @return list<array<string, mixed>>
     */
    public function itemAnalysis(int $revisionId, int $ownerId, array $questions): array
    {
        $statement = $this->database->prepare(
            'SELECT x.question_id, x.answer_json, x.is_correct FROM quiz_answers x ' .
            'JOIN quiz_attempts a ON a.id = x.attempt_id ' .
            "WHERE a.revision_id = :rev AND a.status != 'in_progress' AND a.user_id != :owner"
        );
        $statement->execute(['rev' => $revisionId, 'owner' => $ownerId]);
        $byQuestion = [];
        foreach ($statement->fetchAll() as $row) {
            $byQuestion[(int) $row['question_id']][] = $row;
        }
        $total = $this->database->prepare(
            "SELECT COUNT(*) FROM quiz_attempts WHERE revision_id = :rev AND status != 'in_progress' AND user_id != :owner"
        );
        $total->execute(['rev' => $revisionId, 'owner' => $ownerId]);
        $attemptCount = (int) $total->fetchColumn();

        $analysis = [];
        foreach ($questions as $position => $question) {
            $rows = $byQuestion[(int) $question['id']] ?? [];
            $correct = count(array_filter($rows, static fn (array $r): bool => (int) $r['is_correct'] === 1));
            $picks = array_fill(0, count($question['choices'] ?? []), 0);
            foreach ($rows as $row) {
                $value = json_decode((string) $row['answer_json'], true);
                foreach (is_array($value) ? $value : [$value] as $pick) {
                    if (is_int($pick) && isset($picks[$pick])) {
                        $picks[$pick]++;
                    }
                }
            }
            $analysis[] = [
                'number' => $position + 1,
                'question' => $question,
                'attempts' => $attemptCount,
                'answered' => count($rows),
                'correct' => $correct,
                'percent_correct' => $attemptCount > 0 ? 100 * $correct / $attemptCount : null,
                'picks' => $picks,
            ];
        }
        return $analysis;
    }

    /** Remove a student's attempts for a revision (null user = everyone). Audited. */
    public function reset(int $roomId, int $revisionId, int $actorId, ?int $userId): int
    {
        $room = $this->database->prepare('SELECT owner_id FROM rooms WHERE id = :id');
        $room->execute(['id' => $roomId]);
        if ((int) $room->fetchColumn() !== $actorId) {
            throw new RuntimeException('Only the quiz owner may reset results.');
        }
        $deleted = 0;
        Database::transactional($this->database, function () use ($roomId, $revisionId, $actorId, $userId, &$deleted): void {
            $sql = 'DELETE FROM quiz_attempts WHERE revision_id = :rev AND room_id = :room'
                . ($userId === null ? '' : ' AND user_id = :user');
            $params = ['rev' => $revisionId, 'room' => $roomId] + ($userId === null ? [] : ['user' => $userId]);
            $statement = $this->database->prepare($sql);
            $statement->execute($params);
            $deleted = $statement->rowCount();
            $this->database->prepare(
                'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
                'VALUES (:actor, :room, :action, :now, :details)'
            )->execute([
                'actor' => $actorId,
                'room' => $roomId,
                'action' => $userId === null ? 'results.reset_all' : 'results.reset_student',
                'now' => QuizRepository::now(),
                'details' => json_encode(['revision_id' => $revisionId, 'attempts_deleted' => $deleted], JSON_THROW_ON_ERROR),
            ]);
        });
        return $deleted;
    }
}
