<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuestionRepository;
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
        throw new RuntimeException('Sign in before moderating questions.');
    }
    $roomSlug = (string) ($_POST['room_slug'] ?? '');
    $redirect = '/q/' . rawurlencode($roomSlug);
    $rooms = new RoomRepository($runtime['database']);
    $room = $rooms->findBySlug($roomSlug);
    if ($room === null) {
        throw new RuntimeException('Quiz not found.');
    }
    $questions = new QuestionRepository($runtime['database']);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'approve_all') {
        $questions->moderateAll((int) $room['id'], (int) $user['id'], 'approved');
        Session::set('flash_success', 'All questions approved.');
    } elseif ($action === 'unapprove_all') {
        $questions->moderateAll((int) $room['id'], (int) $user['id'], 'draft');
        Session::set('flash_success', 'All questions unapproved.');
    } else {
        $questions->moderate(
            (int) ($_POST['question_id'] ?? 0),
            (int) $user['id'],
            (string) ($_POST['status'] ?? ''),
        );
        Session::set('flash_success', 'Question status updated.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}

Http::redirect($redirect);

