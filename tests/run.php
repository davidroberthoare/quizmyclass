<?php

declare(strict_types=1);

use QuizMyClass\Support\Backup;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\Config;
use QuizMyClass\Support\Database;
use QuizMyClass\Support\Migrator;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\RichTextSanitizer;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\Grader;
use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\ResultsRepository;
use QuizMyClass\Support\AdminRepository;
use QuizMyClass\Auth\AccessPolicy;
use QuizMyClass\Auth\GoogleClient;

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/Migrator.php';
require_once dirname(__DIR__) . '/app/Support/Backup.php';
require_once dirname(__DIR__) . '/app/Support/Http.php';
require_once dirname(__DIR__) . '/app/Support/UserRepository.php';
require_once dirname(__DIR__) . '/app/Support/RoomRepository.php';
require_once dirname(__DIR__) . '/app/Support/RichTextSanitizer.php';
require_once dirname(__DIR__) . '/app/Support/QuestionRepository.php';
require_once dirname(__DIR__) . '/app/Support/QuizSettings.php';
require_once dirname(__DIR__) . '/app/Support/Grader.php';
require_once dirname(__DIR__) . '/app/Support/QuizRepository.php';
require_once dirname(__DIR__) . '/app/Support/AttemptRepository.php';
require_once dirname(__DIR__) . '/app/Support/ResultsRepository.php';
require_once dirname(__DIR__) . '/app/Support/AdminRepository.php';
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
    assert($applied === ['001_foundation', '002_identity_rooms', '003_questions', '004_quiz_delivery', '005_user_moderation']);
    assert($migrator->migrate() === []);
    assert($migrator->appliedCount() === 5);
};

$tests['backups are consistent and private'] = static function () use ($config): void {
    $database = Database::connect($config);
    $backup = new Backup($database, $config['paths']['backups']);
    $path = $backup->create('foundation.sqlite3');
    assert(is_file($path));
    assert((fileperms($path) & 0777) === 0600);

    $copy = new PDO('sqlite:' . $path);
    $count = (int) $copy->query('SELECT COUNT(*) FROM qmc_migrations')->fetchColumn();
    assert($count === 5);
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

$tests['bulk moderation only touches matching statuses and rejects non-owners'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile(['sub' => 'bulk-owner', 'email' => 'bulk-owner@school.example', 'name' => 'Bulk Owner']);
    $student = $users->syncGoogleProfile(['sub' => 'bulk-student', 'email' => 'bulk-student@school.example', 'name' => 'Bulk Student']);
    $room = (new RoomRepository($database))->create((int) $owner['id'], 'Bulk Room', 'bulk-room');
    $questions = new QuestionRepository($database);
    $make = static fn (string $text): array => $questions->create((int) $room['id'], (int) $owner['id'], [
        'type' => 'true_false',
        'prompt_html' => '<p>' . $text . '</p>',
        'correct' => 'true',
        'points' => '1',
    ]);
    $draft = $make('draft');
    $rejected = $make('rejected');
    $hidden = $make('hidden');
    $questions->moderate((int) $rejected['id'], (int) $owner['id'], 'rejected');
    $questions->moderate((int) $hidden['id'], (int) $owner['id'], 'hidden');

    $questions->moderateAll((int) $room['id'], (int) $owner['id'], 'approved');
    $statuses = array_column($questions->listForRoom((int) $room['id'], (int) $owner['id'], true), 'status', 'id');
    assert($statuses[$draft['id']] === 'approved');
    assert($statuses[$rejected['id']] === 'rejected', 'approve all must not resurrect rejected questions');
    assert($statuses[$hidden['id']] === 'hidden', 'approve all must not unhide questions');

    $questions->moderateAll((int) $room['id'], (int) $owner['id'], 'draft');
    $statuses = array_column($questions->listForRoom((int) $room['id'], (int) $owner['id'], true), 'status', 'id');
    assert($statuses[$draft['id']] === 'draft');
    assert($statuses[$rejected['id']] === 'rejected');

    try {
        $questions->moderateAll((int) $room['id'], (int) $student['id'], 'approved');
        assert(false, 'students must not bulk moderate');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'owner'));
    }
};

