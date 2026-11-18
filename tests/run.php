<?php

declare(strict_types=1);

use QuizMyClass\Support\Backup;
use QuizMyClass\Support\Config;
use QuizMyClass\Support\Database;
use QuizMyClass\Support\Migrator;

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/Migrator.php';
require_once dirname(__DIR__) . '/app/Support/Backup.php';

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/quizmyclass-tests-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);

$config = [
    'app' => [
        'environment' => 'test',
        'debug' => true,
        'session_secret' => str_repeat('t', 32),
        'timezone' => 'UTC',
        'base_url' => '',
    ],
    'database' => [
        'path' => $temporary . '/test.sqlite3',
        'busy_timeout_ms' => 5000,
    ],
    'paths' => [
        'migrations' => $root . '/app/Migrations',
        'backups' => $temporary . '/backups',
    ],
];

$tests = [];

$tests['config rejects short secrets'] = static function () use ($root): void {
    $configFile = tempnam(sys_get_temp_dir(), 'qmc-config-');
    file_put_contents($configFile, "<?php return ['app' => ['session_secret' => 'short']];\n");
    putenv('QUIZMYCLASS_CONFIG_FILE=' . $configFile);
    try {
        Config::load($root);
        throw new RuntimeException('Expected short secret to be rejected.');
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), 'Expected short')) {
            throw $exception;
        }
    } finally {
        putenv('QUIZMYCLASS_CONFIG_FILE');
        unlink($configFile);
    }
};

$tests['sqlite enables required pragmas'] = static function () use ($config): void {
    $database = Database::connect($config);
    assert((int) $database->query('PRAGMA foreign_keys')->fetchColumn() === 1);
    assert(strtolower((string) $database->query('PRAGMA journal_mode')->fetchColumn()) === 'wal');
};

$tests['migrations are repeatable'] = static function () use ($config): void {
    $database = Database::connect($config);
    $migrator = new Migrator($database, $config['paths']['migrations']);
    $applied = $migrator->migrate();
    assert($applied === ['001_foundation']);
    assert($migrator->migrate() === []);
    assert($migrator->appliedCount() === 1);
};

$tests['backups are consistent and private'] = static function () use ($config): void {
    $database = Database::connect($config);
    $backup = new Backup($database, $config['paths']['backups']);
    $path = $backup->create('foundation.sqlite3');
    assert(is_file($path));
    assert((fileperms($path) & 0777) === 0600);

    $copy = new PDO('sqlite:' . $path);
    $count = (int) $copy->query('SELECT COUNT(*) FROM qmc_migrations')->fetchColumn();
    assert($count === 1);
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
        $passed++;
    } catch (Throwable $exception) {
        echo "FAIL $name: " . $exception->getMessage() . "\n";
    }
}

echo "$passed/" . count($tests) . " tests passed\n";

$cleanupFiles = [
    $temporary . '/backups/foundation.sqlite3',
    $temporary . '/test.sqlite3-shm',
    $temporary . '/test.sqlite3-wal',
    $temporary . '/test.sqlite3',
];
foreach ($cleanupFiles as $file) {
    if (file_exists($file)) {
        unlink($file);
    }
}
if (is_dir($temporary . '/backups')) {
    rmdir($temporary . '/backups');
}
if (is_dir($temporary)) {
    rmdir($temporary);
}

exit($passed === count($tests) ? 0 : 1);

