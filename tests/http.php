<?php

declare(strict_types=1);

/**
 * End-to-end permission tests over real HTTP against PHP's built-in server and a throwaway database.
 *   php tests/http.php
 * Covers the boundaries the unit tests cannot: sessions, CSRF, and what each role can see or change.
 */

use QuizMyClass\Support\Config;
use QuizMyClass\Support\Migrator;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/quizmyclass-http-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
file_put_contents($temp . '/config.php', "<?php return " . var_export([
    'app' => ['environment' => 'test', 'debug' => false, 'session_secret' => str_repeat('h', 40), 'timezone' => 'UTC', 'base_url' => ''],
    'database' => ['path' => $temp . '/test.sqlite3'],
    'paths' => ['backups' => $temp . '/backups', 'sessions' => $temp . '/sessions'],
], true) . ";\n");
putenv('QUIZMYCLASS_CONFIG_FILE=' . $temp . '/config.php');

$runtime = require $root . '/app/bootstrap.php';
(new Migrator($runtime['database'], $runtime['config']['paths']['migrations']))->migrate();
$db = $runtime['database'];

// ---- Seed: a teacher with a draft + an approved question with a secret explanation ----
$users = new UserRepository($db);
$teacher = $users->syncGoogleProfile(['sub' => 'teacher', 'email' => 'teacher@test.example', 'name' => 'Teacher']);
$rooms = new RoomRepository($db);
$room = $rooms->create((int) $teacher['id'], 'HTTP Quiz', 'http-quiz');
$questions = new QuestionRepository($db);
$approved = $questions->create((int) $room['id'], (int) $teacher['id'], [
    'type' => 'short_text', 'prompt_html' => '<p>Capital of France?</p>', 'accepted_answers' => 'Paris',
    'explanation_html' => '<p>SECRET-EXPLANATION</p>', 'points' => '1',
]);
$draft = $questions->create((int) $room['id'], (int) $teacher['id'], [
    'type' => 'true_false', 'prompt_html' => '<p>DRAFT-PROMPT-TEXT</p>', 'correct' => 'true', 'points' => '1',
]);
$questions->moderate((int) $approved['id'], (int) $teacher['id'], 'approved');

// ---- Server ----
$port = random_int(20000, 40000);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/tests/http_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']],
    $pipes,
    $root,
    ['QUIZMYCLASS_CONFIG_FILE' => $temp . '/config.php', 'PATH' => (string) getenv('PATH')],
);
register_shutdown_function(static function () use ($server, $temp): void {
    proc_terminate($server);
    foreach (glob($temp . '/{*,*/*}', GLOB_BRACE) ?: [] as $file) {
        is_file($file) && unlink($file);
    }
});
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port);
    if ($probe) {
        fclose($probe);
        break;
    }
    usleep(100000);
}

/** @return array{status: int, body: string, location: string, headers: string} */
function request(string $method, string $path, string $jar, array $form = []): array
{
    global $port;
    $handle = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw = (string) curl_exec($handle);
    $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($raw, 0, $size),
        'body' => substr($raw, $size),
        'location' => (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL),
    ];
    curl_close($handle);
    return $result;
}

function login(string $sub): string
{
    $jar = tempnam(sys_get_temp_dir(), 'qmcjar');
    request('GET', '/__test/login?sub=' . $sub, $jar);
    return $jar;
}

function token(string $jar): string
{
    $page = request('GET', '/', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $matches);
    return $matches[1] ?? '';
}

$value = static fn (string $sql, array $params = []) => (function () use ($db, $sql, $params) {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
})();

$teacherJar = login('teacher');
$studentJar = login('student');
$otherJar = login('other');
$anonJar = tempnam(sys_get_temp_dir(), 'qmcjar');
$tTeacher = token($teacherJar);
$tStudent = token($studentJar);
$tOther = token($otherJar);

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    $failures += $ok ? 0 : 1;
};
$qid = (int) $approved['id'];
$rid = (int) $room['id'];

// ---- Anonymous visitors ----
$check('anonymous cannot create a quiz', (function () use ($anonJar, $value): bool {
    request('POST', '/rooms/create.php', $anonJar, ['name' => 'X', 'slug' => 'anon-quiz', 'csrf_token' => 'x']);
    return (int) $value("SELECT COUNT(*) FROM rooms WHERE slug = 'anon-quiz'") === 0;
})());
$check('results page redirects anonymous visitors', request('GET', '/results.php?room=http-quiz', $anonJar)['status'] === 303);
$check('admin console is closed without Apache auth', request('GET', '/admin/', $anonJar)['status'] === 403);
$check('admin cannot be reached with a forged REMOTE_USER header', (function () use ($port): bool {
    $handle = curl_init("http://127.0.0.1:$port/admin/");
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Remote-User: admin', 'X-Remote-User: admin']]);
    curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return $status === 403;
})());
$home = request('GET', '/', $anonJar);
$check('security headers are sent', str_contains($home['headers'], 'Content-Security-Policy')
    && str_contains($home['headers'], 'X-Frame-Options: DENY') && str_contains($home['headers'], 'nosniff'));

