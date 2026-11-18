<?php

declare(strict_types=1);

use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
$rooms = new RoomRepository($runtime['database']);
$questions = new QuestionRepository($runtime['database']);
$user = Session::user(new UserRepository($runtime['database']));
$error = Session::forget('flash_error');
$success = Session::forget('flash_success');

try {
    $slug = (string) ($_GET['slug'] ?? '');
    $room = $rooms->findBySlug($slug);
    if ($room === null) {
        http_response_code(404);
        $pageTitle = 'Room not found';
        $role = null;
        $roomQuestions = [];
    } else {
        $pageTitle = (string) $room['name'];
        $role = $user === null ? null : $rooms->roleForUser(
            (int) $room['id'],
            (int) $user['id'],
        );
        $roomQuestions = $user === null
            ? []
            : $questions->listForRoom((int) $room['id'], (int) $user['id'], $role === 'owner');
    }
} catch (Throwable $exception) {
    http_response_code(400);
    $pageTitle = 'Invalid room link';
    $room = null;
    $role = null;
    $roomQuestions = [];
    $error = $exception->getMessage();
}

$google = new GoogleClient($runtime['config']);
$appName = $escape($runtime['config']['app']['name']);
$answerLabel = static function (array $question): string {
    if (!$question['can_see_answer'] || $question['answer_json'] === '') {
        return '';
    }
    $answer = json_decode((string) $question['answer_json'], true);
    $choices = json_decode((string) $question['choices_json'], true);
    if (!is_array($answer)) {
        return '';
    }
    if (isset($answer['correct_index']) && is_array($choices)) {
        return (string) ($choices[(int) $answer['correct_index']] ?? '');
    }
    if (isset($answer['correct_indexes']) && is_array($choices)) {
        return implode(', ', array_map(
            static fn (int $index): string => (string) ($choices[$index] ?? ''),
            array_map('intval', $answer['correct_indexes']),
        ));
    }
    if (($question['type'] ?? '') === 'true_false') {
        return ($answer['correct'] ?? false) ? 'True' : 'False';
    }
    if (isset($answer['accepted_answers']) && is_array($answer['accepted_answers'])) {
        return implode('; ', array_map('strval', $answer['accepted_answers']));
    }
    if (isset($answer['correct'])) {
        $tolerance = (float) ($answer['tolerance'] ?? 0);
        return (string) $answer['correct'] . ($tolerance > 0 ? ' ± ' . $tolerance : '');
    }
    return '';
};
$statusColor = [
    'draft' => 'orange',
    'approved' => 'green',
    'rejected' => 'red',
    'hidden' => 'gray',
];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $escape($pageTitle) ?> · <?= $appName ?></title>
    <link rel="stylesheet" href="/assets/vendor/framework7/framework7-bundle.min.css">
    <link rel="stylesheet" href="/assets/css/questions.css">
