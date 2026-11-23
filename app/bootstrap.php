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
require_once __DIR__ . '/Auth/Session.php';
require_once __DIR__ . '/Auth/AccessPolicy.php';
require_once __DIR__ . '/Auth/GoogleClient.php';

$root = dirname(__DIR__);
$config = Config::load($root);

date_default_timezone_set($config['app']['timezone']);
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');

return [
    'root' => $root,
    'config' => $config,
    'database' => Database::connect($config),
];

