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

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);

try {
    $user = Session::user(new UserRepository($runtime['database']));
    if ($user === null) {
        Session::set('flash_error', 'Sign in before working on questions.');
        Http::redirect('/');
    }
    $rooms = new RoomRepository($runtime['database']);
    $room = $rooms->findBySlug((string) ($_GET['room'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Room not found.');
    }
    $role = $rooms->roleForUser((int) $room['id'], (int) $user['id']);
    if ($room['state'] !== 'editing') {
        throw new RuntimeException('This room is no longer editable.');
    }

    $questions = new QuestionRepository($runtime['database']);
    $questionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $question = $questionId === false || $questionId === null
        ? null
        : $questions->findForEditor($questionId, (int) $user['id']);
    if ($question !== null && (int) $question['room_id'] !== (int) $room['id']) {
        throw new RuntimeException('Question does not belong to this room.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
    Http::redirect(isset($room) ? '/q/' . rawurlencode((string) $room['slug']) : '/');
}

$type = (string) ($question['type'] ?? 'multiple_choice');
$prompt = (string) ($question['prompt_html'] ?? '');
$explanation = (string) ($question['explanation_html'] ?? '');
$points = (string) ($question['points'] ?? '1');
$choices = [];
$answer = [];
if ($question !== null) {
    $choices = json_decode((string) $question['choices_json'], true);
    $answer = json_decode((string) $question['answer_json'], true);
}
$choiceText = is_array($choices) ? implode("\n", $choices) : '';
$correctNumbers = '';
if (isset($answer['correct_index'])) {
    $correctNumbers = (string) ((int) $answer['correct_index'] + 1);
} elseif (isset($answer['correct_indexes']) && is_array($answer['correct_indexes'])) {
    $correctNumbers = implode(', ', array_map(
        static fn (int $index): int => $index + 1,
        array_map('intval', $answer['correct_indexes']),
    ));
}
$appName = $escape($runtime['config']['app']['name']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $question === null ? 'New question' : 'Edit question' ?> · <?= $appName ?></title>
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
                    <div class="left">
                        <a class="link external" href="/q/<?= $escape($room['slug']) ?>">Back</a>
                    </div>
                    <div class="title"><?= $question === null ? 'New question' : 'Edit question' ?></div>
                </div>
            </div>
            <main class="page-content">
                <form action="/questions/save.php" method="post" data-question-form>
                    <input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>">
                    <input type="hidden" name="room_slug" value="<?= $escape($room['slug']) ?>">
                    <?php if ($question !== null): ?>
                        <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                    <?php endif; ?>

                    <div class="block-title">Question type</div>
                    <div class="list">
                        <ul>
                            <li>
                                <div class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-input-wrap">
                                            <select name="type" data-question-type-select>
                                                <option value="multiple_choice" <?= $type === 'multiple_choice' ? 'selected' : '' ?>>Multiple choice</option>
                                                <option value="multiple_select" <?= $type === 'multiple_select' ? 'selected' : '' ?>>Multiple select</option>
                                                <option value="true_false" <?= $type === 'true_false' ? 'selected' : '' ?>>True / false</option>
                                                <option value="short_text" <?= $type === 'short_text' ? 'selected' : '' ?>>Short text</option>
                                                <option value="numeric" <?= $type === 'numeric' ? 'selected' : '' ?>>Numeric</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="block-title">Prompt</div>
                    <div
                        class="text-editor text-editor-resizable rich-text-editor"
                        data-rich-text-editor
                        data-rich-text-key="prompt_html"
                    >
                        <div class="text-editor-content" contenteditable="true"></div>
                    </div>
                    <textarea data-rich-text-source="prompt_html" hidden><?= $escape($prompt) ?></textarea>
                    <input type="hidden" name="prompt_html" data-rich-text-target="prompt_html">
                    <div class="block-footer">
                        Formatting allowed: paragraphs, line breaks, bold, italic,
                        underline, lists, color, HTTPS links, and linked HTTPS images.
                    </div>

                    <div data-question-fields="choice">
                        <div class="block-title">Choices</div>
                        <div class="list">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-input-wrap">
                                            <textarea name="choices" rows="6" placeholder="One choice per line"><?= $escape($choiceText) ?></textarea>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-title item-label">Correct choice number(s)</div>
                                        <div class="item-input-wrap">
                                            <input name="correct_numbers" value="<?= $escape($correctNumbers) ?>" placeholder="1 or 1, 3">
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div data-question-fields="true_false">
                        <div class="block-title">Correct answer</div>
                        <div class="list">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-input-wrap">
                                            <select name="true_false_correct">
                                                <option value="true" <?= ($answer['correct'] ?? false) === true ? 'selected' : '' ?>>True</option>
                                                <option value="false" <?= ($answer['correct'] ?? null) === false ? 'selected' : '' ?>>False</option>
                                            </select>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div data-question-fields="short_text">
                        <div class="block-title">Accepted answers</div>
                        <div class="list">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-input-wrap">
                                            <textarea name="accepted_answers" rows="5" placeholder="One accepted answer per line"><?= $escape(is_array($answer['accepted_answers'] ?? null) ? implode("\n", $answer['accepted_answers']) : '') ?></textarea>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div data-question-fields="numeric">
                        <div class="block-title">Numeric answer</div>
                        <div class="list">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-title item-label">Correct value</div>
                                        <div class="item-input-wrap">
                                            <input name="numeric_answer" type="number" step="any" value="<?= $escape($answer['correct'] ?? '') ?>">
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <div class="item-title item-label">Tolerance (plus or minus)</div>
                                        <div class="item-input-wrap">
                                            <input name="numeric_tolerance" type="number" min="0" step="any" value="<?= $escape($answer['tolerance'] ?? '0') ?>">
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="block-title">Feedback and points</div>
                    <div class="list">
                        <ul>
                            <li class="item-content item-input">
                                <div class="item-inner">
                                    <div class="item-title item-label">Points</div>
                                    <div class="item-input-wrap">
                                        <input name="points" type="number" min="0.1" max="100" step="0.1" value="<?= $escape($points) ?>" required>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="block-title">Optional explanation</div>
                    <div
                        class="text-editor text-editor-resizable rich-text-editor"
                        data-rich-text-editor
                        data-rich-text-key="explanation_html"
                    >
                        <div class="text-editor-content" contenteditable="true"></div>
                    </div>
                    <textarea data-rich-text-source="explanation_html" hidden><?= $escape($explanation) ?></textarea>
                    <input type="hidden" name="explanation_html" data-rich-text-target="explanation_html">

                    <div class="block">
                        <button class="button button-fill button-large" type="submit">
                            <?= $question === null ? 'Add question' : 'Save question' ?>
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>
</div>
<script src="/assets/vendor/framework7/framework7-bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/rich-text.js"></script>
<script src="/assets/js/questions.js"></script>
</body>
</html>

