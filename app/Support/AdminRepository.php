<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

/** Deployment-wide reads and moderation for the super-admin console. Every write is audited. */
final class AdminRepository
{
    public const PAGE_SIZE = 25;

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $one = fn (string $sql): int => (int) $this->database->query($sql)->fetchColumn();
        return [
            'users' => $one('SELECT COUNT(*) FROM users'),
            'blocked' => $one('SELECT COUNT(*) FROM users WHERE blocked_at IS NOT NULL'),
            'quizzes' => $one('SELECT COUNT(*) FROM rooms'),
            'editing' => $one("SELECT COUNT(*) FROM rooms WHERE state = 'editing'"),
            'active' => $one("SELECT COUNT(*) FROM rooms WHERE state = 'active'"),
            'closed' => $one("SELECT COUNT(*) FROM rooms WHERE state = 'closed'"),
            'questions' => $one('SELECT COUNT(*) FROM questions'),
            'attempts' => $one("SELECT COUNT(*) FROM quiz_attempts WHERE status != 'in_progress'"),
            'in_progress' => $one("SELECT COUNT(*) FROM quiz_attempts WHERE status = 'in_progress'"),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function searchRooms(string $query, string $state, int $page): array
    {
        $where = [];
        $params = [];
        if ($query !== '') {
            $where[] = "(r.name LIKE :q ESCAPE '\\' OR r.slug LIKE :q ESCAPE '\\' OR u.email LIKE :q ESCAPE '\\' OR u.display_name LIKE :q ESCAPE '\\')";
            $params['q'] = '%' . self::like($query) . '%';
        }
        if (in_array($state, ['editing', 'active', 'closed'], true)) {
            $where[] = 'r.state = :state';
            $params['state'] = $state;
        }
        $sql = 'SELECT r.id, r.slug, r.name, r.state, r.created_at, r.updated_at, ' .
            'u.display_name AS owner_name, u.email AS owner_email, ' .
            '(SELECT COUNT(*) FROM questions q WHERE q.room_id = r.id) AS questions, ' .
            '(SELECT COUNT(*) FROM quiz_attempts a WHERE a.room_id = r.id) AS attempts ' .
            'FROM rooms r JOIN users u ON u.id = r.owner_id ' .
            ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ') .
            'ORDER BY r.updated_at DESC, r.id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->database->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', self::PAGE_SIZE + 1, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $more = count($rows) > self::PAGE_SIZE;
        return ['rows' => array_slice($rows, 0, self::PAGE_SIZE), 'more' => $more];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function roomDetail(int $roomId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT r.*, u.display_name AS owner_name, u.email AS owner_email FROM rooms r ' .
            'JOIN users u ON u.id = r.owner_id WHERE r.id = :id'
        );
        $statement->execute(['id' => $roomId]);
        $room = $statement->fetch();
        if (!is_array($room)) {
            return null;
        }
        $fetch = function (string $sql) use ($roomId): array {
            $s = $this->database->prepare($sql);
            $s->execute(['id' => $roomId]);
            return $s->fetchAll();
        };
        $room['members'] = (int) ($fetch('SELECT COUNT(*) AS n FROM room_memberships WHERE room_id = :id')[0]['n'] ?? 0);
        $room['question_statuses'] = array_column(
            $fetch('SELECT status, COUNT(*) AS n FROM questions WHERE room_id = :id GROUP BY status'),
            'n',
            'status',
        );
        $room['questions'] = $fetch(
            'SELECT q.id, q.type, q.prompt_html, q.status, q.points, u.display_name AS author ' .
            'FROM questions q JOIN users u ON u.id = q.author_id WHERE q.room_id = :id ORDER BY q.position, q.id'
        );
        $room['revisions'] = $fetch(
            'SELECT r.id, r.revision_number, r.created_at, ' .
            "SUM(CASE WHEN a.status != 'in_progress' THEN 1 ELSE 0 END) AS submitted, " .
            "SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) AS open_attempts, " .
            "COUNT(DISTINCT a.user_id) AS students " .
            'FROM quiz_revisions r LEFT JOIN quiz_attempts a ON a.revision_id = r.id ' .
            'WHERE r.room_id = :id GROUP BY r.id ORDER BY r.revision_number DESC'
        );
        $room['audit'] = $fetch(
            'SELECT e.action, e.created_at, e.details_json, u.display_name AS actor FROM audit_events e ' .
            'LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.room_id = :id ORDER BY e.id DESC LIMIT 15'
        );
        return $room;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function searchUsers(string $query, int $page): array
    {
        $params = [];
        $where = '';
        if ($query !== '') {
            $where = "WHERE u.email LIKE :q ESCAPE '\\' OR u.display_name LIKE :q ESCAPE '\\' ";
            $params['q'] = '%' . self::like($query) . '%';
        }
        $statement = $this->database->prepare(
            'SELECT u.id, u.display_name, u.email, u.created_at, u.last_login_at, u.blocked_at, ' .
            '(SELECT COUNT(*) FROM rooms r WHERE r.owner_id = u.id) AS quizzes, ' .
            '(SELECT COUNT(*) FROM questions q WHERE q.author_id = u.id) AS questions, ' .
            '(SELECT COUNT(*) FROM quiz_attempts a WHERE a.user_id = u.id) AS attempts ' .
            'FROM users u ' . $where . 'ORDER BY u.last_login_at DESC, u.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', self::PAGE_SIZE + 1, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        return ['rows' => array_slice($rows, 0, self::PAGE_SIZE), 'more' => count($rows) > self::PAGE_SIZE];
    }

    /** Force an active quiz closed. In-progress attempts are graded and submitted as they stand. */
    public function closeRoom(int $roomId, string $admin): void
    {
        $room = $this->room($roomId);
        if ($room['state'] !== 'active') {
            throw new RuntimeException('Only an active quiz can be deactivated.');
        }
        Database::transactional($this->database, function () use ($roomId, $admin): void {
            (new AttemptRepository($this->database))->finalizeAllOpen($roomId);
            $this->database
                ->prepare("UPDATE rooms SET state = 'closed', updated_at = :now WHERE id = :id")
                ->execute(['now' => QuizRepository::now(), 'id' => $roomId]);
            $this->audit($roomId, 'admin.room_closed', ['admin' => $admin]);
        });
    }

    /** Permanently delete a quiz with its questions, revisions and attempts. Requires the exact slug. */
    public function deleteRoom(int $roomId, string $confirmSlug, string $admin): void
    {
        $room = $this->room($roomId);
        if (!hash_equals((string) $room['slug'], $confirmSlug)) {
            throw new RuntimeException('Type the quiz link exactly to confirm deletion.');
        }
        Database::transactional($this->database, function () use ($room, $admin): void {
            // The room's own audit rows cascade away, so record the deletion without a room reference.
            $this->audit(null, 'admin.room_deleted', [
                'admin' => $admin,
                'slug' => $room['slug'],
                'name' => $room['name'],
                'owner_id' => (int) $room['owner_id'],
            ]);
            $this->database->prepare('DELETE FROM rooms WHERE id = :id')->execute(['id' => $room['id']]);
        });
    }

    public function setBlocked(int $userId, bool $blocked, string $admin): void
    {
        $statement = $this->database->prepare('SELECT id, email FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('User not found.');
        }
        Database::transactional($this->database, function () use ($userId, $blocked, $admin): void {
            $this->database
                ->prepare('UPDATE users SET blocked_at = :at, updated_at = :now WHERE id = :id')
                ->execute(['at' => $blocked ? QuizRepository::now() : null, 'now' => QuizRepository::now(), 'id' => $userId]);
            $this->audit(null, $blocked ? 'admin.user_blocked' : 'admin.user_unblocked', ['admin' => $admin, 'user_id' => $userId]);
        });
    }

    /** @return array<string, mixed> */
    private function room(int $roomId): array
    {
        $statement = $this->database->prepare('SELECT * FROM rooms WHERE id = :id');
        $statement->execute(['id' => $roomId]);
        $room = $statement->fetch();
        if (!is_array($room)) {
            throw new RuntimeException('Quiz not found.');
        }
        return $room;
    }

    /** @param array<string, mixed> $details */
    private function audit(?int $roomId, string $action, array $details): void
    {
        $this->database->prepare(
            'INSERT INTO audit_events (actor_user_id, room_id, action, created_at, details_json) ' .
            'VALUES (NULL, :room, :action, :now, :details)'
        )->execute([
            'room' => $roomId,
            'action' => $action,
            'now' => QuizRepository::now(),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    private static function like(string $text): string
    {
        return addcslashes($text, '\\%_');
    }
}
