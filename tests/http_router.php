<?php

declare(strict_types=1);

// Router for tests/http.php only: adds a test-only sign-in shortcut, then defers to the real router.
// Never deploy or reference this file outside the test run.
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/__test/login') {
    $runtime = require dirname(__DIR__) . '/app/bootstrap.php';
    \QuizMyClass\Auth\Session::start($runtime['config']);
    $user = (new \QuizMyClass\Support\UserRepository($runtime['database']))->syncGoogleProfile([
        'sub' => (string) ($_GET['sub'] ?? 'nobody'),
        'email' => ($_GET['sub'] ?? 'nobody') . '@test.example',
        'name' => ucfirst((string) ($_GET['sub'] ?? 'nobody')),
    ]);
    \QuizMyClass\Auth\Session::login((int) $user['id']);
    echo 'ok';
    return true;
}
return require dirname(__DIR__) . '/public/router.php';