</head>
<body>
<div id="app">
    <div class="view view-main view-init safe-areas">
        <div class="page">
            <div class="navbar">
                <div class="navbar-bg"></div>
                <div class="navbar-inner">
                    <div class="left"><a class="link external" href="/">Home</a></div>
                    <div class="title"><?= $appName ?></div>
                </div>
            </div>
            <main class="page-content" <?= $room !== null ? 'data-room-slug="' . $escape($room['slug']) . '"' : '' ?> <?= $room !== null ? 'data-room-csrf="' . $escape(Csrf::token()) . '"' : '' ?>>
                <?php if (is_string($error) && $error !== ''): ?>
                    <div class="block" role="alert">
                        <div class="card color-red">
                            <div class="card-content card-content-padding"><?= $escape($error) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (is_string($success) && $success !== ''): ?>
                    <div class="block" role="status">
                        <div class="card color-green">
                            <div class="card-content card-content-padding"><?= $escape($success) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <section class="block block-strong">
                    <?php if ($role !== null): ?>
                        <span class="badge color-<?= $role === 'owner' ? 'blue' : 'green' ?>">
                            <?= $role === 'owner' ? 'Teacher' : 'Student' ?>
                        </span>
                    <?php endif; ?>
                    <div class="room-title-row">
                        <h1><?= $escape($pageTitle) ?></h1>
                        <?php if ($room !== null && $user !== null && $role === 'owner'): ?>
                            <a class="link icon-only popup-open room-settings-button" href="#" data-popup="#room-settings" aria-label="Room settings" title="Room settings">
                                <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
                                    <path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.07-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.61-.22l-2.39.96a7.1 7.1 0 0 0-1.62-.94L14.38 2.8a.49.49 0 0 0-.49-.42h-3.84a.49.49 0 0 0-.49.42l-.36 2.52c-.59.24-1.13.56-1.62.94l-2.39-.96a.49.49 0 0 0-.61.22L2.66 8.86a.49.49 0 0 0 .12.64l2.03 1.58c-.05.31-.09.64-.09.94s.03.63.08.94l-2.02 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.31.61.22l2.39-.96c.5.38 1.04.7 1.62.94l.36 2.52c.04.24.25.42.49.42h3.84c.24 0 .45-.18.49-.42l.36-2.52c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.09.48 0 .61-.22l1.92-3.32a.49.49 0 0 0-.12-.64l-2.04-1.6ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if ($room === null): ?>
                        <p>Check the room link and try again.</p>
                    <?php elseif ($user === null): ?>
                        <p>Sign in with Google to enter this room.</p>
                        <?php if ($google->isConfigured()): ?>
                            <a class="button button-fill button-large external" href="/auth/google.php">Sign in with Google</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>
                            <?= $escape(ucfirst((string) $room['state'])) ?> mode · <?= count($roomQuestions) ?> of
                            <?= (int) $room['max_questions'] ?> questions
                        </p>
                        <?php if ($room['state'] === 'editing'): ?>
                            <a
                                class="button button-fill button-large external"
                                href="/questions/editor.php?room=<?= $escape(rawurlencode((string) $room['slug'])) ?>"
                            >Add a question</a>
                        <?php else: ?>
                            <p><span class="badge color-gray">Questions locked</span> This room is not in editing mode.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <?php if ($room !== null && $user !== null): ?>
                    <section class="block-title">Questions</section>
                    <?php if ($roomQuestions === []): ?>
                        <div class="block">No questions have been added yet.</div>
                    <?php endif; ?>
                    <?php if ($roomQuestions !== []): ?>
                        <div
                            class="list medium-inset <?= $role === 'owner' ? 'sortable sortable-opposite' : '' ?>"
                            data-question-sortable
                            data-sortable-owner="<?= $role === 'owner' ? '1' : '0' ?>"
                        >
                            <ul>
                                <?php foreach ($roomQuestions as $index => $question): ?>
                                    <?php
                                    $plainPrompt = mb_strimwidth(
                                        html_entity_decode(
                                            trim(strip_tags((string) $question['prompt_html'])),
                                            ENT_QUOTES | ENT_HTML5,
                                            'UTF-8',
                                        ),
                                        0,
                                        90,
                                        '…',
                                        'UTF-8',
                                    );
                                    $correct = $answerLabel($question);
                                    ?>
                                    <li data-question-id="<?= (int) $question['id'] ?>" <?= $role !== 'owner' ? 'class="no-sorting"' : '' ?>>
                                        <div class="item-content">
                                            <div class="item-inner question-row">
                                                <div class="question-row-main">
                                                    <div class="item-title question-row-title">
                                                        <?= $escape($plainPrompt !== '' ? $plainPrompt : 'Question ' . ($index + 1)) ?>
                                                    </div>
                                                    <div class="item-subtitle question-row-subtitle">
                                                        <?= $escape($question['author_name']) ?>
                                                        <?php if ($correct !== ''): ?>
                                                            · <strong><?= $escape($correct) ?></strong>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="item-text question-row-detail">
                                                        <span class="badge color-<?= $statusColor[$question['status']] ?? 'gray' ?>">
                                                            <?= $escape(ucfirst($question['status'])) ?>
                                                        </span>
                                                        <?= $escape(str_replace('_', ' ', $question['type'])) ?> · <?= $escape($question['points']) ?> points
                                                    </div>
                                                </div>
                                                <?php if ($question['can_edit'] || $role === 'owner'): ?>
                                                    <div class="question-row-actions">
                                                            <?php if ($question['can_edit'] && $room['state'] === 'editing'): ?>
                                                                <a
                                                                    class="button button-small icon-only external"
                                                                    href="/questions/editor.php?room=<?= $escape(rawurlencode((string) $room['slug'])) ?>&amp;id=<?= (int) $question['id'] ?>"
                                                                    aria-label="Edit question"
                                                                    title="Edit"
                                                                >
                                                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25Zm17.71-10.04a1 1 0 0 0 0-1.42l-2.5-2.5a1 1 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 1.99-1.66Z"/></svg>
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if ($role === 'owner' && $room['state'] === 'editing'): ?>
                                                                <?php foreach (['approved' => 'check', 'rejected' => 'close', 'hidden' => 'eye-off', 'draft' => 'draft'] as $status => $icon): ?>
                                                                    <?php if ($status !== $question['status']): ?>
                                                                        <form action="/questions/moderate.php" method="post" class="inline">
                                                                            <input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>">
                                                                            <input type="hidden" name="room_slug" value="<?= $escape($room['slug']) ?>">
                                                                            <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                                                                            <input type="hidden" name="status" value="<?= $status ?>">
                                                                            <button
                                                                                class="button button-small icon-only <?= $status === 'approved' ? 'color-green' : ($status === 'rejected' ? 'color-red' : '') ?>"
                                                                                type="submit"
                                                                                aria-label="<?= ucfirst($status) ?> question"
                                                                                title="<?= ucfirst($status) ?>"
                                                                            >
                                                                                <?php if ($icon === 'check'): ?>
                                                                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41Z"/></svg>
                                                                                <?php elseif ($icon === 'close'): ?>
                                                                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.41l-6.3 6.3-1.41-1.42L9.17 12l-6.29-6.29 1.41-1.42 6.3 6.3 6.29-6.3Z"/></svg>
                                                                                <?php elseif ($icon === 'eye-off'): ?>
                                                                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m2.1 3.51 1.41-1.42 18.9 18.9-1.41 1.42-3.17-3.17A11.7 11.7 0 0 1 12 21C5 21 1 12 1 12a17.7 17.7 0 0 1 4.11-5.3Zm3.4 3.4A15.8 15.8 0 0 0 3.14 12c1.1 2.06 3.53 6 8.86 6 1.46 0 2.73-.3 3.84-.77l-2.12-2.12A4 4 0 0 1 9.1 9.1Zm10.68 10.68-2.38-2.38A4 4 0 0 1 10.1 11.5l-2.5-2.5A4 4 0 0 1 12 8c2.21 0 4 1.79 4 4 0 .79-.23 1.53-.64 2.16l2.82 2.82A15.5 15.5 0 0 0 20.86 12 12 12 0 0 0 18.8 8.2Z"/></svg>
                                                                                <?php else: ?>
                                                                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5a7 7 0 1 1-6.32 4H3.5A8.99 8.99 0 1 0 12 3v2Zm-9.5 2H8v5H6V9H2.5V7Z"/></svg>
                                                                                <?php endif; ?>
                                                                            </button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                        </div>
                                        <?php if ($role === 'owner'): ?>
                                            <div class="sortable-handler" aria-label="Drag to reorder"></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
            <?php if ($room !== null && $user !== null && $role === 'owner'): ?>
                <div class="popup" id="room-settings">
                    <div class="view">
                        <div class="page">
                            <div class="navbar">
                                <div class="navbar-bg"></div>
                                <div class="navbar-inner">
                                    <div class="title">Room settings</div>
                                    <div class="right">
                                        <a class="link popup-close" href="#">Done</a>
                                    </div>
                                </div>
                            </div>
                            <div class="page-content">
                                <form action="/rooms/settings.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>">
                                    <input type="hidden" name="room_slug" value="<?= $escape($room['slug']) ?>">
                                    <div class="block-title">Collaboration limits</div>
                                    <div class="list">
                                        <ul>
                                            <li class="item-content item-input">
                                                <div class="item-inner">
                                                    <div class="item-title item-label">Maximum room questions</div>
                                                    <div class="item-input-wrap">
                                                        <input name="max_questions" type="number" min="1" max="500" value="<?= (int) $room['max_questions'] ?>" required>
                                                    </div>
                                                </div>
                                            </li>
                                            <li class="item-content item-input">
                                                <div class="item-inner">
                                                    <div class="item-title item-label">Questions per student</div>
                                                    <div class="item-input-wrap">
                                                        <input name="student_question_cap" type="number" min="1" max="100" value="<?= (int) $room['student_question_cap'] ?>" required>
                                                    </div>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="block">
                                        <button class="button button-fill button-large" type="submit">Save limits</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="/assets/vendor/framework7/framework7-bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/room.js"></script>
</body>
</html>