$tests['multibyte choices count characters and failed transactions roll back'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile(['sub' => 'mb-owner', 'email' => 'mb-owner@school.example', 'name' => 'MB Owner']);
    $room = (new RoomRepository($database))->create((int) $owner['id'], 'Multibyte', 'multibyte-room');
    $questions = new QuestionRepository($database);
    // 200 two-byte characters is 400 bytes but only 200 characters: allowed (limit is 300 characters).
    $question = $questions->create((int) $room['id'], (int) $owner['id'], [
        'type' => 'multiple_choice',
        'prompt_html' => '<p>Unicode</p>',
        'choices' => str_repeat('é', 200) . "\nB",
        'correct' => [0],
        'points' => '1',
    ]);
    assert($question['id'] > 0);

    $before = (int) $database->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
    try {
        Database::transactional($database, static function () use ($database): void {
            $database->exec("INSERT INTO audit_events (action, created_at, details_json) VALUES ('x', 'now', '{}')");
            throw new RuntimeException('boom');
        });
        assert(false);
    } catch (RuntimeException $exception) {
        assert($exception->getMessage() === 'boom');
    }
    assert((int) $database->query('SELECT COUNT(*) FROM audit_events')->fetchColumn() === $before);
    assert(!$database->inTransaction());
};

$tests['grader scores every question type and seeded order is stable'] = static function (): void {
    $mc = ['id' => 1, 'type' => 'multiple_choice', 'choices' => ['a', 'b', 'c'], 'answer' => ['correct_index' => 1], 'points' => 2.0];
    assert(Grader::grade($mc, '1') === ['correct' => true, 'points' => 2.0]);
    assert(Grader::grade($mc, 0)['correct'] === false);
    assert(Grader::grade($mc, 99)['correct'] === false, 'out-of-range choice is unanswered');
    $ms = ['id' => 2, 'type' => 'multiple_select', 'choices' => ['a', 'b', 'c'], 'answer' => ['correct_indexes' => [0, 2]], 'points' => 1.0];
    assert(Grader::grade($ms, [2, 0])['correct'] === true);
    assert(Grader::grade($ms, [0])['correct'] === false, 'multiple select is all-or-nothing');
    $tf = ['id' => 3, 'type' => 'true_false', 'choices' => [], 'answer' => ['correct' => false], 'points' => 1.0];
    assert(Grader::grade($tf, 'false')['correct'] === true);
    assert(Grader::grade($tf, 'true')['correct'] === false);
    $text = ['id' => 4, 'type' => 'short_text', 'choices' => [], 'answer' => ['accepted_answers' => ['Mitochondria']], 'points' => 1.0];
    assert(Grader::grade($text, "  mitochondria \n")['correct'] === true);
    assert(Grader::grade($text, 'nucleus')['correct'] === false);
    $num = ['id' => 5, 'type' => 'numeric', 'choices' => [], 'answer' => ['correct' => 3.14, 'tolerance' => 0.01], 'points' => 1.0];
    assert(Grader::grade($num, '3.145')['correct'] === true);
    assert(Grader::grade($num, '3.2')['correct'] === false);
    assert(Grader::grade($num, 'abc')['correct'] === false);
    assert(Grader::order(10, 42) === Grader::order(10, 42));
    $sorted = Grader::order(10, 7);
    sort($sorted);
    assert($sorted === range(0, 9));
    $presented = Grader::present([$mc], ['shuffle_choices' => true], 5);
    assert(!isset($presented[0]['answer']) && !isset($presented[0]['explanation_html']), 'answer keys must not reach students');
};

