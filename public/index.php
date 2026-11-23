<?php

declare(strict_types=1);

use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;
use QuizMyClass\Support\View;

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (is_string($requestPath)
    && preg_match('#^/q/([a-z0-9-]+)$#', $requestPath, $matches) === 1
) {
    $_GET['slug'] = $matches[1];
    require __DIR__ . '/q.php';
    return;
}

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
Session::start($runtime['config']);

$users = new UserRepository($runtime['database']);
$rooms = new RoomRepository($runtime['database']);
$user = Session::user($users);
$google = new GoogleClient($runtime['config']);
$myRooms = $user === null ? [] : $rooms->forUser((int) $user['id']);
$e = View::e(...);
$stateColor = ['editing' => 'orange', 'active' => 'green', 'closed' => 'gray'];

View::begin($runtime, ['user' => $user]);

if ($user === null): ?>
    <section class="hero">
        <div class="hero-mark"><?= View::icon('quiz', 36) ?></div>
        <h1>Build the quiz together.</h1>
        <p>
            Your class writes the questions, your teacher approves them, and everyone
            takes the finished quiz. Sign in with Google to get started.
        </p>
        <?php if ($google->isConfigured()): ?>
            <a class="button button-fill button-large external" href="/auth/google.php">Sign in with Google</a>
        <?php else: ?>
            <p><?= View::chip('Setup needed', 'orange') ?> Google sign-in is not configured yet.</p>
        <?php endif; ?>
    </section>
    <div class="list list-strong list-outline-ios list-dividers-ios inset feature-list">
        <ul>
            <li class="item-content">
                <div class="item-media"><?= View::icon('groups', 28) ?></div>
                <div class="item-inner">
                    <div class="item-title">
                        <div class="item-header">Collaborate</div>
                        Students add and refine questions together.
                    </div>
                </div>
            </li>
            <li class="item-content">
                <div class="item-media"><?= View::icon('check', 28) ?></div>
                <div class="item-inner">
                    <div class="item-title">
                        <div class="item-header">Review</div>
                        The teacher approves, reorders, and publishes.
                    </div>
                </div>
            </li>
            <li class="item-content">
                <div class="item-media"><?= View::icon('quiz', 28) ?></div>
                <div class="item-inner">
                    <div class="item-title">
                        <div class="item-header">Take the quiz</div>
                        Auto-graded, with results the teacher can analyze.
                    </div>
                </div>
            </li>
        </ul>
    </div>
<?php else: ?>
    <div class="page-header">
        <div>
            <h1>Hello, <?= $e($user['display_name']) ?></h1>
            <div class="meta">Quizzes you teach or have contributed to.</div>
        </div>
    </div>

    <?php if ($myRooms === []): ?>
        <div class="empty-state">
            <?= View::icon('groups', 40) ?>
            <h2>No quizzes yet</h2>
            <p>Create a quiz and share its link with your class, or open a link your teacher sent you.</p>
            <a class="button button-fill popup-open" href="#" data-popup="#new-room">Create a quiz</a>
        </div>
    <?php else: ?>
        <div class="list media-list list-strong list-outline-ios list-dividers-ios inset">
            <ul>
                <?php foreach ($myRooms as $room): ?>
                    <li>
                        <a class="item-link item-content external" href="/q/<?= $e($room['slug']) ?>">
                            <div class="item-inner">
                                <div class="item-title-row">
                                    <div class="item-title"><?= $e($room['name']) ?></div>
                                    <div class="item-after">
                                        <?= View::chip(ucfirst((string) $room['state']), $stateColor[$room['state']] ?? 'gray') ?>
                                    </div>
                                </div>
                                <div class="item-subtitle">
                                    <?= (int) $room['question_count'] ?> question<?= (int) $room['question_count'] === 1 ? '' : 's' ?>
                                    · <?= $room['member_role'] === 'owner' ? 'Teacher' : 'Student' ?>
                                </div>
                                <div class="item-text">/q/<?= $e($room['slug']) ?></div>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif;

$modals = '';
$fab = '';
$scripts = [];
if ($user !== null) {
    $scripts[] = '/assets/js/home.js';
    $fab = '<div class="fab fab-extended fab-right-bottom"><a class="popup-open" href="#" data-popup="#new-room">'
        . View::icon('plus', 24) . '<div class="fab-text">New quiz</div></a></div>';
    ob_start(); ?>
    <div class="popup" id="new-room" aria-label="New quiz">
        <div class="view">
            <div class="page">
                <div class="navbar">
                    <div class="navbar-bg"></div>
                    <div class="navbar-inner">
                        <div class="left"><a class="link popup-close" href="#">Cancel</a></div>
                        <div class="title">New quiz</div>
                    </div>
                </div>
                <div class="page-content">
                    <form action="/rooms/create.php" method="post" data-new-room-form>
                        <input type="hidden" name="csrf_token" value="<?= $e(Csrf::token()) ?>">
                        <div class="list list-strong list-outline-ios list-dividers-ios inset">
                            <ul>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="room-name">Quiz name</label>
                                        <div class="item-input-wrap">
                                            <input id="room-name" name="name" type="text" maxlength="120"
                                                   autocomplete="off" required placeholder="Biology 2B">
                                        </div>
                                    </div>
                                </li>
                                <li class="item-content item-input">
                                    <div class="item-inner">
                                        <label class="item-title item-label" for="room-slug">Quiz link</label>
                                        <div class="item-input-wrap">
                                            <input id="room-slug" name="slug" type="text" minlength="3" maxlength="60"
                                                   pattern="[a-z0-9]+(?:-[a-z0-9]+)*" autocomplete="off"
                                                   spellcheck="false" required placeholder="biology-2b"
                                                   aria-describedby="slug-hint">
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <p class="form-hint" id="slug-hint">
                            Students will open <strong>/q/<span data-slug-preview>your-link</span></strong>.
                            Use lowercase letters, numbers, and single hyphens.
                        </p>
                        <div class="block">
                            <button class="button button-fill button-large" type="submit">Create quiz</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    $modals = (string) ob_get_clean();
}
View::end($modals, $scripts, $fab);
