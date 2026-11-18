<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => 'QuizMyClass',
        'environment' => 'development',
        'debug' => false,
        'session_secret' => '',
        'timezone' => 'UTC',
        'base_url' => '',
    ],
    'database' => [
        'path' => $root . '/data/quizmyclass.sqlite3',
        'busy_timeout_ms' => 5000,
    ],
    'paths' => [
        'migrations' => $root . '/app/Migrations',
        'backups' => $root . '/data/backups',
    ],
];