$tests['quiz lifecycle freezes revisions, enforces attempts, and applies feedback rules'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile(['sub' => 'life-owner', 'email' => 'life-owner@school.example', 'name' => 'Life Owner']);
    $student = $users->syncGoogleProfile(['sub' => 'life-student', 'email' => 'life-student@school.example', 'name' => 'Life Student']);
    $rooms = new RoomRepository($database);
    $room = $rooms->create((int) $owner['id'], 'Lifecycle', 'lifecycle-quiz');
    $questions = new QuestionRepository($database);
    $quiz = new QuizRepository($database, 'UTC');
    $attempts = new AttemptRepository($database);

    try {
        $quiz->activate((int) $room['id'], (int) $owner['id']);
        assert(false, 'cannot activate without approved questions');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'Approve at least'));
    }

    $one = $questions->create((int) $room['id'], (int) $owner['id'], [
        'type' => 'multiple_choice', 'prompt_html' => '<p>Pick b</p>', 'choices' => "a\nb", 'correct' => [1],
        'explanation_html' => '<p>Because b</p>', 'points' => '2',
    ]);
    $questions->create((int) $room['id'], (int) $owner['id'], [
        'type' => 'true_false', 'prompt_html' => '<p>Unapproved</p>', 'correct' => 'true', 'points' => '1',
    ]);
    $questions->moderate((int) $one['id'], (int) $owner['id'], 'approved');

    try {
        $quiz->saveSettings((int) $room['id'], (int) $student['id'], []);
        assert(false, 'students must not change settings');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'owner'));
    }
    $quiz->saveSettings((int) $room['id'], (int) $owner['id'], [
        'min_approved' => '1', 'max_attempts' => '2', 'feedback_when' => 'after_close',
        'show_score' => '1', 'show_correctness' => '1', 'show_explanations' => '1',
    ]);

    $revisionId = $quiz->activate((int) $room['id'], (int) $owner['id']);
    $room = $rooms->findById((int) $room['id']);
    assert($room['state'] === 'active');
    $revision = $quiz->revision($revisionId);
    assert(count(QuizRepository::revisionQuestions($revision)) === 1, 'only approved questions are frozen');

    // Editing is locked while active, and later edits never change the frozen revision.
    try {
        $questions->update((int) $one['id'], (int) $owner['id'], [
            'type' => 'multiple_choice', 'prompt_html' => '<p>Changed</p>', 'choices' => "a\nb", 'correct' => [0], 'points' => '1',
        ]);
        assert(false, 'active quiz content must be immutable');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'activated'));
    }

    $attempt = $attempts->startOrResume($room, $revision, (int) $student['id']);
    $again = $attempts->startOrResume($room, $revision, (int) $student['id']);
    assert((int) $attempt['id'] === (int) $again['id'], 'refresh resumes the unsubmitted attempt');
    $attempts->saveAnswer((int) $attempt['id'], (int) $student['id'], (int) $one['id'], '1');
    try {
        $attempts->saveAnswer((int) $attempt['id'], (int) $owner['id'], (int) $one['id'], '0');
        assert(false, 'other users cannot write to an attempt');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'not found'));
    }
    $submitted = $attempts->submit((int) $attempt['id'], (int) $student['id']);
    assert($submitted['status'] === 'submitted' && (float) $submitted['score'] === 2.0 && (float) $submitted['max_score'] === 2.0);
    $feedback = QuizSettings::feedback(QuizRepository::revisionSettings($revision), 'active', QuizRepository::now());
    assert($feedback['score'] === false && $feedback['explanations'] === false, 'after_close hides feedback while active');

    $second = $attempts->startOrResume($room, $revision, (int) $student['id']);
    $attempts->submit((int) $second['id'], (int) $student['id']);
    try {
        $attempts->startOrResume($room, $revision, (int) $student['id']);
        assert(false, 'max attempts must be enforced');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'attempts'));
    }

    $results = new ResultsRepository($database);
    $summary = $results->summary($revisionId, (int) $owner['id']);
    assert($summary['students'] === 1 && $summary['attempts'] === 2 && round((float) $summary['average_best']) === 100.0);
    $analysis = $results->itemAnalysis($revisionId, (int) $owner['id'], QuizRepository::revisionQuestions($revision));
    assert($analysis[0]['correct'] === 1 && $analysis[0]['picks'] === [1 - 1, 1] || $analysis[0]['picks'][1] >= 1);

    $quiz->close((int) $room['id'], (int) $owner['id']);
    $closed = $rooms->findById((int) $room['id']);
    $feedback = QuizSettings::feedback(QuizRepository::revisionSettings($revision), $closed['state'], QuizRepository::now());
    assert($feedback['score'] === true && $feedback['explanations'] === true, 'after_close reveals feedback once closed');

    try {
        $results->reset((int) $room['id'], $revisionId, (int) $student['id'], null);
        assert(false, 'students must not reset results');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'owner'));
    }
    assert($results->reset((int) $room['id'], $revisionId, (int) $owner['id'], (int) $student['id']) === 2);
};

