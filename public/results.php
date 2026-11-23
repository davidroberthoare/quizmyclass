<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\ResultsRepository;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
$user = Endpoint::pageUser($runtime);
$db = $runtime['database'];
$timezone = (string) $runtime['config']['app']['timezone'];

$room = (new RoomRepository($db))->findBySlug((string) ($_GET['room'] ?? ''));
if ($room === null || (int) $room['owner_id'] !== (int) $user['id']) {
    Session::set('flash_error', 'Only the quiz owner can view results.');
    Http::redirect('/');
}

$quiz = new QuizRepository($db, $timezone);
$results = new ResultsRepository($db);
$attemptsRepo = new AttemptRepository($db);
$revisions = $results->revisions((int) $room['id']);
$slugUrl = rawurlencode((string) $room['slug']);
$backUrl = '/q/' . $slugUrl;
$e = View::e(...);

$revision = null;
foreach ($revisions as $candidate) {
    if (!isset($_GET['rev']) || (int) $_GET['rev'] === (int) $candidate['id']) {
        $revision = $quiz->revision((int) $candidate['id']);
        break;
    }
}

$when = static fn (?string $utc): string => QuizSettings::toLocal($utc, $timezone, 'M j, g:i a');
$pts = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
$csrf = Csrf::token();

View::begin($runtime, [
    'title' => 'Results · ' . $room['name'],
    'heading' => 'Results',
    'user' => $user,
    'back' => $backUrl,
    'backLabel' => 'Quiz',
    'wide' => true,
]);
?>
<div class="page-header">
    <div>
        <h1><?= $e($room['name']) ?></h1>
        <div class="meta">Results</div>
    </div>
    <button class="button button-small button-tonal no-print" type="button" onclick="window.print()">Print</button>
</div>
<?php
if ($revision === null):
    ?>
    <div class="empty-state">
        <?= View::icon('quiz', 40) ?>
        <h2>No results yet</h2>
        <p>Results appear here once the quiz has been activated and students submit attempts.</p>
    </div>
    <?php
    View::end();
    return;
endif;

$revisionId = (int) $revision['id'];
$questions = QuizRepository::revisionQuestions($revision);
$revisionUrl = '/results.php?room=' . $slugUrl . '&rev=' . $revisionId;
?>
<?php if (count($revisions) > 1): ?>
    <div class="toolbar-actions no-print" role="navigation" aria-label="Quiz versions">
        <?php foreach ($revisions as $candidate): ?>
            <a class="button button-small <?= (int) $candidate['id'] === $revisionId ? 'button-fill' : 'button-tonal' ?> external"
               href="/results.php?room=<?= $slugUrl ?>&amp;rev=<?= (int) $candidate['id'] ?>">
                Version <?= (int) $candidate['revision_number'] ?> · <?= (int) $candidate['attempts'] ?> attempts
            </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="form-hint">Version <?= (int) $revision['revision_number'] ?>, activated <?= $e($when($revision['created_at'])) ?>.</p>
<?php endif; ?>
<?php

// ---- One student ----
$studentId = filter_var($_GET['student'] ?? null, FILTER_VALIDATE_INT);
if ($studentId !== false && $studentId !== null):
    $student = (new UserRepository($db))->findById($studentId);
    $studentAttempts = $student === null ? [] : $results->attemptsForStudent($revisionId, $studentId);
    $studentAttempts = array_values(array_filter($studentAttempts, static fn (array $a): bool => $a['status'] !== 'in_progress'));
    if ($student === null || $studentAttempts === [] || (int) $student['id'] === (int) $user['id']):
        Session::set('flash_error', 'No submitted attempts for that student.');
        Http::redirect($revisionUrl);
    endif;
    $selected = $studentAttempts[count($studentAttempts) - 1];
    foreach ($studentAttempts as $candidate) {
        if (isset($_GET['attempt']) && (int) $_GET['attempt'] === (int) $candidate['id']) {
            $selected = $candidate;
        }
    }
    ?>
    <div class="section-heading">
        <h2><?= $e($student['display_name']) ?></h2>
        <a class="link external no-print" href="<?= $e($revisionUrl) ?>">All students</a>
    </div>
    <p class="form-hint"><?= $e($student['email']) ?></p>
    <div class="toolbar-actions no-print">
        <?php foreach ($studentAttempts as $candidate): ?>
            <a class="button button-small <?= (int) $candidate['id'] === (int) $selected['id'] ? 'button-fill' : 'button-tonal' ?> external"
               href="<?= $e($revisionUrl . '&student=' . $studentId . '&attempt=' . (int) $candidate['id']) ?>">
                Attempt <?= (int) $candidate['attempt_number'] ?>: <?= $e($pts((float) $candidate['score'])) ?>/<?= $e($pts((float) $candidate['max_score'])) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <p class="form-hint">
        Submitted <?= $e($when($selected['submitted_at'])) ?><?= $selected['status'] === 'expired' ? ' · time ran out' : '' ?>.
        You always see full detail here, regardless of what students are allowed to see.
    </p>
    <?php
    $presented = \QuizMyClass\Support\Grader::present($questions, QuizRepository::revisionSettings($revision), (int) $selected['seed']);
    $byId = [];
    foreach ($questions as $q) {
        $byId[(int) $q['id']] = $q;
    }
    $reviewQuestions = array_map(static fn (array $q): array => $byId[(int) $q['id']], $presented);
    $reviewAnswers = $attemptsRepo->gradedAnswers((int) $selected['id']);
    $reviewFlags = ['score' => true, 'correctness' => true, 'correct_answers' => true, 'explanations' => true];
    require dirname(__DIR__) . '/app/Views/review.php';
    ?>
    <form action="/results/reset.php" method="post" class="no-print"
          data-confirm="Permanently delete <?= $e($student['display_name']) ?>'s attempts for this version? This cannot be undone.">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
        <input type="hidden" name="revision_id" value="<?= $revisionId ?>">
        <input type="hidden" name="user_id" value="<?= (int) $studentId ?>">
        <div class="block"><button class="button color-red" type="submit">Reset this student's attempts</button></div>
    </form>
    <?php
    View::end();
    return;
