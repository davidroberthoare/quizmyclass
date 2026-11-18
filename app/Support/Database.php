<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

final class Database
{
    /**
     * @param array<string, mixed> $config
     */
    public static function connect(array $config): PDO
    {
        $path = $config['database']['path'] ?? '';
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Database path is not configured.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the database directory.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Database directory is not writable.');
        }

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $busyTimeout = (int) ($config['database']['busy_timeout_ms'] ?? 5000);
        if ($busyTimeout < 0 || $busyTimeout > 60000) {
            throw new RuntimeException('Database busy timeout must be between 0 and 60000 milliseconds.');
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeout);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        return $pdo;
    }
}

