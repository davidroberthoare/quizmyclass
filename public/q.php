<?php

declare(strict_types=1);

use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\QuestionRepository;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
Session::start($runtime['config']);

$rooms = new RoomRepository($runtime['database']);
$questions = new QuestionRepository($runtime['database']);
$user = Session::user(new UserRepository($runtime['database']));

try {
    $slug = (string) ($_GET['slug'] ?? '');
    $room = $rooms->findBySlug($slug);
    if ($room === null) {
        http_response_code(404);
        $pageTitle = 'Quiz not found';
        $role = null;
        $roomQuestions = [];
    } else {
        $pageTitle = (string) $room['name'];
        $role = $user === null ? null : $rooms->roleForUser(
            (int) $room['id'],
            (int) $user['id'],
        );
        // Outside editing mode nobody sees the question list; viewers get quiz mode instead.
        $roomQuestions = ($user === null || $room['state'] !== 'editing')
            ? []
            : $questions->listForRoom((int) $room['id'], (int) $user['id'], $role === 'owner');
    }
} catch (Throwable $exception) {
    http_response_code(400);
    $pageTitle = 'Invalid quiz link';
    $room = null;
    $role = null;
    $roomQuestions = [];
    Session::set('flash_error', Http::safeMessage($exception));
}

$e = View::e(...);
$google = new GoogleClient($runtime['config']);
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
$statusColor = ['draft' => 'orange', 'approved' => 'green', 'rejected' => 'red', 'hidden' => 'gray'];
$stateColor = ['editing' => 'orange', 'active' => 'green', 'closed' => 'gray'];
$isOwner = $role === 'owner';
$editing = $room !== null && $room['state'] === 'editing';
$counts = array_fill_keys(array_keys($statusColor), 0);
foreach ($roomQuestions as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}
$timezone = (string) $runtime['config']['app']['timezone'];
$quizRepo = new QuizRepository($runtime['database'], $timezone);
$attemptRepo = new AttemptRepository($runtime['database']);
$revision = $room === null ? null : $quizRepo->latestRevision((int) $room['id']);
$draftSettings = $room === null ? [] : QuizRepository::settingsFor($room);
$problems = ($room !== null && $isOwner && $editing)
    ? $quizRepo->activationProblems((int) $room['id'], $draftSettings)
    : [];
$roomUrl = $room === null ? '' : '/q/' . rawurlencode((string) $room['slug']);
$csrf = Csrf::token();

View::begin($runtime, [
    'title' => $pageTitle,
    'user' => $user,
    'back' => $user === null ? null : '/',
    'backLabel' => 'Quizzes',
    'heading' => 'Quiz',
    'wide' => true,
]);
?>
<div
    data-room-page
    <?php if ($room !== null): ?>data-room-slug="<?= $e($room['slug']) ?>" data-room-csrf="<?= $e($csrf) ?>"<?php endif; ?>
>
<?php if ($room === null): ?>
    <div class="empty-state">
        <?= View::icon('quiz', 40) ?>
        <h2><?= $e($pageTitle) ?></h2>
        <p>Check the quiz link and try again.</p>
        <a class="button button-fill external" href="/">Go home</a>
    </div>
