<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

try {
    $user = Session::user(new UserRepository($runtime['database']));
    if ($user === null) {
        Session::set('flash_error', 'Sign in before working on questions.');
        Http::redirect('/');
    }
    $rooms = new RoomRepository($runtime['database']);
    $room = $rooms->findBySlug((string) ($_GET['room'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Quiz not found.');
    }
    if ($room['state'] !== 'editing') {
        throw new RuntimeException('This quiz is no longer editable.');
    }

    $questions = new QuestionRepository($runtime['database']);
    $questionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $question = $questionId === false || $questionId === null
        ? null
        : $questions->findForEditor($questionId, (int) $user['id']);
    if ($question !== null && (int) $question['room_id'] !== (int) $room['id']) {
        throw new RuntimeException('Question does not belong to this quiz.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', Http::safeMessage($exception));
    Http::redirect(isset($room) && $room !== null ? '/q/' . rawurlencode((string) $room['slug']) : '/');
}

// ---- Normalize the form state from a saved question, or from a draft kept after a failed save ----
$draft = Session::forget('question_draft');
if (!is_array($draft)
    || ($draft['room_slug'] ?? null) !== $room['slug']
    || ($draft['question_id'] ?? null) !== ($question === null ? null : (int) $question['id'])
) {
    $draft = null;
}

$f = [
    'type' => 'multiple_choice',
    'prompt' => '',
    'explanation' => '',
    'points' => '1',
    'choices' => ['', '', '', ''],
    'correct' => [],
    'tf' => '',
    'accepted' => '',
    'numeric' => '',
    'tolerance' => '0',
];
$text = static fn (mixed $v): string => is_string($v) ? $v : '';

if ($question !== null) {
    $choices = json_decode((string) $question['choices_json'], true);
    $answer = json_decode((string) $question['answer_json'], true);
    $answer = is_array($answer) ? $answer : [];
    $f['type'] = (string) $question['type'];
    $f['prompt'] = (string) $question['prompt_html'];
    $f['explanation'] = (string) $question['explanation_html'];
    $f['points'] = (string) $question['points'];
    if (is_array($choices) && $choices !== []) {
        $f['choices'] = array_map('strval', $choices);
    }
    if (isset($answer['correct_index'])) {
        $f['correct'] = [(int) $answer['correct_index']];
    } elseif (isset($answer['correct_indexes']) && is_array($answer['correct_indexes'])) {
        $f['correct'] = array_map('intval', $answer['correct_indexes']);
    }
    if (array_key_exists('correct', $answer) && is_bool($answer['correct'])) {
        $f['tf'] = $answer['correct'] ? 'true' : 'false';
    }
    if (is_array($answer['accepted_answers'] ?? null)) {
        $f['accepted'] = implode("\n", array_map('strval', $answer['accepted_answers']));
    }
    if (isset($answer['correct']) && !is_bool($answer['correct'])) {
        $f['numeric'] = (string) $answer['correct'];
        $f['tolerance'] = (string) ($answer['tolerance'] ?? '0');
    }
} elseif ($draft === null && isset($_GET['type'])) {
    $f['type'] = (string) $_GET['type'];
}

if ($draft !== null) {
    $f['type'] = $text($draft['type'] ?? '') ?: $f['type'];
    $f['prompt'] = $text($draft['prompt_html'] ?? '');
    $f['explanation'] = $text($draft['explanation_html'] ?? '');
    $f['points'] = $text($draft['points'] ?? '1');
    $f['choices'] = is_array($draft['choice_text'] ?? null)
        ? array_map($text, array_values($draft['choice_text']))
        : $f['choices'];
    $f['correct'] = is_array($draft['choice_correct'] ?? null)
        ? array_map('intval', array_values($draft['choice_correct']))
        : [];
    $f['tf'] = $text($draft['true_false_correct'] ?? '');
    $f['accepted'] = $text($draft['accepted_answers'] ?? '');
    $f['numeric'] = $text($draft['numeric_answer'] ?? '');
    $f['tolerance'] = $text($draft['numeric_tolerance'] ?? '0');
}
while (count($f['choices']) < 2) {
    $f['choices'][] = '';
}

$types = [
    'multiple_choice' => ['Choice', 'Multiple choice (one correct answer)'],
    'multiple_select' => ['Multi', 'Multiple select (several correct answers)'],
    'true_false' => ['True / False', 'True or false'],
    'short_text' => ['Short text', 'Short text answer'],
    'numeric' => ['Number', 'Numeric answer'],
];
if (!isset($types[$f['type']])) {
    $f['type'] = 'multiple_choice';
}
$e = View::e(...);
$isNew = $question === null;
$slug = (string) $room['slug'];

View::begin($runtime, [
    'title' => $isNew ? 'New question' : 'Edit question',
    'heading' => $isNew ? 'New question' : 'Edit question',
    'user' => $user,
    'back' => '/q/' . rawurlencode($slug),
    'backLabel' => 'Quiz',
    'wide' => true,
]);
?>
<form action="/questions/save.php" method="post" data-question-form>
    <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
    <input type="hidden" name="room_slug" value="<?= $e($slug) ?>">
    <?php if (!$isNew): ?>
        <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="type" value="<?= $e($f['type']) ?>" data-question-type>

    <div class="block-title" id="type-label">Question type</div>
    <div class="segmented segmented-strong type-picker" role="radiogroup" aria-labelledby="type-label">
        <?php foreach ($types as $value => [$short, $long]): ?>
            <button class="button <?= $f['type'] === $value ? 'button-active' : '' ?>" type="button"
                    role="radio" aria-checked="<?= $f['type'] === $value ? 'true' : 'false' ?>"
                    aria-label="<?= $e($long) ?>" data-type-option="<?= $e($value) ?>"><?= $e($short) ?></button>
        <?php endforeach; ?>
        <span class="segmented-highlight"></span>
    </div>

    <div class="block-title" id="prompt-label">Question</div>
    <div class="text-editor text-editor-resizable rich-text-editor" data-rich-text-editor
         data-rich-text-key="prompt_html" role="group" aria-labelledby="prompt-label">
        <div class="text-editor-content" contenteditable="true"></div>
    </div>
    <textarea data-rich-text-source="prompt_html" hidden><?= $e($f['prompt']) ?></textarea>
    <input type="hidden" name="prompt_html" data-rich-text-target="prompt_html">

    <div data-question-fields="choice">
        <div class="block-title" id="choices-label">Answer choices</div>
        <p class="form-hint" data-choice-hint>
            Tick the correct answer. Blank rows are ignored.
        </p>
        <div class="list list-strong list-outline-ios list-dividers-ios inset" role="group" aria-labelledby="choices-label">
            <ul data-choice-list>
                <?php foreach ($f['choices'] as $i => $choiceText): ?>
                    <li class="item-content item-input choice-row" data-choice-row>
                        <?php $multi = $f['type'] === 'multiple_select'; ?>
                        <label class="choice-correct <?= $multi ? 'checkbox' : 'radio' ?>" data-choice-mark>
                            <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                                   name="choice_correct[]" value="<?= $i ?>"
                                   <?= in_array($i, $f['correct'], true) ? 'checked' : '' ?>
                                   aria-label="Choice <?= $i + 1 ?> is correct" data-choice-correct>
                            <i class="<?= $multi ? 'icon-checkbox' : 'icon-radio' ?>"></i>
                        </label>
                        <div class="item-inner">
                            <div class="item-input-wrap">
                                <input type="text" name="choice_text[]" maxlength="300" autocomplete="off"
                                       value="<?= $e($choiceText) ?>" placeholder="Choice <?= $i + 1 ?>"
                                       aria-label="Choice <?= $i + 1 ?> text" data-choice-text>
                            </div>
                        </div>
                        <button class="link icon-only choice-remove" type="button" data-choice-remove
                                aria-label="Remove choice <?= $i + 1 ?>" title="Remove choice"><?= View::icon('close') ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="block">
            <button class="button button-tonal" type="button" data-choice-add>
                <?= View::icon('plus', 18) ?><span>Add choice</span>
            </button>
        </div>
    </div>

    <div data-question-fields="true_false">
        <div class="block-title" id="tf-label">Correct answer</div>
        <div class="list list-strong list-outline-ios list-dividers-ios inset" role="radiogroup" aria-labelledby="tf-label">
            <ul>
                <?php foreach (['true' => 'True', 'false' => 'False'] as $value => $label): ?>
                    <li>
                        <label class="item-radio item-content">
                            <input type="radio" name="true_false_correct" value="<?= $value ?>" <?= $f['tf'] === $value ? 'checked' : '' ?>>
                            <i class="icon-radio"></i>
                            <div class="item-inner"><div class="item-title"><?= $label ?></div></div>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div data-question-fields="short_text">
        <div class="block-title">Accepted answers</div>
        <div class="list list-strong list-outline-ios list-dividers-ios inset">
            <ul>
                <li class="item-content item-input">
                    <div class="item-inner">
                        <div class="item-input-wrap">
                            <textarea name="accepted_answers" rows="4" aria-label="Accepted answers, one per line"
                                      placeholder="One accepted answer per line"><?= $e($f['accepted']) ?></textarea>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>

    <div data-question-fields="numeric">
        <div class="block-title">Numeric answer</div>
        <div class="list list-strong list-outline-ios list-dividers-ios inset">
            <ul>
                <li class="item-content item-input">
                    <div class="item-inner">
                        <label class="item-title item-label" for="numeric-answer">Correct value</label>
                        <div class="item-input-wrap">
                            <input id="numeric-answer" name="numeric_answer" type="number" step="any" value="<?= $e($f['numeric']) ?>">
                        </div>
                    </div>
                </li>
                <li class="item-content item-input">
                    <div class="item-inner">
                        <label class="item-title item-label" for="numeric-tolerance">Tolerance (plus or minus)</label>
                        <div class="item-input-wrap">
                            <input id="numeric-tolerance" name="numeric_tolerance" type="number" min="0" step="any" value="<?= $e($f['tolerance']) ?>">
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>

    <div class="block-title">Points</div>
    <div class="list list-strong list-outline-ios list-dividers-ios inset">
        <ul>
            <li class="item-content item-input">
                <div class="item-inner">
                    <label class="item-title item-label" for="points">Points for a correct answer</label>
                    <div class="item-input-wrap">
                        <input id="points" name="points" type="number" min="0.1" max="100" step="0.1" value="<?= $e($f['points']) ?>" required>
                    </div>
                </div>
            </li>
        </ul>
    </div>

    <div class="block-title" id="explanation-label">Explanation (optional)</div>
    <div class="text-editor text-editor-resizable rich-text-editor" data-rich-text-editor
         data-rich-text-key="explanation_html" role="group" aria-labelledby="explanation-label">
        <div class="text-editor-content" contenteditable="true"></div>
    </div>
    <textarea data-rich-text-source="explanation_html" hidden><?= $e($f['explanation']) ?></textarea>
    <input type="hidden" name="explanation_html" data-rich-text-target="explanation_html">

    <div class="editor-actions">
        <a class="button button-large external" href="/q/<?= $e(rawurlencode($slug)) ?>">Cancel</a>
        <button class="button button-fill button-large" type="submit"><?= $isNew ? 'Add question' : 'Save question' ?></button>
    </div>
</form>
<?php
View::end('', ['/assets/js/rich-text.js', '/assets/js/questions.js']);