// ---- CSRF ----
$check('owner POST without a CSRF token changes nothing', (function () use ($teacherJar, $value, $qid): bool {
    request('POST', '/questions/moderate.php', $teacherJar, ['room_slug' => 'http-quiz', 'question_id' => $qid, 'status' => 'rejected']);
    return $value('SELECT status FROM questions WHERE id = ?', [$qid]) === 'approved';
})());
$check('owner POST with another session\'s token is rejected', (function () use ($teacherJar, $tStudent, $value, $qid): bool {
    request('POST', '/questions/moderate.php', $teacherJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'question_id' => $qid, 'status' => 'rejected']);
    return $value('SELECT status FROM questions WHERE id = ?', [$qid]) === 'approved';
})());

// ---- Students cannot act as the teacher (valid CSRF, wrong role) ----
$check('student cannot moderate', (function () use ($studentJar, $tStudent, $value, $qid): bool {
    request('POST', '/questions/moderate.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'question_id' => $qid, 'status' => 'rejected']);
    return $value('SELECT status FROM questions WHERE id = ?', [$qid]) === 'approved';
})());
$check('student cannot bulk-moderate', (function () use ($studentJar, $tStudent, $value, $rid): bool {
    request('POST', '/questions/moderate.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'action' => 'approve_all']);
    return $value("SELECT status FROM questions WHERE room_id = ? AND status = 'draft'", [$rid]) === 'draft';
})());
$check('student cannot reorder', (function () use ($studentJar, $tStudent, $value, $qid): bool {
    $before = $value('SELECT position FROM questions WHERE id = ?', [$qid]);
    request('POST', '/questions/move.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'question_id' => $qid, 'direction' => 'down']);
    return $value('SELECT position FROM questions WHERE id = ?', [$qid]) === $before;
})());
$check('student cannot edit someone else\'s question', (function () use ($studentJar, $tStudent, $value, $qid): bool {
    request('POST', '/questions/save.php', $studentJar, [
        'csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'question_id' => $qid, 'type' => 'short_text',
        'prompt_html' => '<p>HACKED</p>', 'accepted_answers' => 'x', 'points' => '1',
    ]);
    return !str_contains((string) $value('SELECT prompt_html FROM questions WHERE id = ?', [$qid]), 'HACKED');
})());
$check('student cannot change settings or activate', (function () use ($studentJar, $tStudent, $value, $rid): bool {
    request('POST', '/rooms/settings.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'name' => 'Pwned', 'slug' => 'pwned', 'max_questions' => 5, 'student_question_cap' => 1]);
    request('POST', '/rooms/lifecycle.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'action' => 'activate']);
    return $value('SELECT name FROM rooms WHERE id = ?', [$rid]) === 'HTTP Quiz' && $value('SELECT state FROM rooms WHERE id = ?', [$rid]) === 'editing';
})());
$check('student cannot see correct answers or explanations while editing', (function () use ($studentJar): bool {
    $page = request('GET', '/q/http-quiz', $studentJar);
    return $page['status'] === 200 && !str_contains($page['body'], 'SECRET-EXPLANATION') && !str_contains($page['body'], 'Paris')
        && str_contains($page['body'], 'DRAFT-PROMPT-TEXT');
})());
$check('student sees no owner controls', (function () use ($studentJar): bool {
    $body = request('GET', '/q/http-quiz', $studentJar)['body'];
    return !str_contains($body, 'data-question-menu') && !str_contains($body, 'Activate quiz') && !str_contains($body, 'room-settings');
})());
$check('student cannot read results or exports', request('GET', '/results.php?room=http-quiz', $studentJar)['status'] === 303
    && request('GET', '/results/export.php?room=http-quiz&rev=1', $studentJar)['status'] === 303);

// ---- Activation, then quiz mode ----
$check('owner can activate over HTTP', (function () use ($teacherJar, $tTeacher, $value, $rid): bool {
    request('POST', '/rooms/lifecycle.php', $teacherJar, ['csrf_token' => $tTeacher, 'room_slug' => 'http-quiz', 'action' => 'activate']);
    return $value('SELECT state FROM rooms WHERE id = ?', [$rid]) === 'active';
})());
$check('active quiz hides every question and answer from viewers', (function () use ($studentJar, $teacherJar): bool {
    foreach ([$studentJar, $teacherJar] as $jar) {
        $body = request('GET', '/q/http-quiz', $jar)['body'];
        if (str_contains($body, 'DRAFT-PROMPT-TEXT') || str_contains($body, 'Capital of France') || str_contains($body, 'SECRET-EXPLANATION')) {
            return false;
        }
    }
    return true;
})());
$check('editor is closed once active', request('GET', '/questions/editor.php?room=http-quiz', $studentJar)['status'] === 303
    && request('GET', '/questions/editor.php?room=http-quiz&id=' . $qid, $teacherJar)['status'] === 303);
$check('nobody can save questions into an active quiz', (function () use ($teacherJar, $tTeacher, $value, $rid): bool {
    $before = (int) $value('SELECT COUNT(*) FROM questions WHERE room_id = ?', [$rid]);
    request('POST', '/questions/save.php', $teacherJar, [
        'csrf_token' => $tTeacher, 'room_slug' => 'http-quiz', 'type' => 'true_false', 'prompt_html' => '<p>late</p>', 'true_false_correct' => 'true', 'points' => '1',
    ]);
    return (int) $value('SELECT COUNT(*) FROM questions WHERE room_id = ?', [$rid]) === $before;
})());

// ---- Attempts ----
$start = request('POST', '/quiz/start.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz']);
$attemptUrl = $start['location'] !== '' ? parse_url($start['location'], PHP_URL_PATH) . '?' . parse_url($start['location'], PHP_URL_QUERY) : '';
$attemptId = (int) $value('SELECT id FROM quiz_attempts LIMIT 1');
$check('student can start an attempt', $attemptId > 0 && str_contains($attemptUrl, '/quiz/attempt.php'));
$page = request('GET', $attemptUrl, $studentJar);
$check('attempt page shows the question but never the answer key or explanation', $page['status'] === 200
    && str_contains($page['body'], 'Capital of France') && !str_contains($page['body'], 'Paris') && !str_contains($page['body'], 'SECRET-EXPLANATION'));
$check('another student cannot open or write to that attempt', (function () use ($otherJar, $tOther, $attemptUrl, $attemptId, $qid, $value): bool {
    $view = request('GET', $attemptUrl, $otherJar);
    $write = request('POST', '/quiz/answer.php', $otherJar, ['csrf_token' => $tOther, 'attempt_id' => $attemptId, 'question_id' => $qid, 'answer' => 'Paris']);
    $submit = request('POST', '/quiz/submit.php', $otherJar, ['csrf_token' => $tOther, 'attempt_id' => $attemptId]);
    return $view['status'] === 303 && $write['status'] === 400
        && (int) $value('SELECT COUNT(*) FROM quiz_answers') === 0
        && $value('SELECT status FROM quiz_attempts WHERE id = ?', [$attemptId]) === 'in_progress'
        && $submit['status'] === 303;
})());
$check('owner cannot save answers into a student\'s attempt', (function () use ($teacherJar, $tTeacher, $attemptId, $qid, $value): bool {
    request('POST', '/quiz/answer.php', $teacherJar, ['csrf_token' => $tTeacher, 'attempt_id' => $attemptId, 'question_id' => $qid, 'answer' => 'Paris']);
    return (int) $value('SELECT COUNT(*) FROM quiz_answers') === 0;
})());
$check('autosave and submit work for the attempt owner', (function () use ($studentJar, $tStudent, $attemptId, $qid, $value): bool {
    $saved = request('POST', '/quiz/answer.php', $studentJar, ['csrf_token' => $tStudent, 'attempt_id' => $attemptId, 'question_id' => $qid, 'answer' => ' paris ']);
    request('POST', '/quiz/submit.php', $studentJar, ['csrf_token' => $tStudent, 'attempt_id' => $attemptId]);
    return str_contains($saved['body'], 'saved') && $value('SELECT score FROM quiz_attempts WHERE id = ?', [$attemptId]) == 1;
})());
$check('owner sees results; students and anonymous visitors do not', request('GET', '/results.php?room=http-quiz', $teacherJar)['status'] === 200
    && request('GET', '/results/export.php?room=http-quiz&rev=1', $teacherJar)['status'] === 200
    && request('GET', '/results/export.php?room=http-quiz&rev=1', $studentJar)['status'] === 303);
$check('student cannot reset results', (function () use ($studentJar, $tStudent, $value): bool {
    request('POST', '/results/reset.php', $studentJar, ['csrf_token' => $tStudent, 'room_slug' => 'http-quiz', 'revision_id' => 1]);
    return (int) $value('SELECT COUNT(*) FROM quiz_attempts') === 1;
})());

// ---- Error hygiene ----
$check('database errors never reach the browser', (function () use ($teacherJar, $db): bool {
    $db->exec('ALTER TABLE quiz_attempts RENAME TO quiz_attempts_hidden');
    $page = request('GET', '/q/http-quiz', $teacherJar);
    $db->exec('ALTER TABLE quiz_attempts_hidden RENAME TO quiz_attempts');
    return $page['status'] === 500 && str_contains($page['body'], 'Something went wrong')
        && !str_contains($page['body'], 'SQLSTATE') && !str_contains($page['body'], 'quiz_attempts') && !str_contains($page['body'], dirname(__DIR__));
})());

echo $failures === 0 ? "\nAll HTTP checks passed.\n" : "\n$failures HTTP check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
