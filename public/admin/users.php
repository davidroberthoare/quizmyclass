<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AdminGuard;
use QuizMyClass\Support\AdminRepository;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\QuizSettings;
use QuizMyClass\Support\View;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
$admin = AdminGuard::require($runtime);

$e = View::e(...);
$timezone = (string) $runtime['config']['app']['timezone'];
$when = static fn (?string $utc): string => QuizSettings::toLocal($utc, $timezone, 'M j, Y g:i a');
$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = (new AdminRepository($runtime['database']))->searchUsers($query, $page);
$csrf = Csrf::token();

View::begin($runtime, ['title' => 'Admin · Users', 'heading' => 'Admin', 'back' => null, 'wide' => true]);
?>
<div class="page-header"><div><h1>Users</h1><div class="meta">Signed in as <?= $e($admin) ?></div></div></div>
<?= AdminGuard::nav('users') ?>
<form method="get">
    <div class="list list-strong list-outline-ios inset"><ul><li class="item-content item-input"><div class="item-inner">
        <label class="item-title item-label" for="q">Search name or email</label>
        <div class="item-input-wrap"><input id="q" type="search" name="q" value="<?= $e($query) ?>" maxlength="100"></div>
    </div></li></ul></div>
</form>
<div class="data-table card"><table>
    <thead><tr><th>User</th><th>Last sign-in</th><th class="numeric-cell">Quizzes</th><th class="numeric-cell">Questions</th><th class="numeric-cell">Attempts</th><th>Access</th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $row): ?>
        <tr>
            <td><?= $e($row['display_name']) ?><div class="cell-sub"><?= $e($row['email']) ?></div></td>
            <td><?= $e($when($row['last_login_at'])) ?></td>
            <td class="numeric-cell"><?= (int) $row['quizzes'] ?></td>
            <td class="numeric-cell"><?= (int) $row['questions'] ?></td>
            <td class="numeric-cell"><?= (int) $row['attempts'] ?></td>
            <td>
                <form action="/admin/action.php" method="post"
                      <?= $row['blocked_at'] === null ? 'data-confirm="Block ' . $e($row['display_name']) . '? They are signed out and cannot sign in again until unblocked."' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="<?= $row['blocked_at'] === null ? 'block_user' : 'unblock_user' ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                    <?= $row['blocked_at'] === null ? View::chip('Active', 'green') : View::chip('Blocked', 'red') ?>
                    <button class="button button-small button-tonal" type="submit"><?= $row['blocked_at'] === null ? 'Block' : 'Unblock' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="toolbar-actions">
    <?php $base = '?q=' . rawurlencode($query); ?>
    <?php if ($page > 1): ?><a class="button button-small button-tonal external" href="<?= $e($base . '&page=' . ($page - 1)) ?>">Previous</a><?php endif; ?>
    <?php if ($result['more']): ?><a class="button button-small button-tonal external" href="<?= $e($base . '&page=' . ($page + 1)) ?>">Next</a><?php endif; ?>
</div>
<?php View::end();
