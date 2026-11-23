<?php

declare(strict_types=1);

use QuizMyClass\Support\Config;
use QuizMyClass\Support\Migrator;

// Preflight for a deployment:  php scripts/check-deployment.php
// Exits non-zero if anything that must be fixed before going live is wrong.
$root = dirname(__DIR__);
require_once $root . '/app/Support/Config.php';
require_once $root . '/app/Support/Database.php';
require_once $root . '/app/Support/Migrator.php';

$failures = 0;
$warnings = 0;
$report = static function (string $level, string $message) use (&$failures, &$warnings): void {
    echo str_pad($level, 5) . ' ' . $message . "\n";
    if ($level === 'FAIL') {
        $failures++;
    } elseif ($level === 'WARN') {
        $warnings++;
    }
};

// PHP and extensions
version_compare(PHP_VERSION, '8.2.0', '>=')
    ? $report('OK', 'PHP ' . PHP_VERSION)
    : $report('FAIL', 'PHP 8.2 or newer is required (found ' . PHP_VERSION . ').');
foreach (['pdo_sqlite', 'sqlite3', 'curl', 'openssl', 'mbstring', 'dom', 'libxml'] as $extension) {
    extension_loaded($extension) ? $report('OK', "extension $extension") : $report('FAIL', "missing PHP extension: $extension");
}

// Configuration
try {
    $config = Config::load($root);
    $report('OK', 'configuration loads and validates (' . $config['app']['environment'] . ')');
} catch (Throwable $exception) {
    $report('FAIL', 'configuration: ' . $exception->getMessage());
    exit(1);
}
$production = $config['app']['environment'] === 'production';
$production ? $report('OK', 'environment is production') : $report('WARN', 'environment is "' . $config['app']['environment'] . '", not production');
empty($config['app']['debug']) ? $report('OK', 'debug is off') : $report($production ? 'FAIL' : 'WARN', 'debug is on');
$localConfig = $root . '/config/local.php';
if (is_file($localConfig)) {
    (fileperms($localConfig) & 0077) === 0
        ? $report('OK', 'config/local.php is private to its owner')
        : $report('WARN', 'config/local.php is readable by other users; run: chmod 600 config/local.php');
}

// Secrets and data must not be inside the web root
$publicRoot = realpath($root . '/public');
foreach ([
    'database' => dirname((string) $config['database']['path']),
    'backups' => (string) $config['paths']['backups'],
    'sessions' => (string) ($config['paths']['sessions'] ?? ''),
    'config' => $root . '/config',
] as $label => $directory) {
    if ($directory === '') {
        continue;
    }
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    $real = realpath($directory);
    if ($real === false) {
        $report('FAIL', "$label directory does not exist and could not be created: $directory");
    } elseif ($publicRoot !== false && str_starts_with($real . '/', $publicRoot . '/')) {
        $report('FAIL', "$label directory is inside the public web root: $real");
    } elseif ($label !== 'config' && !is_writable($real)) {
        $report('FAIL', "$label directory is not writable by PHP: $real");
    } else {
        $report('OK', "$label directory is outside the web root" . ($label === 'config' ? '' : ' and writable'));
    }
}

// Database and migrations
try {
    $database = \QuizMyClass\Support\Database::connect($config);
    $migrator = new Migrator($database, $config['paths']['migrations']);
    $pending = $migrator->pendingCount();
    $pending === 0
        ? $report('OK', 'database is up to date (' . $migrator->appliedCount() . ' migrations)')
        : $report('FAIL', "$pending migration(s) pending; run: php scripts/migrate.php");
    $report(strtolower((string) $database->query('PRAGMA journal_mode')->fetchColumn()) === 'wal' ? 'OK' : 'FAIL', 'SQLite WAL mode');
} catch (Throwable $exception) {
    $report('FAIL', 'database: ' . $exception->getMessage());
}

// Google sign-in and access control
$google = $config['google'];
($google['client_id'] !== '' && $google['client_secret'] !== '')
    ? $report('OK', 'Google credentials are set')
    : $report($production ? 'FAIL' : 'WARN', 'Google credentials are missing');
$access = $config['access'];
if ($access['allowed_domains'] === [] && $access['allowed_test_emails'] === []) {
    $report('FAIL', 'no account allowlist: nobody can sign in');
} elseif ($production && $access['allowed_domains'] === []) {
    $report('WARN', 'production should use access.allowed_domains (your Workspace domain), not individual test emails');
} else {
    $report('OK', 'account allowlist is set');
}

// Admin console
$htaccess = $root . '/public/admin/.htaccess';
$contents = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';
if ($contents === '') {
    $report('FAIL', 'public/admin/.htaccess is missing');
} elseif (str_contains($contents, '/REPLACE/WITH')) {
    $report($production ? 'FAIL' : 'WARN', 'admin .htaccess still has the placeholder AuthUserFile path (the console stays closed until you set it)');
} elseif (preg_match('/^\s*AuthUserFile\s+"?([^"\r\n]+)"?/m', $contents, $matches) === 1) {
    $file = trim($matches[1]);
    if (!is_file($file)) {
        $report('FAIL', "admin AuthUserFile does not exist: $file");
    } elseif ($publicRoot !== false && str_starts_with((string) realpath($file), $publicRoot . '/')) {
        $report('FAIL', 'admin password file is inside the public web root');
    } else {
        $report('OK', 'admin password file exists outside the web root');
    }
}
empty($config['admin']['dev_password_hash'] ?? '')
    ? $report('OK', 'no development admin password configured')
    : $report($production ? 'WARN' : 'OK', 'admin.dev_password_hash is set (ignored outside development; remove it from production config)');

// HTTPS
$base = (string) $config['app']['base_url'];
str_starts_with(strtolower($base), 'https://')
    ? $report('OK', 'base_url uses HTTPS (session cookies are Secure)')
    : $report($production ? 'FAIL' : 'WARN', 'base_url is not HTTPS');

echo "\n" . ($failures === 0 ? 'Ready' : "$failures problem(s) to fix") . ($warnings > 0 ? ", $warnings warning(s)" : '') . ".\n";
exit($failures === 0 ? 0 : 1);
