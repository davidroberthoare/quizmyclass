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
    $room = (new RoomRepository($runtime['database']))->findBySlug((string) ($_POST['room_slug'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Quiz not found.');
    }
    $redirect = '/q/' . rawurlencode((string) $room['slug']);
    $quiz = new QuizRepository($runtime['database'], (string) $runtime['config']['app']['timezone']);
    switch ((string) ($_POST['action'] ?? '')) {
        case 'activate':
            $quiz->activate((int) $room['id'], (int) $user['id']);
            Session::set('flash_success', 'Quiz activated. Students can start now.');
            break;
        case 'close':
            $quiz->close((int) $room['id'], (int) $user['id']);
            Session::set('flash_success', 'Quiz closed.');
            break;
        case 'reopen':
            $quiz->reopenForEditing((int) $room['id'], (int) $user['id']);
            Session::set('flash_success', 'Back in editing mode.');
            break;
        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}
Http::redirect($redirect);
