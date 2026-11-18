<?php

declare(strict_types=1);

use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;

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
Http::securityHeaders();

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
$appName = $escape($runtime['config']['app']['name']);
$users = new UserRepository($runtime['database']);
$rooms = new RoomRepository($runtime['database']);
$user = Session::user($users);
$google = new GoogleClient($runtime['config']);
$ownedRooms = $user === null ? [] : $rooms->forOwner((int) $user['id']);
$error = Session::forget('flash_error');
$success = Session::forget('flash_success');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $appName ?></title>
    <link rel="stylesheet" href="/assets/vendor/framework7/framework7-bundle.min.css">
</head>
<body>
<div id="app">
    <div class="view view-main view-init safe-areas">
        <div class="page">
            <div class="navbar">
                <div class="navbar-bg"></div>
                <div class="navbar-inner">
                    <div class="title"><?= $appName ?></div>
                    <?php if ($user !== null): ?>
                        <div class="right">
                            <form action="/auth/logout.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>">
                                <button class="button button-small" type="submit">Sign out</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <main class="page-content">
                <?php if (is_string($error) && $error !== ''): ?>
                    <div class="block" role="alert">
                        <div class="card color-red">
                            <div class="card-content card-content-padding"><?= $escape($error) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (is_string($success) && $success !== ''): ?>
                    <div class="block" role="status">
                        <div class="card color-green">
                            <div class="card-content card-content-padding"><?= $escape($success) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <section class="block block-strong">
                    <div class="block-title">Collaborative classroom quizzes</div>
                    <?php if ($user === null): ?>
                        <h1>Build the quiz together.</h1>
                        <p>
                            Sign in with Google to create a room, contribute questions,
                            or take a quiz shared by your teacher.
                        </p>
                        <?php if ($google->isConfigured()): ?>
                            <a class="button button-fill button-large external" href="/auth/google.php">
                                Sign in with Google
                            </a>
                        <?php else: ?>
                            <p><span class="badge color-orange">Setup needed</span> Google sign-in is not configured yet.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <h1>Hello, <?= $escape($user['display_name']) ?>.</h1>
                        <p>Create a room and share its short link with your class.</p>
                    <?php endif; ?>
                </section>

                <?php if ($user !== null): ?>
                    
                    <section class="block-title">Your rooms</section>
                    <div class="block">
                        <?php if ($ownedRooms === []): ?>
                            <div class="card">
                                <div class="card-content card-content-padding">
                                    You have not created a room yet.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="list links-list">
                                <ul>
                                    <?php foreach ($ownedRooms as $room): ?>
                                        <li>
                                            <a class="external" href="/q/<?= $escape($room['slug']) ?>">
                                                <?= $escape($room['name']) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <section class="block-title">Create a room</section>
                    <div class="block">
                        <form action="/rooms/create.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>">
                            <div class="list no-hairlines-md">
                                <ul>
                                    <li class="item-content item-input">
                                        <div class="item-inner">
                                            <div class="item-title item-label">Room name</div>
                                            <div class="item-input-wrap">
                                                <input
                                                    name="name"
                                                    type="text"
                                                    maxlength="120"
                                                    autocomplete="off"
                                                    required
                                                >
                                            </div>
                                        </div>
                                    </li>
                                    <li class="item-content item-input">
                                        <div class="item-inner">
                                            <div class="item-title item-label">Room link</div>
                                            <div class="item-input-wrap">
                                                <input
                                                    name="slug"
                                                    type="text"
                                                    minlength="3"
                                                    maxlength="60"
                                                    pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                    required
                                                    placeholder="biology-2b"
                                                >
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <button class="button button-fill button-large" type="submit">Create room</button>
                        </form>
                    </div>

                    
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>
<script src="/assets/vendor/framework7/framework7-bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>

