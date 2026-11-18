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

$redirect = '/';
try {
    Csrf::validate();
    $user = Session::user(new UserRepository($runtime['database']));
    if ($user === null) {
        throw new RuntimeException('Sign in before changing room settings.');
    }
    $rooms = new RoomRepository($runtime['database']);
    $room = $rooms->findBySlug((string) ($_POST['room_slug'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Room not found.');
    }
    $redirect = '/q/' . rawurlencode((string) $room['slug']);
    $rooms->updateCollaborationLimits(
        (int) $room['id'],
        (int) $user['id'],
        (int) ($_POST['max_questions'] ?? 0),
        (int) ($_POST['student_question_cap'] ?? 0),
    );
    Session::set('flash_success', 'Room question limits saved.');
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}

Http::redirect($redirect);

