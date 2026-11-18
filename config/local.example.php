<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'development',
        'debug' => true,
        'session_secret' => 'replace-with-at-least-32-random-characters',
        'timezone' => 'UTC',
        'base_url' => 'https://quizmyclass.example.test',
    ],
    'database' => [
        'path' => dirname(__DIR__) . '/data/quizmyclass.sqlite3',
    ],
    'paths' => [
        'backups' => dirname(__DIR__) . '/data/backups',
    ],
];

