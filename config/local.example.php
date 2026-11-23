<?php

// Copy to config/local.php (git-ignored, chmod 600). Never commit real secrets.
// For production, replace every value below; see docs/DEPLOYMENT.md.

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'production',     // development | production
        'debug' => false,                  // must be false in production
        'session_secret' => 'replace-with-at-least-32-random-characters',
        'timezone' => 'America/Toronto',   // used for quiz open/close times
        'base_url' => 'https://quiz.example.org',
    ],
    'database' => [
        'path' => dirname(__DIR__) . '/data/quizmyclass.sqlite3',
    ],
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://quiz.example.org/auth/google/callback.php',
    ],
    'access' => [
        // Production: your Google Workspace domain(s). Everyone signing in must match.
        'allowed_domains' => ['school.example'],
        // Development: exact personal accounts approved for testing.
        'allowed_test_emails' => [],
    ],
    'paths' => [
        'backups' => dirname(__DIR__) . '/data/backups',
        'sessions' => dirname(__DIR__) . '/data/sessions',
    ],
];
