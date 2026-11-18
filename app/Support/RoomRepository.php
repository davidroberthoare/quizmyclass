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
            throw new RuntimeException('Room link must use lowercase letters, numbers, and single hyphens.');
        }
        if (strlen($slug) < 3 || strlen($slug) > 60) {
            throw new RuntimeException('Room link must be between 3 and 60 characters.');
        }
        return $slug;
    }

    /** @return array<string, mixed> */
    public function create(int $ownerId, string $name, string $slug): array
    {
        $name = trim($name);
        $slug = self::normalizeSlug($slug);
        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('Room name must be between 1 and 120 characters.');
        }

        $this->database->beginTransaction();
        try {
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
            $this->database->commit();
        } catch (PDOException $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed: rooms.slug')) {
                throw new RuntimeException('That room link is already in use.', 0, $exception);
            }
            throw $exception;
        }

        $room = $this->findById($roomId);
        if ($room === null) {
            throw new RuntimeException('Room could not be loaded after creation.');
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

        $insert = $this->database->prepare(
            'INSERT INTO room_memberships (room_id, user_id, role, created_at) ' .
            'VALUES (:room_id, :user_id, :role, :created_at)'
        );
        $insert->execute([
            'room_id' => $roomId,
            'user_id' => $userId,
            'role' => 'student',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return 'student';
    }
}