<?php else: ?>
    <div class="page-header">
        <div>
            <h1><?= $e($room['name']) ?></h1>
            <div class="meta">
                <?= View::chip(ucfirst((string) $room['state']), $stateColor[$room['state']] ?? 'gray') ?>
                <?php if ($role !== null && $user !== null): ?>
                    <?= View::chip($isOwner ? 'Teacher' : 'Student', $isOwner ? 'blue' : 'teal') ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($user !== null && $isOwner): ?>
            <a class="link icon-only popup-open" href="#" data-popup="#room-settings"
               aria-label="Quiz settings" title="Quiz settings"><?= View::icon('settings', 24) ?></a>
        <?php endif; ?>
    </div>

    <?php if ($user === null): ?>
        <div class="block block-strong inset">
            <p>Sign in with Google to see this quiz's questions and add your own.</p>
            <?php if ($google->isConfigured()): ?>
                <a class="button button-fill button-large external" href="/auth/google.php">Sign in with Google</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($isOwner): ?>
            <div class="share-row">
                <code id="room-link" data-path="<?= $e($roomUrl) ?>"><?= $e($roomUrl) ?></code>
                <button class="button button-small button-tonal" type="button" data-copy-link>
                    <?= View::icon('copy', 18) ?><span>Copy link</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($editing && $isOwner): ?>
            <div class="block block-strong inset activation-card">
                <h2>Publish this quiz</h2>
                <?php if ($problems === []): ?>
                    <p>
                        <?= $counts['approved'] ?> approved question<?= $counts['approved'] === 1 ? '' : 's' ?> will be frozen
                        into the quiz students take. Students can add or change nothing after this.
                    </p>
                <?php else: ?>
                    <ul class="problem-list">
                        <?php foreach ($problems as $problem): ?><li><?= $e($problem) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form action="/rooms/lifecycle.php" method="post"
                      data-confirm="Activate this quiz? The approved questions and settings are frozen, and students can start taking it.">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                    <input type="hidden" name="action" value="activate">
                    <button class="button button-fill" type="submit" <?= $problems === [] ? '' : 'disabled' ?>>Activate quiz</button>
                    <a class="button popup-open" href="#" data-popup="#room-settings">Quiz settings</a>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($editing): ?>

        <div class="stats" role="group" aria-label="Question summary">
            <div class="stat"><strong><?= count($roomQuestions) ?></strong><span>of <?= (int) $room['max_questions'] ?> questions</span></div>
            <div class="stat"><strong><?= $counts['approved'] ?></strong><span>Approved</span></div>
            <div class="stat"><strong><?= $counts['draft'] ?></strong><span>Draft</span></div>
            <?php if ($isOwner): ?>
                <div class="stat"><strong><?= $counts['rejected'] + $counts['hidden'] ?></strong><span>Rejected / hidden</span></div>
            <?php endif; ?>
        </div>

        <?php if ($isOwner && $editing && $roomQuestions !== []): ?>
            <div class="toolbar-actions">
                <form action="/questions/moderate.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                    <input type="hidden" name="action" value="approve_all">
                    <button class="button button-small button-tonal color-green" type="submit">
                        <?= View::icon('check', 18) ?><span>Approve all drafts</span>
                    </button>
                </form>
                <form action="/questions/moderate.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                    <input type="hidden" name="action" value="unapprove_all">
                    <button class="button button-small button-tonal color-orange" type="submit">
                        <?= View::icon('undo', 18) ?><span>Return approved to draft</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="section-heading"><h2>Questions</h2></div>

        <?php if ($roomQuestions === []): ?>
            <div class="empty-state">
                <?= View::icon('quiz', 40) ?>
                <h2>No questions yet</h2>
                <p>Be the first to add one — everyone in your class can read it.</p>
                <?php if ($editing): ?>
                    <a class="button button-fill external" href="/questions/editor.php?room=<?= $e(rawurlencode((string) $room['slug'])) ?>">Add a question</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div
                class="list media-list list-strong list-outline-ios list-dividers-ios inset <?= $isOwner && $editing ? 'sortable sortable-opposite' : '' ?>"
                data-question-sortable
                data-sortable-owner="<?= $isOwner && $editing ? '1' : '0' ?>"
            >
                <ul>
                    <?php $lastIndex = count($roomQuestions) - 1; ?>
                    <?php foreach ($roomQuestions as $index => $question): ?>
                        <?php
                        $plainPrompt = mb_strimwidth(
                            html_entity_decode(trim(strip_tags((string) $question['prompt_html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                            0, 160, '…', 'UTF-8',
                        );
                        $correct = $answerLabel($question);
                        $editUrl = '/questions/editor.php?room=' . rawurlencode((string) $room['slug']) . '&id=' . (int) $question['id'];
                        $label = $plainPrompt !== '' ? $plainPrompt : 'Question ' . ($index + 1);
                        ?>
                        <li data-question-id="<?= (int) $question['id'] ?>" <?= $isOwner && $editing ? '' : 'class="no-sorting"' ?>>
                            <div class="item-content">
                                <div class="item-inner">
                                    <div class="item-title-row">
                                        <div class="item-title question-prompt"><?= $e($label) ?></div>
                                        <?php if ($editing && ($question['can_edit'] || $isOwner)): ?>
                                            <div class="item-after row-actions">
                                                <?php if ($question['can_edit']): ?>
                                                    <a class="link icon-only external" href="<?= $e($editUrl) ?>"
                                                       aria-label="Edit: <?= $e($label) ?>" title="Edit"><?= View::icon('edit') ?></a>
                                                <?php endif; ?>
                                                <?php if ($isOwner): ?>
                                                    <button class="link icon-only" type="button" data-question-menu
                                                            data-question-id="<?= (int) $question['id'] ?>"
                                                            data-status="<?= $e($question['status']) ?>"
                                                            data-first="<?= $index === 0 ? '1' : '0' ?>"
                                                            data-last="<?= $index === $lastIndex ? '1' : '0' ?>"
                                                            aria-label="Review options: <?= $e($label) ?>"
                                                            title="Review options"><?= View::icon('more') ?></button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-subtitle">
                                        <?= $e($question['author_name']) ?>
                                        <?php if ($correct !== ''): ?>
                                            · Answer: <strong><?= $e($correct) ?></strong>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-text question-meta">
                                        <?= View::chip(ucfirst((string) $question['status']), $statusColor[$question['status']] ?? 'gray') ?>
                                        <span><?= $e(ucfirst(str_replace('_', ' ', (string) $question['type']))) ?> · <?= $e($question['points']) ?> pt<?= (float) $question['points'] === 1.0 ? '' : 's' ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php if ($isOwner && $editing): ?>
                                <div class="sortable-handler" aria-label="Drag to reorder"></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isOwner && $editing): ?>
            <form action="/questions/moderate.php" method="post" id="moderate-form" hidden>
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                <input type="hidden" name="question_id" value="">
                <input type="hidden" name="status" value="">
            </form>
            <form action="/questions/move.php" method="post" id="move-form" hidden>
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                <input type="hidden" name="question_id" value="">
                <input type="hidden" name="direction" value="">
            </form>
        <?php endif; ?>
        <?php else: ?>
            <?php require dirname(__DIR__) . '/app/Views/quiz_mode.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
$fab = '';
if ($room !== null && $user !== null && $editing) {
    $fab = '<div class="fab fab-extended fab-right-bottom"><a class="external" href="/questions/editor.php?room='
        . $e(rawurlencode((string) $room['slug'])) . '">' . View::icon('plus', 24)
        . '<div class="fab-text">Add question</div></a></div>';
}
$modals = '';
if ($room !== null && $user !== null && $isOwner) {
    ob_start(); ?>
    <div class="popup" id="room-settings" aria-label="Quiz settings">
        <div class="view">
            <div class="page">
                <div class="navbar">
                    <div class="navbar-bg"></div>
                    <div class="navbar-inner">
                        <div class="title">Quiz settings</div>
                        <div class="right"><a class="link popup-close" href="#">Close</a></div>
                    </div>
                </div>
                <div class="page-content">
                    <form action="/rooms/settings.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                        <?php if (!$editing): ?>
                            <div class="block block-strong inset">Settings are frozen while the quiz is live or closed. Choose “Edit questions” to change them.</div>
                        <?php else: ?>
                        <div class="block-title">Name and link</div>
                        <div class="list list-strong list-outline-ios list-dividers-ios inset">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="quiz-name">Quiz name</label>
                                        <div class="item-input-wrap">
                                            <input id="quiz-name" name="name" type="text" maxlength="120" value="<?= $e($room['name']) ?>" required>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="quiz-slug">Quiz link</label>
                                        <div class="item-input-wrap">
                                            <input id="quiz-slug" name="slug" type="text" minlength="3" maxlength="60"
                                                   pattern="[a-z0-9]+(?:-[a-z0-9]+)*" autocomplete="off" spellcheck="false"
                                                   value="<?= $e($room['slug']) ?>" required aria-describedby="slug-warning">
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <p class="form-hint" id="slug-warning">Changing the link breaks any copy you've already shared. Share the new one after saving.</p>

                        <div class="block-title">Collaboration</div>
                        <div class="list list-strong list-outline-ios list-dividers-ios inset">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="max-questions">Maximum quiz questions</label>
                                        <div class="item-input-wrap">
                                            <input id="max-questions" name="max_questions" type="number" min="1" max="500" value="<?= (int) $room['max_questions'] ?>" required>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="student-cap">Questions per student</label>
                                        <div class="item-input-wrap">
                                            <input id="student-cap" name="student_question_cap" type="number" min="1" max="100" value="<?= (int) $room['student_question_cap'] ?>" required>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="min-approved">Minimum approved to activate</label>
                                        <div class="item-input-wrap">
                                            <input id="min-approved" name="min_approved" type="number" min="1" max="500" value="<?= (int) $draftSettings['min_approved'] ?>" required>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="block-title">Availability</div>
                        <div class="list list-strong list-outline-ios list-dividers-ios inset">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="open-at">Opens (optional)</label>
                                        <div class="item-input-wrap">
                                            <input id="open-at" name="open_at" type="datetime-local" value="<?= $e(QuizSettings::toLocal($draftSettings['open_at'], $timezone)) ?>">
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="close-at">Closes (optional)</label>
                                        <div class="item-input-wrap">
                                            <input id="close-at" name="close_at" type="datetime-local" value="<?= $e(QuizSettings::toLocal($draftSettings['close_at'], $timezone)) ?>">
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="duration">Time limit in minutes (blank for none)</label>
                                        <div class="item-input-wrap">
                                            <input id="duration" name="duration_minutes" type="number" min="1" max="600" inputmode="numeric" value="<?= $e((string) ($draftSettings['duration_minutes'] ?? '')) ?>">
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="max-attempts">Attempts per student (0 = unlimited)</label>
                                        <div class="item-input-wrap">
                                            <input id="max-attempts" name="max_attempts" type="number" min="0" max="100" value="<?= (int) $draftSettings['max_attempts'] ?>" required>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <p class="form-hint">Times are in <?= $e($timezone) ?>. The best score across attempts is the student's result.</p>

                        <div class="block-title">Randomization</div>
                        <div class="list list-strong list-outline-ios list-dividers-ios inset">
                            <ul>
                                <li class="item-content">
                                    <div class="item-inner">
                                        <div class="item-title">Shuffle question order</div>
                                        <div class="item-after">
                                            <label class="toggle"><input type="checkbox" name="shuffle_questions" value="1" aria-label="Shuffle question order" <?= !empty($draftSettings['shuffle_questions']) ? 'checked' : '' ?>><i class="toggle-icon"></i></label>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content">
                                    <div class="item-inner">
                                        <div class="item-title">Shuffle answer choices</div>
                                        <div class="item-after">
                                            <label class="toggle"><input type="checkbox" name="shuffle_choices" value="1" aria-label="Shuffle answer choices" <?= !empty($draftSettings['shuffle_choices']) ? 'checked' : '' ?>><i class="toggle-icon"></i></label>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="block-title">Feedback for students</div>
                        <div class="list list-strong list-outline-ios list-dividers-ios inset">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="feedback-when">Show feedback</label>
                                        <div class="item-input-wrap">
                                            <select id="feedback-when" name="feedback_when"><option value="immediate" <?= $draftSettings['feedback_when'] === 'immediate' ? 'selected' : '' ?>>Right after submitting</option><option value="after_close" <?= $draftSettings['feedback_when'] === 'after_close' ? 'selected' : '' ?>>After the quiz closes</option><option value="never" <?= $draftSettings['feedback_when'] === 'never' ? 'selected' : '' ?>>Never</option></select>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content">
                                    <div class="item-inner">
                                        <div class="item-title">Show score</div>
                                        <div class="item-after">
                                            <label class="toggle"><input type="checkbox" name="show_score" value="1" aria-label="Show score" <?= !empty($draftSettings['show_score']) ? 'checked' : '' ?>><i class="toggle-icon"></i></label>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content">
                                    <div class="item-inner">
                                        <div class="item-title">Show which answers were right or wrong</div>
                                        <div class="item-after">
                                            <label class="toggle"><input type="checkbox" name="show_correctness" value="1" aria-label="Show which answers were right or wrong" <?= !empty($draftSettings['show_correctness']) ? 'checked' : '' ?>><i class="toggle-icon"></i></label>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content">
                                    <div class="item-inner">
                                        <div class="item-title">Show correct answers</div>
                                        <div class="item-after">
                                            <label class="toggle"><input type="checkbox" name="show_correct_answers" value="1" aria-label="Show correct answers" <?= !empty($draftSettings['show_correct_answers']) ? 'checked' : '' ?>><i class="toggle-icon"></i></label>
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content">
                                    <div class="item-inner">
                                        <div class="item-title">Show explanations</div>
                                        <div class="item-after">
                                            <label class="toggle"><input type="checkbox" name="show_explanations" value="1" aria-label="Show explanations" <?= !empty($draftSettings['show_explanations']) ? 'checked' : '' ?>><i class="toggle-icon"></i></label>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <div class="block">
                            <button class="button button-fill button-large" type="submit">Save settings</button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    $modals = (string) ob_get_clean();
}
View::end($modals, ['/assets/js/room.js'], $fab);
