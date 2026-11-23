<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$attemptId = (int) ($_POST['attempt_id'] ?? 0);
$redirect = '/';
try {
    $user = Endpoint::postUser($runtime);
    $answers = is_array($_POST['answer'] ?? null) ? $_POST['answer'] : [];
    $attempt = (new AttemptRepository($runtime['database']))->submit($attemptId, (int) $user['id'], $answers);
    $redirect = '/quiz/attempt.php?id=' . (int) $attempt['id'];
    Session::set('flash_success', $attempt['status'] === 'expired' ? 'Time ran out; your answers were submitted.' : 'Quiz submitted.');
} catch (Throwable $exception) {
    Session::set('flash_error', Http::safeMessage($exception));
}
Http::redirect($redirect);
