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
$isAjax = strcasecmp(
    (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''),
    'XMLHttpRequest',
) === 0;
try {
    Csrf::validate();
    $user = Session::user(new UserRepository($runtime['database']));
    if ($user === null) {
        throw new RuntimeException('Sign in before reordering questions.');
    }
    $redirect = '/q/' . rawurlencode((string) ($_POST['room_slug'] ?? ''));
    $repository = new QuestionRepository($runtime['database']);
    $targetPosition = filter_var($_POST['target_position'] ?? null, FILTER_VALIDATE_INT);
    if ($targetPosition !== false && $targetPosition !== null) {
        $repository->reorderTo(
            (int) ($_POST['question_id'] ?? 0),
            (int) $user['id'],
            $targetPosition,
        );
    } elseif (isset($_POST['direction'])) {
        $repository->move(
            (int) ($_POST['question_id'] ?? 0),
            (int) $user['id'],
            (string) $_POST['direction'],
        );
    } else {
        throw new RuntimeException('Choose a target position or a direction.');
    }
    if ($isAjax) {
        Http::json(['status' => 'ok']);
    }
    Session::set('flash_success', 'Question order updated.');
} catch (Throwable $exception) {
    if ($isAjax) {
        Http::json([
            'status' => 'error',
            'message' => $exception->getMessage(),
        ], 400);
    }
    Session::set('flash_error', $exception->getMessage());
}

Http::redirect($redirect);