$tests['quiz rename is owner-only, editing-only, and keeps links unique'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile(['sub' => 'rn-owner', 'email' => 'rn-owner@school.example', 'name' => 'RN Owner']);
    $other = $users->syncGoogleProfile(['sub' => 'rn-other', 'email' => 'rn-other@school.example', 'name' => 'RN Other']);
    $rooms = new RoomRepository($database);
    $room = $rooms->create((int) $owner['id'], 'Old name', 'rename-old');
    $rooms->create((int) $owner['id'], 'Taken', 'rename-taken');

    $rooms->rename((int) $room['id'], (int) $owner['id'], 'New name', 'rename-new');
    $updated = $rooms->findById((int) $room['id']);
    assert($updated['name'] === 'New name' && $updated['slug'] === 'rename-new');
    assert($rooms->findBySlug('rename-old') === null);

    foreach ([
        [(int) $other['id'], 'X', 'rename-x', 'owner'],
        [(int) $owner['id'], 'X', 'rename-taken', 'already in use'],
        [(int) $owner['id'], 'X', 'Bad Slug!', 'lowercase'],
        [(int) $owner['id'], '   ', 'rename-ok', 'between 1 and 120'],
    ] as [$actor, $name, $slug, $expected]) {
        try {
            $rooms->rename((int) $room['id'], $actor, $name, $slug);
            assert(false, 'rename should have been rejected: ' . $expected);
        } catch (RuntimeException $exception) {
            assert(str_contains($exception->getMessage(), $expected));
        }
    }
    $database->exec("UPDATE rooms SET state = 'active' WHERE id = " . (int) $room['id']);
    try {
        $rooms->rename((int) $room['id'], (int) $owner['id'], 'Late', 'rename-late');
        assert(false, 'rename must be blocked once active');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'being edited'));
    }
};

$tests['admin can search, deactivate, block users, and delete with confirmation and audit'] = static function () use ($config): void {
    $database = Database::connect($config);
    $users = new UserRepository($database);
    $owner = $users->syncGoogleProfile(['sub' => 'adm-owner', 'email' => 'adm-owner@school.example', 'name' => 'Admin Owner']);
    $rooms = new RoomRepository($database);
    $room = $rooms->create((int) $owner['id'], '100% Quiz_Name', 'admin-target');
    $questions = new QuestionRepository($database);
    $q = $questions->create((int) $room['id'], (int) $owner['id'], [
        'type' => 'true_false', 'prompt_html' => '<p>x</p>', 'correct' => 'true', 'points' => '1',
    ]);
    $questions->moderate((int) $q['id'], (int) $owner['id'], 'approved');
    $quiz = new QuizRepository($database);
    $quiz->activate((int) $room['id'], (int) $owner['id']);
    $room = $rooms->findById((int) $room['id']);
    $revision = $quiz->latestRevision((int) $room['id']);
    $attempt = (new AttemptRepository($database))->startOrResume($room, $revision, (int) $owner['id']);

    $admin = new AdminRepository($database);
    assert(count($admin->searchRooms('adm-owner@', '', 1)['rows']) === 1, 'search by owner email');
    assert(count($admin->searchRooms('100%', '', 1)['rows']) === 1, 'LIKE wildcards are escaped, not interpreted');
    assert($admin->searchRooms('%', '', 1)['rows'] !== [] && count($admin->searchRooms('zzz%', '', 1)['rows']) === 0);
    assert($admin->roomDetail((int) $room['id'])['revisions'][0]['open_attempts'] == 1);

    $admin->closeRoom((int) $room['id'], 'tester');
    assert($rooms->findById((int) $room['id'])['state'] === 'closed');
    assert((new AttemptRepository($database))->find((int) $attempt['id'])['status'] === 'submitted', 'open attempts are graded on admin close');

    $admin->setBlocked((int) $owner['id'], true, 'tester');
    assert(!empty($users->findById((int) $owner['id'])['blocked_at']));
    $admin->setBlocked((int) $owner['id'], false, 'tester');
    assert(empty($users->findById((int) $owner['id'])['blocked_at']));

    try {
        $admin->deleteRoom((int) $room['id'], 'wrong-slug', 'tester');
        assert(false, 'delete needs the exact slug');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'exactly'));
    }
    $admin->deleteRoom((int) $room['id'], 'admin-target', 'tester');
    assert($rooms->findById((int) $room['id']) === null);
    $left = (int) $database->query('SELECT COUNT(*) FROM quiz_attempts WHERE room_id = ' . (int) $room['id'])->fetchColumn();
    assert($left === 0, 'attempts cascade with the quiz');
    $audit = (int) $database->query("SELECT COUNT(*) FROM audit_events WHERE action = 'admin.room_deleted' AND room_id IS NULL")->fetchColumn();
    assert($audit === 1, 'deletion is audited without a room reference');
};

