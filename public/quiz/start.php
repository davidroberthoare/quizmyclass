<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\RoomRepository;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$redirect = '/';
try {
    $user = Endpoint::postUser($runtime);
    $room = (new RoomRepository($runtime['database']))->findBySlug((string) ($_POST['room_slug'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Quiz not found.');
    }
    $redirect = '/q/' . rawurlencode((string) $room['slug']);
    $revision = (new QuizRepository($runtime['database']))->latestRevision((int) $room['id']);
    if ($revision === null) {
        throw new RuntimeException('This quiz is not open for attempts.');
    }
    $attempt = (new AttemptRepository($runtime['database']))->startOrResume($room, $revision, (int) $user['id']);
    $redirect = '/quiz/attempt.php?id=' . (int) $attempt['id'];
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}
Http::redirect($redirect);
