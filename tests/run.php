<?php

declare(strict_types=1);

use QuizMyClass\Support\Backup;
use QuizMyClass\Support\Config;
use QuizMyClass\Support\Database;
use QuizMyClass\Support\Migrator;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Auth\AccessPolicy;
use QuizMyClass\Auth\GoogleClient;

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/Migrator.php';
require_once dirname(__DIR__) . '/app/Support/Backup.php';
require_once dirname(__DIR__) . '/app/Support/UserRepository.php';
require_once dirname(__DIR__) . '/app/Support/RoomRepository.php';
require_once dirname(__DIR__) . '/app/Auth/AccessPolicy.php';
require_once dirname(__DIR__) . '/app/Auth/GoogleClient.php';

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
    'google' => [
        'client_id' => 'test-client.apps.googleusercontent.com',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://127.0.0.1:8080/auth/google/callback.php',
    ],
    'access' => [
        'allowed_domains' => ['school.example'],
        'allowed_test_emails' => ['approved@example.com'],
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
    assert($applied === ['001_foundation', '002_identity_rooms']);
    assert($migrator->migrate() === []);
    assert($migrator->appliedCount() === 2);
};

$tests['backups are consistent and private'] = static function () use ($config): void {
    $database = Database::connect($config);
    $backup = new Backup($database, $config['paths']['backups']);
    $path = $backup->create('foundation.sqlite3');
    assert(is_file($path));
    assert((fileperms($path) & 0777) === 0600);

    $copy = new PDO('sqlite:' . $path);
    $count = (int) $copy->query('SELECT COUNT(*) FROM qmc_migrations')->fetchColumn();
    assert($count === 2);
};

$tests['google profile identity follows stable subject'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $first = $users->syncGoogleProfile([
        'sub' => 'stable-google-subject',
        'email' => 'teacher@school.example',
        'name' => 'Teacher One',
    ]);
    $updated = $users->syncGoogleProfile([
        'sub' => 'stable-google-subject',
        'email' => 'renamed@school.example',
        'name' => 'Teacher Two',
    ]);

    assert($first['id'] === $updated['id']);
    assert($updated['email'] === 'renamed@school.example');
    assert((int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
};

$tests['access policy requires verified approved email'] = static function () use ($config): void {
    $policy = new AccessPolicy($config);
    $policy->assertAllowed([
        'email' => 'student@school.example',
        'email_verified' => true,
    ]);
    $policy->assertAllowed([
        'email' => 'approved@example.com',
        'email_verified' => true,
    ]);

    try {
        $policy->assertAllowed([
            'email' => 'outsider@example.net',
            'email_verified' => true,
        ]);
        throw new RuntimeException('Expected unapproved email to be rejected.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected unapproved'));
    }

    try {
        $policy->assertAllowed([
            'email' => 'student@school.example',
            'email_verified' => false,
        ]);
        throw new RuntimeException('Expected unverified email to be rejected.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected unverified'));
    }
};

$tests['oauth request uses least scope and PKCE'] = static function () use ($config): void {
    $client = new GoogleClient($config);
    $url = $client->authorizeUrl('state-value', str_repeat('v', 43));
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    assert($query['scope'] === 'openid email profile');
    assert($query['code_challenge_method'] === 'S256');
    assert($query['state'] === 'state-value');
    assert(isset($query['code_challenge']));
    assert(!isset($query['access_type']) || $query['access_type'] === 'online');
};

$tests['rooms enforce owner and student roles'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile([
        'sub' => 'room-owner',
        'email' => 'owner@school.example',
        'name' => 'Owner',
    ]);
    $student = $users->syncGoogleProfile([
        'sub' => 'room-student',
        'email' => 'student@school.example',
        'name' => 'Student',
    ]);
    $rooms = new RoomRepository($database);
    $room = $rooms->create((int) $owner['id'], 'Biology 2B', 'biology-2b');

    assert($room['owner_id'] === $owner['id']);
    assert($rooms->roleForUser((int) $room['id'], (int) $owner['id']) === 'owner');
    assert($rooms->roleForUser((int) $room['id'], (int) $student['id']) === 'student');
    assert($rooms->findBySlug('biology-2b')['id'] === $room['id']);
    assert(count($rooms->forOwner((int) $owner['id'])) === 1);

    try {
        $rooms->create((int) $owner['id'], 'Duplicate', 'biology-2b');
        throw new RuntimeException('Expected duplicate slug to be rejected.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected duplicate'));
    }

    try {
        RoomRepository::normalizeSlug('bad--slug');
        throw new RuntimeException('Expected invalid slug to be rejected.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected invalid'));
    }
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

