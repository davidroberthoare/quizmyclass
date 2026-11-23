<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Grader;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = Endpoint::pageUser($runtime);

$attempts = new AttemptRepository($runtime['database']);
try {
    $attempt = $attempts->findOwned((int) ($_GET['id'] ?? 0), (int) $user['id']);
    $room = (new RoomRepository($runtime['database']))->findById((int) $attempt['room_id']);
    $revision = (new QuizRepository($runtime['database']))->revision((int) $attempt['revision_id']);
    if ($room === null || $revision === null) {
        throw new RuntimeException('Quiz not found.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
    Http::redirect('/');
}

$e = View::e(...);
$settings = QuizRepository::revisionSettings($revision);
$snapshot = QuizRepository::revisionQuestions($revision);
$presented = Grader::present($snapshot, $settings, (int) $attempt['seed']);
$backUrl = '/q/' . rawurlencode((string) $room['slug']);
$inProgress = $attempt['status'] === 'in_progress';
$pts = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';

View::begin($runtime, [
    'title' => $room['name'],
    'heading' => 'Attempt ' . (int) $attempt['attempt_number'],
    'user' => $user,
    'back' => $backUrl,
    'backLabel' => 'Quiz',
    'wide' => true,
]);

if ($inProgress):
    $saved = $attempts->answers((int) $attempt['id']);
    $remaining = empty($attempt['deadline_at']) ? null : max(0, strtotime((string) $attempt['deadline_at']) - time());
    ?>
    <form action="/quiz/submit.php" method="post" id="attempt-form"
          data-attempt-id="<?= (int) $attempt['id'] ?>"
          data-csrf="<?= $e(Csrf::token()) ?>"
          <?= $remaining === null ? '' : 'data-remaining="' . (int) $remaining . '"' ?>>
        <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
        <input type="hidden" name="attempt_id" value="<?= (int) $attempt['id'] ?>">

        <div class="attempt-bar">
            <div>
                <h1><?= $e($room['name']) ?></h1>
                <div class="meta"><span data-answered-count>0</span> of <?= count($presented) ?> answered ·
                    <span data-save-status role="status" aria-live="polite">Answers save as you go</span></div>
            </div>
            <?php if ($remaining !== null): ?>
                <div class="timer" role="timer" aria-label="Time remaining" data-timer>--:--</div>
            <?php endif; ?>
        </div>

        <?php foreach ($presented as $number => $question): ?>
            <?php
            $id = (int) $question['id'];
            $value = $saved[$id] ?? null;
            $name = 'answer[' . $id . ']';
            ?>
            <div class="card question-card" data-question="<?= $id ?>" data-type="<?= $e($question['type']) ?>">
                <div class="card-content card-content-padding">
                    <fieldset class="question-fieldset">
                        <legend>
                            <span class="question-number">Question <?= $number + 1 ?> of <?= count($presented) ?></span>
                            <span class="question-points"><?= $e($pts((float) $question['points'])) ?> pt<?= (float) $question['points'] === 1.0 ? '' : 's' ?></span>
                        </legend>
                        <div class="question-prompt-body"><?= $question['prompt_html'] ?></div>

                        <?php if (in_array($question['type'], ['multiple_choice', 'multiple_select'], true)): ?>
                            <?php $multi = $question['type'] === 'multiple_select'; ?>
                            <?php if ($multi): ?><p class="form-hint">Select all that apply.</p><?php endif; ?>
                            <div class="list list-strong list-outline-ios list-dividers-ios">
                                <ul>
                                    <?php foreach ($question['choice_order'] as $index): ?>
                                        <?php
                                        $checked = $multi
                                            ? is_array($value) && in_array($index, $value, true)
                                            : $value === $index;
                                        ?>
                                        <li>
                                            <label class="item-<?= $multi ? 'checkbox' : 'radio' ?> item-content">
                                                <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                                                       name="<?= $e($name . ($multi ? '[]' : '')) ?>"
                                                       value="<?= (int) $index ?>" <?= $checked ? 'checked' : '' ?>>
                                                <i class="icon-<?= $multi ? 'checkbox' : 'radio' ?>"></i>
                                                <div class="item-inner"><div class="item-title"><?= $e($question['choices'][$index]) ?></div></div>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php elseif ($question['type'] === 'true_false'): ?>
                            <div class="list list-strong list-outline-ios list-dividers-ios">
                                <ul>
                                    <?php foreach (['true' => 'True', 'false' => 'False'] as $v => $label): ?>
                                        <li>
                                            <label class="item-radio item-content">
                                                <input type="radio" name="<?= $e($name) ?>" value="<?= $v ?>" <?= $value === $v ? 'checked' : '' ?>>
                                                <i class="icon-radio"></i>
                                                <div class="item-inner"><div class="item-title"><?= $label ?></div></div>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="list list-strong list-outline-ios list-dividers-ios">
                                <ul>
                                    <li class="item-content item-input">
                                        <div class="item-inner">
                                            <div class="item-input-wrap">
                                                <input type="<?= $question['type'] === 'numeric' ? 'number' : 'text' ?>"
                                                       <?= $question['type'] === 'numeric' ? 'step="any" inputmode="decimal"' : 'maxlength="500"' ?>
                                                       name="<?= $e($name) ?>" autocomplete="off"
                                                       aria-label="Your answer to question <?= $number + 1 ?>"
                                                       placeholder="<?= $question['type'] === 'numeric' ? 'Enter a number' : 'Type your answer' ?>"
                                                       value="<?= $value === null ? '' : $e((string) $value) ?>">
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="editor-actions">
            <button class="button button-fill button-large" type="submit">Submit quiz</button>
        </div>
    </form>
    <?php
    View::end('', ['/assets/js/attempt.js']);
    return;
endif;

// ---- Finished attempt: score and whatever feedback the quiz allows ----
$flags = QuizSettings::feedback($settings, (string) $room['state'], QuizRepository::now());
$reviewAnswers = $attempts->gradedAnswers((int) $attempt['id']);
?>
<div class="page-header">
    <div>
        <h1><?= $e($room['name']) ?></h1>
        <div class="meta">
            Attempt <?= (int) $attempt['attempt_number'] ?>
            <?= $attempt['status'] === 'expired' ? '· time ran out' : '' ?>
        </div>
    </div>
</div>
<?php if ($flags['score']): ?>
    <div class="block block-strong inset score-card">
        <div class="score-number"><?= $e($pts((float) $attempt['score'])) ?> <small>/ <?= $e($pts((float) $attempt['max_score'])) ?></small></div>
        <?php if ((float) $attempt['max_score'] > 0): ?>
            <div class="score-percent"><?= (int) round(100 * (float) $attempt['score'] / (float) $attempt['max_score']) ?>%</div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="block block-strong inset">
        <?= View::chip('Submitted', 'green') ?>
        <?= $settings['feedback_when'] === 'after_close'
            ? 'Your answers are in. Results appear after the quiz closes.'
            : 'Your answers are in.' ?>
    </div>
<?php endif; ?>
<a class="button button-large external review-back" href="<?= $e($backUrl) ?>">Back to quiz</a>
<?php
if ($flags['correctness'] || $flags['correct_answers'] || $flags['explanations']) {
    $reviewQuestions = $presented;
    // Re-attach answer keys and explanations from the snapshot; presented copies deliberately omit them.
    $byId = [];
    foreach ($snapshot as $q) {
        $byId[(int) $q['id']] = $q;
    }
    foreach ($reviewQuestions as $i => $q) {
        $reviewQuestions[$i] = $byId[(int) $q['id']];
    }
    $reviewFlags = $flags;
    require dirname(__DIR__, 2) . '/app/Views/review.php';
}
View::end();
