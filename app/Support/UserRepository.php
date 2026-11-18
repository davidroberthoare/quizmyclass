<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function syncGoogleProfile(array $profile): array
    {
        $subject = trim((string) ($profile['sub'] ?? ''));
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $name = trim((string) ($profile['name'] ?? ''));

        if ($subject === '' || strlen($subject) > 255) {
            throw new RuntimeException('Google did not provide a valid stable subject.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 320) {
            throw new RuntimeException('Google did not provide a valid email address.');
        }
        if ($name === '' || strlen($name) > 200) {
            $name = $email;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT id FROM users WHERE google_subject = :subject'
            );
            $statement->execute(['subject' => $subject]);
            $id = $statement->fetchColumn();

            if ($id === false) {
                $insert = $this->database->prepare(
                    'INSERT INTO users (google_subject, email, display_name, created_at, updated_at, last_login_at) ' .
                    'VALUES (:subject, :email, :name, :created_at, :updated_at, :last_login_at)'
                );
                $insert->execute([
                    'subject' => $subject,
                    'email' => $email,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_login_at' => $now,
                ]);
                $id = (int) $this->database->lastInsertId();
            } else {
                $update = $this->database->prepare(
                    'UPDATE users SET email = :email, display_name = :name, updated_at = :updated_at, ' .
                    'last_login_at = :last_login_at WHERE id = :id'
                );
                $update->execute([
                    'email' => $email,
                    'name' => $name,
                    'updated_at' => $now,
                    'last_login_at' => $now,
                    'id' => $id,
                ]);
                $id = (int) $id;
            }
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        $user = $this->findById($id);
        if ($user === null) {
            throw new RuntimeException('User profile could not be loaded after sign-in.');
        }
        return $user;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, google_subject, email, display_name, created_at, updated_at, last_login_at ' .
            'FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }
}

