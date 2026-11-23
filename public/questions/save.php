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

/**
 * Convert the editor's repeated choice rows into the repository's input shape.
 * Blank rows are dropped and "correct" row numbers are remapped to the kept rows.
 *
 * @param array<string, mixed> $post
 * @return array{choices: string, correct: list<int>}
 */
$collectChoices = static function (array $post): array {
    $texts = is_array($post['choice_text'] ?? null) ? $post['choice_text'] : [];
    $marked = is_array($post['choice_correct'] ?? null) ? $post['choice_correct'] : [];
    $marked = array_map('strval', $marked);
    $kept = [];
    $correct = [];
    foreach (array_values($texts) as $row => $text) {
        $text = trim(is_string($text) ? $text : '');
        if ($text === '') {
            continue;
        }
        if (in_array($text, $kept, true)) {
            throw new RuntimeException('Each choice must be different.');
        }
        if (in_array((string) $row, $marked, true)) {
            $correct[] = count($kept);
        }
        $kept[] = $text;
    }
    return ['choices' => implode("\n", $kept), 'correct' => $correct];
};

$redirect = '/';
$room = null;
$questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
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
        throw new RuntimeException('Quiz not found.');
    }
    $redirect = '/q/' . rawurlencode((string) $room['slug']);

    $type = (string) ($_POST['type'] ?? '');
    $input = $_POST;
    if ($type === 'multiple_choice' || $type === 'multiple_select') {
        $collected = $collectChoices($_POST);
        $input['choices'] = $collected['choices'];
        $input['correct'] = $collected['correct'];
    } elseif ($type === 'true_false') {
        $input['correct'] = (string) ($_POST['true_false_correct'] ?? '');
    }

    $repository = new QuestionRepository($runtime['database']);
    if ($questionId === false || $questionId === null) {
        $repository->create((int) $room['id'], (int) $user['id'], $input);
        Session::set('flash_success', 'Question added for review.');
    } else {
        $existing = $repository->findForEditor($questionId, (int) $user['id']);
        if ($existing === null || (int) $existing['room_id'] !== (int) $room['id']) {
            throw new RuntimeException('Question does not belong to this quiz.');
        }
        $repository->update($questionId, (int) $user['id'], $input);
        Session::set('flash_success', 'Question saved and returned to draft.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', Http::safeMessage($exception));
    if ($room !== null && Session::get('user_id') !== null) {
        // Keep what the user typed so a validation error or expired form does not lose their work.
        $keep = ['type', 'prompt_html', 'explanation_html', 'points', 'choice_text', 'choice_correct',
            'true_false_correct', 'accepted_answers', 'numeric_answer', 'numeric_tolerance'];
        $draft = ['room_slug' => (string) $room['slug'], 'question_id' => is_int($questionId) ? $questionId : null];
        foreach ($keep as $field) {
            if (isset($_POST[$field])) {
                $draft[$field] = $_POST[$field];
            }
        }
        Session::set('question_draft', $draft);
        $redirect = '/questions/editor.php?room=' . rawurlencode((string) $room['slug'])
            . (is_int($questionId) ? '&id=' . $questionId : '');
    }
}

Http::redirect($redirect);
