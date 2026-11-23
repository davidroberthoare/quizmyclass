<?php
/**
 * Quiz mode: what everyone sees once a quiz is no longer in editing mode.
 * Included by public/q.php; expects $room, $revision, $user, $isOwner, $e, $csrf, $timezone,
 * $attemptRepo and $quizRepo from the including scope.
 */

use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\ResultsRepository;
use QuizMyClass\Support\View;

$now = QuizRepository::now();
$when = static fn (?string $utc): string => QuizSettings::toLocal($utc, $timezone, 'M j, g:i a');

if ($revision === null):
    ?>
    <div class="empty-state">
        <?= View::icon('quiz', 40) ?>
        <h2>Not published yet</h2>
        <p>This quiz has no published questions.</p>
    </div>
    <?php
    return;
endif;

$quizSettings = QuizRepository::revisionSettings($revision);
$quizQuestions = QuizRepository::revisionQuestions($revision);
$totalPoints = array_sum(array_map(static fn (array $q): float => (float) $q['points'], $quizQuestions));
$availability = $attemptRepo->availability($room, $revision, (int) $user['id']);
$feedback = QuizSettings::feedback($quizSettings, (string) $room['state'], $now);
$myAttempts = $attemptRepo->forUser((int) $room['id'], (int) $user['id']);
$maxAttempts = (int) $quizSettings['max_attempts'];
$pts = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
?>
<?php if ($isOwner): ?>
    <?php
    $summary = (new ResultsRepository($runtime['database']))->summary((int) $revision['id'], (int) $user['id']);
    ?>
    <div class="stats" role="group" aria-label="Results summary">
        <div class="stat"><strong><?= $summary['students'] ?></strong><span>Students</span></div>
        <div class="stat"><strong><?= $summary['attempts'] ?></strong><span>Submitted attempts</span></div>
        <div class="stat"><strong><?= $summary['in_progress'] ?></strong><span>In progress</span></div>
        <div class="stat"><strong><?= $summary['average_best'] === null ? '–' : round($summary['average_best']) . '%' ?></strong><span>Average best score</span></div>
    </div>
    <div class="toolbar-actions">
        <a class="button button-small button-fill external" href="/results.php?room=<?= $e(rawurlencode((string) $room['slug'])) ?>">
            <?= View::icon('quiz', 18) ?><span>View results</span>
        </a>
        <?php if ($room['state'] === 'active'): ?>
            <form action="/rooms/lifecycle.php" method="post"
                  data-confirm="Close this quiz? Anyone mid-attempt is submitted as they stand, and no new attempts can start.">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
                <input type="hidden" name="action" value="close">
                <button class="button button-small button-tonal color-orange" type="submit"><span>Close quiz</span></button>
            </form>
        <?php endif; ?>
        <form action="/rooms/lifecycle.php" method="post"
              data-confirm="Go back to editing? The live quiz stops. Existing results are kept, and activating again creates a new version.">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
            <input type="hidden" name="action" value="reopen">
            <button class="button button-small button-tonal" type="submit"><?= View::icon('edit', 18) ?><span>Edit questions</span></button>
        </form>
    </div>
<?php endif; ?>

<div class="section-heading">
    <h2><?= $isOwner ? 'Student view' : 'Take the quiz' ?></h2>
</div>
<div class="list list-strong list-outline-ios list-dividers-ios inset">
    <ul>
        <li class="item-content"><div class="item-inner"><div class="item-title">Questions</div><div class="item-after"><?= count($quizQuestions) ?> · <?= $e($pts($totalPoints)) ?> points</div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Time limit</div><div class="item-after"><?= $quizSettings['duration_minutes'] ? (int) $quizSettings['duration_minutes'] . ' minutes' : 'None' ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Attempts</div><div class="item-after"><?= $maxAttempts === 0 ? 'Unlimited' : $maxAttempts ?> · best score counts</div></div></li>
        <?php if (!empty($quizSettings['open_at'])): ?>
            <li class="item-content"><div class="item-inner"><div class="item-title">Opens</div><div class="item-after"><?= $e($when($quizSettings['open_at'])) ?></div></div></li>
        <?php endif; ?>
        <?php if (!empty($quizSettings['close_at'])): ?>
            <li class="item-content"><div class="item-inner"><div class="item-title">Closes</div><div class="item-after"><?= $e($when($quizSettings['close_at'])) ?></div></div></li>
        <?php endif; ?>
    </ul>
</div>

<div class="block">
    <?php if (in_array($availability['status'], ['ready', 'resume'], true)): ?>
        <form action="/quiz/start.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
            <button class="button button-fill button-large" type="submit">
                <?= $availability['status'] === 'resume' ? 'Resume your attempt' : ($myAttempts === [] ? 'Start quiz' : 'Start another attempt') ?>
            </button>
        </form>
        <?php if ($availability['max'] > 0): ?>
            <p class="form-hint">Attempt <?= $availability['used'] + ($availability['status'] === 'resume' ? 0 : 1) ?> of <?= $availability['max'] ?>.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="notice-inline"><?= View::chip($availability['status'] === 'not_open' ? 'Not open yet' : 'Unavailable', 'gray') ?> <?= $e($availability['message']) ?></p>
    <?php endif; ?>
</div>

<?php if ($myAttempts !== []): ?>
    <div class="section-heading"><h2>Your attempts</h2></div>
    <div class="list media-list list-strong list-outline-ios list-dividers-ios inset">
        <ul>
            <?php foreach ($myAttempts as $attempt): ?>
                <li>
                    <a class="item-link item-content external" href="/quiz/attempt.php?id=<?= (int) $attempt['id'] ?>">
                        <div class="item-inner">
                            <div class="item-title-row">
                                <div class="item-title">Attempt <?= (int) $attempt['attempt_number'] ?></div>
                                <div class="item-after">
                                    <?php if ($attempt['status'] === 'in_progress'): ?>
                                        <?= View::chip('In progress', 'orange') ?>
                                    <?php elseif ($feedback['score']): ?>
                                        <?= $e($pts((float) $attempt['score'])) ?> / <?= $e($pts((float) $attempt['max_score'])) ?>
                                    <?php else: ?>
                                        <?= View::chip('Submitted', 'green') ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="item-subtitle"><?= $e($when($attempt['submitted_at'] ?? $attempt['started_at'])) ?><?= $attempt['status'] === 'expired' ? ' · time ran out' : '' ?></div>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if (!$feedback['score'] && $quizSettings['feedback_when'] === 'after_close'): ?>
        <p class="form-hint">Scores appear after the quiz closes.</p>
    <?php elseif (!$feedback['score'] && $quizSettings['feedback_when'] === 'never'): ?>
        <p class="form-hint">Your teacher has chosen not to show scores.</p>
    <?php endif; ?>
<?php endif; ?>
