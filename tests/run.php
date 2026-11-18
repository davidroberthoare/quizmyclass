<?php

declare(strict_types=1);

use QuizMyClass\Support\Backup;
use QuizMyClass\Support\Config;
use QuizMyClass\Support\Database;
use QuizMyClass\Support\Migrator;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\RichTextSanitizer;
use QuizMyClass\Auth\AccessPolicy;
use QuizMyClass\Auth\GoogleClient;

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/Migrator.php';
require_once dirname(__DIR__) . '/app/Support/Backup.php';
require_once dirname(__DIR__) . '/app/Support/UserRepository.php';
require_once dirname(__DIR__) . '/app/Support/RoomRepository.php';
require_once dirname(__DIR__) . '/app/Support/RichTextSanitizer.php';
require_once dirname(__DIR__) . '/app/Support/QuestionRepository.php';
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
    assert($applied === ['001_foundation', '002_identity_rooms', '003_questions']);
    assert($migrator->migrate() === []);
    assert($migrator->appliedCount() === 3);
};

$tests['backups are consistent and private'] = static function () use ($config): void {
    $database = Database::connect($config);
    $backup = new Backup($database, $config['paths']['backups']);
    $path = $backup->create('foundation.sqlite3');
    assert(is_file($path));
    assert((fileperms($path) & 0777) === 0600);

    $copy = new PDO('sqlite:' . $path);
    $count = (int) $copy->query('SELECT COUNT(*) FROM qmc_migrations')->fetchColumn();
    assert($count === 3);
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

$tests['rich text uses an explicit safe allowlist'] = static function (): void {
    $sanitizer = new RichTextSanitizer();
    $clean = $sanitizer->clean(
        '<script>alert(1)</script><p onclick="attack()">Hello <strong>class</strong>.'
        . '<a href="javascript:alert(1)">bad link</a>'
        . '<img src="data:text/html,bad" alt="bad">'
        . '<img src="https://images.example.test/question.png" alt="diagram" onload="attack()">'
        . '<span style="position:fixed">unsafe style</span>'
        . '<span style="color:#123456">colored</span></p>'
    );

    assert(!str_contains($clean, '<script'));
    assert(!str_contains($clean, 'alert(1)'));
    assert(!str_contains($clean, 'onclick'));
    assert(!str_contains($clean, 'javascript:'));
    assert(!str_contains($clean, 'data:text/html'));
    assert(!str_contains($clean, 'onload'));
    assert(!str_contains($clean, 'position:fixed'));
    assert(str_contains($clean, '<strong>class</strong>'));
    assert(str_contains($clean, '<img src="https://images.example.test/question.png" alt="diagram">'));
    assert(str_contains($clean, '<span style="color: #123456">colored</span>'));
    $styled = $sanitizer->clean(
        '<p><span style="font-size:18px; font-style:italic; color:#123456; position:fixed">Styled</span></p>'
    );
    assert(str_contains($styled, 'font-size: 18px'));
    assert(str_contains($styled, 'font-style: italic'));
    assert(str_contains($styled, 'color: #123456'));
    assert(!str_contains($styled, 'position:fixed'));
};

$tests['all supported question types validate'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile([
        'sub' => 'all-question-types-owner',
        'email' => 'types@school.example',
        'name' => 'Types Owner',
    ]);
    $room = (new RoomRepository($database))->create(
        (int) $owner['id'],
        'Question Types',
        'question-types',
    );
    $questions = new QuestionRepository($database);
    $base = ['prompt_html' => '<p>Choose the answer.</p>', 'points' => '1'];
    $types = [
        array_merge($base, [
            'type' => 'multiple_choice',
            'choices' => "One\nTwo",
            'correct' => [1],
        ]),
        array_merge($base, [
            'type' => 'multiple_select',
            'choices' => "One\nTwo\nThree",
            'correct' => [0, 2],
        ]),
        array_merge($base, ['type' => 'true_false', 'correct' => 'true']),
        array_merge($base, [
            'type' => 'short_text',
            'accepted_answers' => "two\n2",
        ]),
        array_merge($base, [
            'type' => 'numeric',
            'numeric_answer' => '3.14',
            'numeric_tolerance' => '0.01',
        ]),
    ];

    foreach ($types as $input) {
        $question = $questions->create((int) $room['id'], (int) $owner['id'], $input);
        assert($question['type'] === $input['type']);
        assert($question['status'] === 'draft');
    }
    $list = $questions->listForRoom((int) $room['id'], (int) $owner['id'], true);
    assert(count($list) === 5);
};

$tests['collaboration protects ownership answers and moderation'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile([
        'sub' => 'collaboration-owner',
        'email' => 'collab-owner@school.example',
        'name' => 'Collaboration Owner',
    ]);
    $student = $users->syncGoogleProfile([
        'sub' => 'collaboration-student',
        'email' => 'collab-student@school.example',
        'name' => 'Collaboration Student',
    ]);
    $other = $users->syncGoogleProfile([
        'sub' => 'collaboration-other',
        'email' => 'collab-other@school.example',
        'name' => 'Other Student',
    ]);
    $rooms = new RoomRepository($database);
    $room = $rooms->create((int) $owner['id'], 'Collaboration Rules', 'collaboration-rules');
    assert($rooms->roleForUser((int) $room['id'], (int) $other['id']) === 'student');
    $membership = $database->prepare(
        'SELECT COUNT(*) FROM room_memberships WHERE room_id = :room_id AND user_id = :user_id'
    );
    $membership->execute(['room_id' => $room['id'], 'user_id' => $other['id']]);
    assert((int) $membership->fetchColumn() === 0);

    $questions = new QuestionRepository($database);
    $studentQuestion = $questions->create((int) $room['id'], (int) $student['id'], [
        'type' => 'multiple_choice',
        'prompt_html' => '<p>Student question</p>',
        'choices' => "A\nB",
        'correct' => [0],
        'points' => '1',
    ]);
    $ownerQuestion = $questions->create((int) $room['id'], (int) $owner['id'], [
        'type' => 'true_false',
        'prompt_html' => '<p>Owner question</p>',
        'explanation_html' => '<p>Owner-only explanation</p>',
        'correct' => 'false',
        'points' => '2',
    ]);

    $studentView = $questions->listForRoom((int) $room['id'], (int) $student['id'], false);
    $studentOwn = array_values(array_filter(
        $studentView,
        static fn (array $question): bool => (int) $question['id'] === (int) $studentQuestion['id'],
    ))[0];
    $ownerOther = array_values(array_filter(
        $studentView,
        static fn (array $question): bool => (int) $question['id'] === (int) $ownerQuestion['id'],
    ))[0];
    assert($studentOwn['can_see_answer'] === true);
    assert($ownerOther['can_see_answer'] === false);
    assert($ownerOther['answer_json'] === '');
    assert($ownerOther['explanation_html'] === '');

    try {
        $questions->update((int) $ownerQuestion['id'], (int) $student['id'], [
            'type' => 'true_false',
            'prompt_html' => '<p>Hacked</p>',
            'correct' => 'true',
            'points' => '1',
        ]);
        throw new RuntimeException('Expected student edit of owner question to fail.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected student edit'));
    }

    try {
        $questions->moderate((int) $studentQuestion['id'], (int) $student['id'], 'approved');
        throw new RuntimeException('Expected student moderation to fail.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected student moderation'));
    }

    $questions->moderate((int) $studentQuestion['id'], (int) $owner['id'], 'approved');
    $approvedAudit = $database->prepare(
        "SELECT COUNT(*) FROM audit_events WHERE action = 'question.approved' AND room_id = :room_id"
    );
    $approvedAudit->execute(['room_id' => $room['id']]);
    assert((int) $approvedAudit->fetchColumn() === 1);
    $questions->moderate((int) $ownerQuestion['id'], (int) $owner['id'], 'hidden');
    assert($questions->findForEditor((int) $ownerQuestion['id'], (int) $owner['id'])['status'] === 'hidden');
    $studentView = $questions->listForRoom((int) $room['id'], (int) $student['id'], false);
    assert(count(array_filter(
        $studentView,
        static fn (array $question): bool => (int) $question['id'] === (int) $ownerQuestion['id'],
    )) === 0);

    try {
        $rooms->updateCollaborationLimits((int) $room['id'], (int) $student['id'], 20, 2);
        throw new RuntimeException('Expected student settings update to fail.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected student settings'));
    }
    $rooms->updateCollaborationLimits((int) $room['id'], (int) $owner['id'], 20, 1);

    try {
        $questions->create((int) $room['id'], (int) $student['id'], [
            'type' => 'true_false',
            'prompt_html' => '<p>Over cap</p>',
            'correct' => 'true',
            'points' => '1',
        ]);
        throw new RuntimeException('Expected student cap to be enforced.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected student cap'));
    }

    $rooms->updateCollaborationLimits((int) $room['id'], (int) $owner['id'], 3, 2);
    $otherQuestion = $questions->create((int) $room['id'], (int) $other['id'], [
        'type' => 'short_text',
        'prompt_html' => '<p>Third question</p>',
        'accepted_answers' => "third\n3rd",
        'points' => '1',
    ]);
    try {
        $questions->create((int) $room['id'], (int) $owner['id'], [
            'type' => 'numeric',
            'prompt_html' => '<p>Over room limit</p>',
            'numeric_answer' => '4',
            'numeric_tolerance' => '0',
            'points' => '1',
        ]);
        throw new RuntimeException('Expected room cap to be enforced.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected room cap'));
    }

    $questions->move((int) $otherQuestion['id'], (int) $owner['id'], 'up');
    $ordered = $questions->listForRoom((int) $room['id'], (int) $owner['id'], true);
    assert((int) $ordered[1]['id'] === (int) $otherQuestion['id']);

    $questions->reorderTo((int) $studentQuestion['id'], (int) $owner['id'], 3);
    $reordered = $questions->listForRoom((int) $room['id'], (int) $owner['id'], true);
    assert((int) $reordered[2]['id'] === (int) $studentQuestion['id']);

    try {
        $questions->reorderTo((int) $studentQuestion['id'], (int) $student['id'], 1);
        throw new RuntimeException('Expected student reorder to fail.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected student reorder'));
    }

    $database->exec("UPDATE rooms SET state = 'active' WHERE id = " . (int) $room['id']);
    try {
        $questions->update((int) $studentQuestion['id'], (int) $student['id'], [
            'type' => 'true_false',
            'prompt_html' => '<p>Too late</p>',
            'correct' => 'true',
            'points' => '1',
        ]);
        throw new RuntimeException('Expected active-room edit to fail.');
    } catch (RuntimeException $exception) {
        assert(!str_contains($exception->getMessage(), 'Expected active-room edit'));
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

