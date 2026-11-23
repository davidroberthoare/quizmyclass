<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\RoomRepository;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$redirect = '/';
try {
    $user = Endpoint::postUser($runtime);
    $rooms = new RoomRepository($runtime['database']);
    $room = $rooms->findBySlug((string) ($_POST['room_slug'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Quiz not found.');
    }
    $redirect = '/q/' . rawurlencode((string) $room['slug']);
    $rooms->rename(
        (int) $room['id'],
        (int) $user['id'],
        (string) ($_POST['name'] ?? ''),
        (string) ($_POST['slug'] ?? ''),
    );
    // Any later step must send the user to the new link.
    $redirect = '/q/' . rawurlencode(RoomRepository::normalizeSlug((string) ($_POST['slug'] ?? '')));
    $quiz = new QuizRepository($runtime['database'], (string) $runtime['config']['app']['timezone']);
    // Validate the delivery settings first so a bad value saves nothing.
    $quiz->saveSettings((int) $room['id'], (int) $user['id'], $_POST);
    $rooms->updateCollaborationLimits(
        (int) $room['id'],
        (int) $user['id'],
        (int) ($_POST['max_questions'] ?? 0),
        (int) ($_POST['student_question_cap'] ?? 0),
    );
    Session::set('flash_success', 'Quiz settings saved.');
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}
Http::redirect($redirect);
