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
        throw new RuntimeException('Sign in before saving a question.');
    }
    $room = (new RoomRepository($runtime['database']))->findBySlug(
        (string) ($_POST['room_slug'] ?? '')
    );
    if ($room === null) {
        throw new RuntimeException('Room not found.');
    }
    $redirect = '/q/' . rawurlencode((string) $room['slug']);

    $type = (string) ($_POST['type'] ?? '');
    $input = $_POST;
    if ($type === 'multiple_choice' || $type === 'multiple_select') {
        $numbers = preg_split('/\s*,\s*/', trim((string) ($_POST['correct_numbers'] ?? '')));
        $filtered = array_filter($numbers ?: [], static fn (string $number): bool => $number !== '');
        $invalidNumbers = array_filter(
            $filtered,
            static fn (string $number): bool => ctype_digit($number) === false || (int) $number < 1,
        );
        $input['correct'] = $invalidNumbers === []
            ? array_map(static fn (string $number): int => (int) $number - 1, $filtered)
            : [-1];
    } elseif ($type === 'true_false') {
        $input['correct'] = (string) ($_POST['true_false_correct'] ?? '');
    }

    $repository = new QuestionRepository($runtime['database']);
    $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
    if ($questionId === false || $questionId === null) {
        $repository->create((int) $room['id'], (int) $user['id'], $input);
        Session::set('flash_success', 'Question added for review.');
    } else {
        $existing = $repository->findForEditor($questionId, (int) $user['id']);
        if ($existing === null || (int) $existing['room_id'] !== (int) $room['id']) {
            throw new RuntimeException('Question does not belong to this room.');
        }
        $repository->update($questionId, (int) $user['id'], $input);
        Session::set('flash_success', 'Question saved and returned to draft.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}

Http::redirect($redirect);

