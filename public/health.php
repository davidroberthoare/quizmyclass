<?php

declare(strict_types=1);

use QuizMyClass\Support\Http;
use QuizMyClass\Support\Migrator;

try {
    $runtime = require dirname(__DIR__) . '/app/bootstrap.php';
    Http::securityHeaders();

    $database = $runtime['database'];
    $foreignKeys = (int) $database->query('PRAGMA foreign_keys')->fetchColumn();
    $journalMode = strtolower((string) $database->query('PRAGMA journal_mode')->fetchColumn());
    if ($foreignKeys !== 1 || $journalMode !== 'wal') {
        throw new RuntimeException('Database health invariants are not active.');
    }

    $migrator = new Migrator(
        $database,
        $runtime['config']['paths']['migrations'],
    );
    Http::json([
        'status' => 'ok',
        'application' => $runtime['config']['app']['name'],
        'database' => [
            'connected' => true,
            'journal_mode' => $journalMode,
            'foreign_keys' => true,
            'migrations_applied' => $migrator->appliedCount(),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('QuizMyClass health check failed: ' . $exception->getMessage());
    Http::securityHeaders();
    Http::json(['status' => 'unhealthy'], 503);
}

