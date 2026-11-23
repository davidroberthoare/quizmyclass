<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    Csrf::validate();
    $user = Session::user(new UserRepository($runtime['database']));
    if ($user === null) {
        Session::set('flash_error', 'Sign in before creating a quiz.');
        Http::redirect('/');
    }

    $room = (new RoomRepository($runtime['database']))->create(
        (int) $user['id'],
        (string) ($_POST['name'] ?? ''),
        (string) ($_POST['slug'] ?? ''),
    );
    Session::set('flash_success', 'Quiz created.');
    Http::redirect('/q/' . rawurlencode((string) $room['slug']));
} catch (Throwable $exception) {
    Session::set('flash_error', Http::safeMessage($exception));
    Http::redirect('/');
}

