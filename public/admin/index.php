<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AdminGuard;
use QuizMyClass\Support\AdminRepository;
use QuizMyClass\Support\Migrator;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
$admin = AdminGuard::require($runtime);

$repo = new AdminRepository($runtime['database']);
$e = View::e(...);
$timezone = (string) $runtime['config']['app']['timezone'];
$when = static fn (?string $utc): string => QuizSettings::toLocal($utc, $timezone, 'M j, Y g:i a');
$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$state = (string) ($_GET['state'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $repo->searchRooms($query, $state, $page);
$counts = $repo->counts();

$migrationDir = $runtime['config']['paths']['migrations'];
$migrator = new Migrator($runtime['database'], $migrationDir);
$files = glob($migrationDir . '/*.sql') ?: [];
$pending = count($files) - $migrator->appliedCount();
$dbPath = $runtime['config']['database']['path'];
$dbSize = is_file($dbPath) ? filesize($dbPath) : 0;
$backups = glob($runtime['config']['paths']['backups'] . '/*.sqlite3') ?: [];
rsort($backups);
$stateColor = ['editing' => 'orange', 'active' => 'green', 'closed' => 'gray'];

View::begin($runtime, ['title' => 'Admin', 'heading' => 'Admin', 'back' => null, 'wide' => true]);
?>
<div class="page-header"><div><h1>Administration</h1><div class="meta">Signed in as <?= $e($admin) ?></div></div></div>
<?= AdminGuard::nav('index') ?>

<?php if ($pending > 0): ?>
    <div class="notice notice-error" role="alert"><strong>Problem:</strong> <?= $pending ?> database migration<?= $pending === 1 ? '' : 's' ?> pending. Run <code>php scripts/migrate.php</code>.</div>
<?php endif; ?>

<div class="stats" role="group" aria-label="Deployment summary">
    <div class="stat"><strong><?= $counts['users'] ?></strong><span>Users<?= $counts['blocked'] > 0 ? ' (' . $counts['blocked'] . ' blocked)' : '' ?></span></div>
    <div class="stat"><strong><?= $counts['quizzes'] ?></strong><span>Quizzes</span></div>
    <div class="stat"><strong><?= $counts['active'] ?></strong><span>Active now</span></div>
    <div class="stat"><strong><?= $counts['questions'] ?></strong><span>Questions</span></div>
    <div class="stat"><strong><?= $counts['attempts'] ?></strong><span>Submitted attempts</span></div>
    <div class="stat"><strong><?= $counts['in_progress'] ?></strong><span>Attempts in progress</span></div>
</div>
<div class="list list-strong list-outline-ios list-dividers-ios inset">
    <ul>
        <li class="item-content"><div class="item-inner"><div class="item-title">Environment</div><div class="item-after"><?= $e($runtime['config']['app']['environment']) ?><?= $runtime['config']['app']['debug'] ? ' · debug ON' : '' ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">PHP</div><div class="item-after"><?= $e(PHP_VERSION) ?></div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Database</div><div class="item-after"><?= $e(number_format($dbSize / 1048576, 2)) ?> MB · <?= $migrator->appliedCount() ?> migrations applied</div></div></li>
        <li class="item-content"><div class="item-inner"><div class="item-title">Latest backup</div><div class="item-after"><?= $backups === [] ? 'None yet' : $e(basename($backups[0])) ?> (<?= count($backups) ?> total)</div></div></li>
    </ul>
</div>

<div class="section-heading"><h2>Quizzes</h2></div>
<form method="get" class="admin-search">
    <div class="list list-strong list-outline-ios inset">
        <ul>
            <li class="item-content item-input">
                <div class="item-inner">
                    <label class="item-title item-label" for="q">Search name, link, or owner</label>
                    <div class="item-input-wrap"><input id="q" type="search" name="q" value="<?= $e($query) ?>" maxlength="100"></div>
                </div>
            </li>
        </ul>
    </div>
    <div class="toolbar-actions">
        <?php foreach (['' => 'All', 'editing' => 'Editing', 'active' => 'Active', 'closed' => 'Closed'] as $value => $label): ?>
            <button class="button button-small <?= $state === $value ? 'button-fill' : 'button-tonal' ?>" type="submit" name="state" value="<?= $e($value) ?>"><?= $e($label) ?></button>
        <?php endforeach; ?>
    </div>
</form>

<?php if ($result['rows'] === []): ?>
    <div class="empty-state"><h2>No quizzes found</h2></div>
<?php else: ?>
    <div class="data-table card">
        <table>
            <thead><tr><th>Quiz</th><th>Owner</th><th>State</th><th class="numeric-cell">Questions</th><th class="numeric-cell">Attempts</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><a class="external" href="/admin/room.php?id=<?= (int) $row['id'] ?>"><?= $e($row['name']) ?></a><div class="cell-sub">/q/<?= $e($row['slug']) ?></div></td>
                    <td><?= $e($row['owner_name']) ?><div class="cell-sub"><?= $e($row['owner_email']) ?></div></td>
                    <td><?= View::chip(ucfirst((string) $row['state']), $stateColor[$row['state']] ?? 'gray') ?></td>
                    <td class="numeric-cell"><?= (int) $row['questions'] ?></td>
                    <td class="numeric-cell"><?= (int) $row['attempts'] ?></td>
                    <td><?= $e($when($row['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="toolbar-actions">
        <?php $base = '?q=' . rawurlencode($query) . '&state=' . rawurlencode($state); ?>
        <?php if ($page > 1): ?><a class="button button-small button-tonal external" href="<?= $e($base . '&page=' . ($page - 1)) ?>">Previous</a><?php endif; ?>
        <?php if ($result['more']): ?><a class="button button-small button-tonal external" href="<?= $e($base . '&page=' . ($page + 1)) ?>">Next</a><?php endif; ?>
    </div>
<?php endif; ?>
<?php View::end();
