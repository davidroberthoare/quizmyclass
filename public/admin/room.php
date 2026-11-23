<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AdminGuard;
use QuizMyClass\Support\AdminRepository;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
$admin = AdminGuard::require($runtime);

$room = (new AdminRepository($runtime['database']))->roomDetail((int) ($_GET['id'] ?? 0));
if ($room === null) {
    Session::set('flash_error', 'Quiz not found.');
    Http::redirect('/admin/');
}
$e = View::e(...);
$timezone = (string) $runtime['config']['app']['timezone'];
$when = static fn (?string $utc): string => QuizSettings::toLocal($utc, $timezone, 'M j, Y g:i a');
$settings = QuizSettings::fromStored((string) $room['settings_json']);
$csrf = Csrf::token();
$stateColor = ['editing' => 'orange', 'active' => 'green', 'closed' => 'gray'];

View::begin($runtime, ['title' => 'Admin · ' . $room['name'], 'heading' => 'Admin', 'back' => '/admin/', 'backLabel' => 'Admin', 'wide' => true]);
?>
<div class="page-header">
    <div>
        <h1><?= $e($room['name']) ?></h1>
        <div class="meta"><?= View::chip(ucfirst((string) $room['state']), $stateColor[$room['state']] ?? 'gray') ?>
            <a class="external" href="/q/<?= $e($room['slug']) ?>">/q/<?= $e($room['slug']) ?></a></div>
    </div>
</div>

<div class="list list-strong list-outline-ios list-dividers-ios inset">
    <ul>
        <li class="item-content"><div class="item-inner"><div class="item-title">Owner</div><div class="item-after"><?= $e($room['owner_name']) ?> · <?= $e($room['owner_email']) ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Created</div><div class="item-after"><?= $e($when($room['created_at'])) ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Members</div><div class="item-after"><?= (int) $room['members'] ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Questions by status</div><div class="item-after"><?php
            $parts = [];
            foreach ($room['question_statuses'] as $status => $n) { $parts[] = $e($status) . ' ' . (int) $n; }
            echo $parts === [] ? 'None' : implode(' · ', $parts);
        ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Time limit</div><div class="item-after"><?= $settings['duration_minutes'] ? (int) $settings['duration_minutes'] . ' min' : 'None' ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Attempts allowed</div><div class="item-after"><?= (int) $settings['max_attempts'] === 0 ? 'Unlimited' : (int) $settings['max_attempts'] ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Opens / closes</div><div class="item-after"><?= $e($when($settings['open_at']) ?: '—') ?> / <?= $e($when($settings['close_at']) ?: '—') ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Shuffle questions / choices</div><div class="item-after"><?= $settings['shuffle_questions'] ? 'Yes' : 'No' ?> / <?= $settings['shuffle_choices'] ? 'Yes' : 'No' ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Feedback</div><div class="item-after"><?= $e(str_replace('_', ' ', (string) $settings['feedback_when'])) ?></div></div></li>
    </ul>
</div>

<div class="toolbar-actions">
    <?php if ($room['state'] === 'active'): ?>
        <form action="/admin/action.php" method="post" data-confirm="Deactivate this quiz now? Attempts in progress are submitted as they stand.">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="close_room">
            <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
            <button class="button button-small button-tonal color-orange" type="submit">Deactivate quiz</button>
        </form>
    <?php endif; ?>
</div>

<div class="section-heading"><h2>Versions &amp; activity</h2></div>
<?php if ($room['revisions'] === []): ?>
    <p class="form-hint">Never activated.</p>
<?php else: ?>
    <div class="data-table card"><table>
        <thead><tr><th>Version</th><th>Activated</th><th class="numeric-cell">Students</th><th class="numeric-cell">Submitted</th><th class="numeric-cell">In progress</th></tr></thead>
        <tbody><?php foreach ($room['revisions'] as $r): ?>
            <tr><td><?= (int) $r['revision_number'] ?></td><td><?= $e($when($r['created_at'])) ?></td><td class="numeric-cell"><?= (int) $r['students'] ?></td><td class="numeric-cell"><?= (int) $r['submitted'] ?></td><td class="numeric-cell"><?= (int) $r['open_attempts'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>

<div class="section-heading"><h2>Questions</h2></div>
<div class="data-table card"><table>
    <thead><tr><th>Prompt</th><th>Type</th><th>Author</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($room['questions'] as $q): ?>
        <?php $plain = mb_strimwidth(html_entity_decode(trim(strip_tags((string) $q['prompt_html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 100, '…', 'UTF-8'); ?>
        <tr><td><?= $e($plain) ?></td><td><?= $e(str_replace('_', ' ', (string) $q['type'])) ?></td><td><?= $e($q['author']) ?></td><td><?= $e($q['status']) ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>

<div class="section-heading"><h2>Recent audit events</h2></div>
<div class="data-table card"><table>
    <thead><tr><th>When</th><th>Action</th><th>By</th></tr></thead>
    <tbody><?php foreach ($room['audit'] as $a): ?>
        <tr><td><?= $e($when($a['created_at'])) ?></td><td><?= $e($a['action']) ?></td><td><?= $e($a['actor'] ?? 'system / admin') ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>

<div class="section-heading"><h2>Danger zone</h2></div>
<form action="/admin/action.php" method="post" class="block block-strong inset"
      data-confirm="Permanently delete this quiz, all questions, and every student attempt? This cannot be undone.">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="delete_room">
    <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
    <p>To delete, type the quiz link <strong><?= $e($room['slug']) ?></strong> below.</p>
    <div class="list list-strong list-outline-ios inset"><ul><li class="item-content item-input"><div class="item-inner">
        <label class="item-title item-label" for="confirm-slug">Quiz link</label>
        <div class="item-input-wrap"><input id="confirm-slug" name="confirm_slug" type="text" autocomplete="off" required></div>
    </div></li></ul></div>
    <button class="button color-red" type="submit">Delete quiz permanently</button>
</form>
<?php View::end();