$tests['backups prune oldest files; errors never leak internals; production config is strict'] = static function () use ($config, $temporary): void {
    $directory = $temporary . '/prune-backups';
    mkdir($directory, 0700, true);
    foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
        touch($directory . "/quizmyclass-$stamp.sqlite3");
    }
    touch($directory . '/manual-note.sqlite3'); // not an automatic backup: must survive
    $backup = new Backup(Database::connect($config), $directory);
    $removed = $backup->prune(2);
    assert($removed === ['quizmyclass-20260101-000000.sqlite3']);
    assert(is_file($directory . '/manual-note.sqlite3'));
    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);

    // User-facing messages pass through; database and unexpected errors are replaced.
    $logged = ini_set('error_log', '/dev/null');
    assert(Http::safeMessage(new RuntimeException('Pick a different link.')) === 'Pick a different link.');
    $leak = Http::safeMessage(new PDOException('SQLSTATE[HY000]: no such table: secret_table'));
    assert(!str_contains($leak, 'SQLSTATE') && !str_contains($leak, 'secret_table'));
    assert(!str_contains(Http::safeMessage(new TypeError('boom /var/www/app.php')), '/var/www'));
    ini_set('error_log', (string) $logged);

    // Production must not run with debug on or an HTTP origin.
    $production = ['app' => ['environment' => 'production', 'debug' => true]];
    putenv('QUIZMYCLASS_ENVIRONMENT=production');
    putenv('QUIZMYCLASS_DEBUG=1');
    putenv('QUIZMYCLASS_SESSION_SECRET=' . str_repeat('s', 40));
    putenv('QUIZMYCLASS_BASE_URL=https://quiz.example.com');
    putenv('QUIZMYCLASS_GOOGLE_REDIRECT_URI=https://quiz.example.com/auth/google/callback.php');
    putenv('QUIZMYCLASS_GOOGLE_CLIENT_ID=id');
    putenv('QUIZMYCLASS_GOOGLE_CLIENT_SECRET=secret');
    putenv('QUIZMYCLASS_CONFIG_FILE=' . $temporary . '/none.php');
    try {
        Config::load(dirname(__DIR__));
        assert(false, 'production with debug on must be rejected');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'Debug mode'));
    } finally {
        foreach (['ENVIRONMENT', 'DEBUG', 'SESSION_SECRET', 'BASE_URL', 'GOOGLE_REDIRECT_URI', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'CONFIG_FILE'] as $name) {
            putenv('QUIZMYCLASS_' . $name);
        }
    }
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

