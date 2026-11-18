<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\UserRepository;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$redirect = '/';
try {
    Csrf::validate();
    $user = Session::user(new UserRepository($runtime['database']));
    if ($user === null) {
        throw new RuntimeException('Sign in before moderating questions.');
    }
    $redirect = '/q/' . rawurlencode((string) ($_POST['room_slug'] ?? ''));
    (new QuestionRepository($runtime['database']))->moderate(
        (int) ($_POST['question_id'] ?? 0),
        (int) $user['id'],
        (string) ($_POST['status'] ?? ''),
    );
    Session::set('flash_success', 'Question status updated.');
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}

Http::redirect($redirect);

