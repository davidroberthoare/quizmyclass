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
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://quizmyclass.example.test/auth/google/callback.php',
    ],
    'access' => [
        'allowed_domains' => ['school.example'],
        'allowed_test_emails' => ['approved.personal@gmail.com'],
    ],
    'paths' => [
        'backups' => dirname(__DIR__) . '/data/backups',
    ],
];

