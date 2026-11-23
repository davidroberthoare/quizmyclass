<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use PDOException;
use RuntimeException;

final class RoomRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new RuntimeException('Quiz link must use lowercase letters, numbers, and single hyphens.');
        }
        if (strlen($slug) < 3 || strlen($slug) > 60) {
            throw new RuntimeException('Quiz link must be between 3 and 60 characters.');
        }
        return $slug;
    }

    /** @return array<string, mixed> */
    public function create(int $ownerId, string $name, string $slug): array
    {
        $name = trim($name);
        $slug = self::normalizeSlug($slug);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Quiz name must be between 1 and 120 characters.');
        }

        $roomId = 0;
        try {
            Database::transactional($this->database, function () use (&$roomId, $slug, $name, $ownerId): void {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $insert = $this->database->prepare(
                    'INSERT INTO rooms (slug, name, owner_id, state, created_at, updated_at) ' .
                    'VALUES (:slug, :name, :owner_id, :state, :created_at, :updated_at)'
                );
                $insert->execute([
                    'slug' => $slug,
                    'name' => $name,
                    'owner_id' => $ownerId,
                    'state' => 'editing',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $roomId = (int) $this->database->lastInsertId();

                $membership = $this->database->prepare(
                    'INSERT INTO room_memberships (room_id, user_id, role, created_at) ' .
                    'VALUES (:room_id, :user_id, :role, :created_at)'
                );
                $membership->execute([
                    'room_id' => $roomId,
                    'user_id' => $ownerId,
                    'role' => 'owner',
                    'created_at' => $now,
                ]);
                $audit = $this->database->prepare(
                    'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
                    'VALUES (:actor_user_id, :room_id, :action, :created_at, :details_json)'
                );
                $audit->execute([
                    'actor_user_id' => $ownerId,
                    'room_id' => $roomId,
                    'action' => 'room.created',
                    'created_at' => $now,
                    'details_json' => json_encode(
                        ['slug' => $slug],
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                ]);
            });
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed: rooms.slug')) {
                throw new RuntimeException('That quiz link is already in use.', 0, $exception);
            }
            throw $exception;
        }

        $room = $this->findById($roomId);
        if ($room === null) {
            throw new RuntimeException('Quiz could not be loaded after creation.');
        }
        return $room;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $slug = self::normalizeSlug($slug);
        $statement = $this->database->prepare('SELECT * FROM rooms WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $room = $statement->fetch();
        return is_array($room) ? $room : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM rooms WHERE id = :id');
        $statement->execute(['id' => $id]);
        $room = $statement->fetch();
        return is_array($room) ? $room : null;
    }

    /** @return list<array<string, mixed>> */
    public function forOwner(int $ownerId): array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM rooms WHERE owner_id = :owner_id ORDER BY created_at DESC'
        );
        $statement->execute(['owner_id' => $ownerId]);
        return $statement->fetchAll();
    }

    /**
     * Rooms the user owns or has contributed to, with question counts.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT r.*, m.role AS member_role, ' .
            '(SELECT COUNT(*) FROM questions q WHERE q.room_id = r.id) AS question_count ' .
            'FROM rooms r JOIN room_memberships m ON m.room_id = r.id AND m.user_id = :user_id ' .
            'ORDER BY r.updated_at DESC, r.id DESC'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function roleForUser(int $roomId, int $userId): string
    {
        $statement = $this->database->prepare(
            'SELECT role FROM room_memberships WHERE room_id = :room_id AND user_id = :user_id'
        );
        $statement->execute(['room_id' => $roomId, 'user_id' => $userId]);
        $role = $statement->fetchColumn();
        if (is_string($role)) {
            return $role;
        }

        return 'student';
    }

    /** Change the printed name and/or link while the quiz is still being edited. */
    public function rename(int $roomId, int $actorId, string $name, string $slug): void
    {
        $room = $this->findById($roomId);
        if ($room === null) {
            throw new RuntimeException('Quiz not found.');
        }
        if ((int) $room['owner_id'] !== $actorId) {
            throw new RuntimeException('Only the quiz owner may rename it.');
        }
        if ($room['state'] !== 'editing') {
            throw new RuntimeException('The name and link can only change while the quiz is being edited.');
        }
        $name = trim($name);
        $slug = self::normalizeSlug($slug);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Quiz name must be between 1 and 120 characters.');
        }
        if ($name === $room['name'] && $slug === $room['slug']) {
            return;
        }
        try {
            Database::transactional($this->database, function () use ($roomId, $actorId, $room, $name, $slug): void {
                $this->database->prepare(
                    'UPDATE rooms SET name = :name, slug = :slug, updated_at = :now WHERE id = :id'
                )->execute(['name' => $name, 'slug' => $slug, 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => $roomId]);
                $this->database->prepare(
                    'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
                    'VALUES (:actor, :room, :action, :now, :details)'
                )->execute([
                    'actor' => $actorId,
                    'room' => $roomId,
                    'action' => 'room.renamed',
                    'now' => gmdate('Y-m-d\TH:i:s\Z'),
                    'details' => json_encode(['old_slug' => $room['slug'], 'new_slug' => $slug], JSON_THROW_ON_ERROR),
                ]);
            });
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed: rooms.slug')) {
                throw new RuntimeException('That quiz link is already in use.', 0, $exception);
            }
            throw $exception;
        }
    }

    public function updateCollaborationLimits(int $roomId, int $actorId, int $maxQuestions, int $studentCap): void
    {
        $room = $this->findById($roomId);
        if ($room === null) {
            throw new RuntimeException('Quiz not found.');
        }
        if ((int) $room['owner_id'] !== $actorId) {
            throw new RuntimeException('Only the quiz owner may update quiz limits.');
        }
        if ($room['state'] !== 'editing') {
            throw new RuntimeException('Quiz limits cannot change after activation.');
        }
        if ($maxQuestions < 1 || $maxQuestions > 500
            || $studentCap < 1 || $studentCap > 100
        ) {
            throw new RuntimeException('Question limits are outside the allowed range.');
        }

        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM questions WHERE room_id = :room_id'
        );
        $statement->execute(['room_id' => $roomId]);
        if ((int) $statement->fetchColumn() > $maxQuestions) {
            throw new RuntimeException('The quiz already has more questions than that limit.');
        }

        Database::transactional($this->database, function () use ($maxQuestions, $studentCap, $roomId, $actorId): void {
            $update = $this->database->prepare(
                'UPDATE rooms SET max_questions = :max_questions, student_question_cap = :student_cap, ' .
                'updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                'max_questions' => $maxQuestions,
                'student_cap' => $studentCap,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'id' => $roomId,
            ]);
            $audit = $this->database->prepare(
                'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
                'VALUES (:actor_user_id, :room_id, :action, :created_at, :details_json)'
            );
            $audit->execute([
                'actor_user_id' => $actorId,
                'room_id' => $roomId,
                'action' => 'room.limits_updated',
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'details_json' => json_encode(
                    ['max_questions' => $maxQuestions, 'student_question_cap' => $studentCap],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ]);
        });
    }
}

