<?php

declare(strict_types=1);

use QuizMyClass\Support\Config;
use QuizMyClass\Support\Database;

require_once __DIR__ . '/Support/Config.php';
require_once __DIR__ . '/Support/Database.php';
require_once __DIR__ . '/Support/Backup.php';
require_once __DIR__ . '/Support/Migrator.php';
require_once __DIR__ . '/Support/Http.php';
require_once __DIR__ . '/Support/Csrf.php';
require_once __DIR__ . '/Support/Endpoint.php';
require_once __DIR__ . '/Support/View.php';
require_once __DIR__ . '/Support/UserRepository.php';
require_once __DIR__ . '/Support/RoomRepository.php';
require_once __DIR__ . '/Support/RichTextSanitizer.php';
require_once __DIR__ . '/Support/QuestionRepository.php';
require_once __DIR__ . '/Support/QuizSettings.php';
require_once __DIR__ . '/Support/Grader.php';
require_once __DIR__ . '/Support/QuizRepository.php';
require_once __DIR__ . '/Support/AttemptRepository.php';
require_once __DIR__ . '/Support/ResultsRepository.php';
require_once __DIR__ . '/Support/AdminGuard.php';
require_once __DIR__ . '/Support/AdminRepository.php';
require_once __DIR__ . '/Auth/Session.php';
require_once __DIR__ . '/Auth/AccessPolicy.php';
require_once __DIR__ . '/Auth/GoogleClient.php';

$root = dirname(__DIR__);
$config = Config::load($root);

date_default_timezone_set($config['app']['timezone']);
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
ini_set('log_errors', '1');

// Last-resort handler: log the real error, show visitors a plain page (details only in debug mode).
set_exception_handler(static function (\Throwable $exception) use ($config): void {
    error_log(sprintf('QuizMyClass uncaught %s: %s at %s:%d', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo !empty($config['app']['debug'])
        ? $exception::class . ': ' . $exception->getMessage() . ' (' . $exception->getFile() . ':' . $exception->getLine() . ')'
        : 'Something went wrong. Please try again in a moment.';
    exit(1);
});

$database = Database::connect($config);

// After a code update, a forgotten `php scripts/migrate.php` should give a clear message, not a fatal error.
if (PHP_SAPI !== 'cli') {
    $pending = (new \QuizMyClass\Support\Migrator($database, $config['paths']['migrations']))->pendingCount();
    if ($pending > 0) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 300');
        echo $config['app']['debug']
            ? "The database needs $pending migration(s). Run: php scripts/migrate.php"
            : 'QuizMyClass is being updated. Please try again in a few minutes.';
        exit;
    }
}

return [
    'root' => $root,
    'config' => $config,
    'database' => $database,
];