endif;

// ---- Overview ----
$summary = $results->summary($revisionId, (int) $user['id']);
$students = $results->students($revisionId, (int) $user['id']);
$analysis = $results->itemAnalysis($revisionId, (int) $user['id'], $questions);
?>
<div class="stats" role="group" aria-label="Results summary">
    <div class="stat"><strong><?= $summary['students'] ?></strong><span>Students</span></div>
    <div class="stat"><strong><?= $summary['attempts'] ?></strong><span>Submitted attempts</span></div>
    <div class="stat"><strong><?= $summary['average_best'] === null ? '–' : round($summary['average_best']) . '%' ?></strong><span>Average best score</span></div>
    <div class="stat"><strong><?= $summary['highest'] === null ? '–' : round($summary['highest']) . '%' ?></strong><span>Highest</span></div>
    <div class="stat"><strong><?= $summary['lowest'] === null ? '–' : round($summary['lowest']) . '%' ?></strong><span>Lowest</span></div>
    <?php if ($summary['in_progress'] > 0): ?>
        <div class="stat"><strong><?= $summary['in_progress'] ?></strong><span>In progress now</span></div>
    <?php endif; ?>
</div>

<div class="section-heading">
    <h2>Students</h2>
    <span class="no-print">
        <a class="link external" href="/results/export.php?room=<?= $slugUrl ?>&amp;rev=<?= $revisionId ?>">Download CSV</a>
        ·
        <a class="link external" href="/results/export.php?room=<?= $slugUrl ?>&amp;rev=<?= $revisionId ?>&amp;type=attempts">All attempts CSV</a>
    </span>
</div>
<?php if ($students === []): ?>
    <div class="empty-state"><h2>No submissions yet</h2><p>Student scores will appear here as attempts are submitted.</p></div>
<?php else: ?>
    <div class="data-table card">
        <table>
            <thead>
                <tr><th>Student</th><th class="numeric-cell">Attempts</th><th class="numeric-cell">Best</th><th class="numeric-cell">%</th><th>Last submitted</th></tr>
            </thead>
            <tbody>
                <?php foreach ($students as $row): ?>
                    <?php $percent = (float) $row['max_score'] > 0 ? round(100 * (float) $row['best_score'] / (float) $row['max_score']) : 0; ?>
                    <tr>
                        <td>
                            <a class="external" href="<?= $e($revisionUrl . '&student=' . (int) $row['user_id']) ?>"><?= $e($row['display_name']) ?></a>
                            <div class="cell-sub"><?= $e($row['email']) ?></div>
                        </td>
                        <td class="numeric-cell"><?= (int) $row['attempts'] ?></td>
                        <td class="numeric-cell"><?= $e($pts((float) $row['best_score'])) ?> / <?= $e($pts((float) $row['max_score'])) ?></td>
                        <td class="numeric-cell"><?= $percent ?>%</td>
                        <td><?= $e($when($row['last_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="section-heading"><h2>Item analysis</h2></div>
<p class="form-hint">Share of all submitted attempts that answered each question correctly. Low numbers can flag a hard or unclear question.</p>
<?php foreach ($analysis as $item): ?>
    <?php
    $q = $item['question'];
    $plain = mb_strimwidth(html_entity_decode(trim(strip_tags((string) $q['prompt_html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 140, '…', 'UTF-8');
    $percent = $item['percent_correct'];
    ?>
    <div class="card item-card">
        <div class="card-content card-content-padding">
            <div class="review-head">
                <strong>Q<?= $item['number'] ?></strong>
                <?php if ($percent !== null): ?>
                    <?= $percent < 40 ? View::chip('Hard', 'red') : ($percent >= 90 ? View::chip('Easy', 'green') : '') ?>
                <?php endif; ?>
                <span class="review-points"><?= $percent === null ? 'No data' : round($percent) . '% correct' ?> · <?= $item['correct'] ?> of <?= $item['attempts'] ?></span>
            </div>
            <p class="item-prompt"><?= $e($plain !== '' ? $plain : 'Question ' . $item['number']) ?></p>
            <?php if ($percent !== null): ?>
                <progress value="<?= (int) round($percent) ?>" max="100" aria-label="Percent correct"></progress>
            <?php endif; ?>
            <?php if (!empty($q['choices'])): ?>
                <ul class="choice-stats">
                    <?php
                    $answer = $q['answer'];
                    $correctIndexes = isset($answer['correct_index']) ? [(int) $answer['correct_index']] : array_map('intval', $answer['correct_indexes'] ?? []);
                    ?>
                    <?php foreach ($q['choices'] as $index => $choice): ?>
                        <li>
                            <?= $e($choice) ?>
                            <strong>· <?= (int) $item['picks'][$index] ?> picked</strong>
                            <?= in_array($index, $correctIndexes, true) ? View::chip('✓ Correct answer', 'green') : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($students !== []): ?>
    <form action="/results/reset.php" method="post" class="no-print"
          data-confirm="Permanently delete ALL attempts for this version? Student scores cannot be recovered.">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="room_slug" value="<?= $e($room['slug']) ?>">
        <input type="hidden" name="revision_id" value="<?= $revisionId ?>">
        <div class="block"><button class="button color-red" type="submit">Reset all results for this version</button></div>
    </form>
<?php endif; ?>
<?php View::end();
