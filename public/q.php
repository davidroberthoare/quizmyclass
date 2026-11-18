<?php

declare(strict_types=1);

use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\RoomRepository;
use QuizMyClass\Support\UserRepository;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
$rooms = new RoomRepository($runtime['database']);

try {
    $slug = (string) ($_GET['slug'] ?? '');
    $room = $rooms->findBySlug($slug);
    if ($room === null) {
        http_response_code(404);
        $pageTitle = 'Room not found';
        $contentTitle = 'Room not found';
        $content = 'Check the room link and try again.';
        $user = Session::user(new UserRepository($runtime['database']));
        $role = null;
    } else {
        $pageTitle = (string) $room['name'];
        $contentTitle = (string) $room['name'];
        $user = Session::user(new UserRepository($runtime['database']));
        $role = $user === null ? null : $rooms->roleForUser(
            (int) $room['id'],
            (int) $user['id'],
        );
        $content = $role === 'owner'
            ? 'You are the room owner. Question review and room settings will appear here.'
            : ($role === 'student'
                ? 'You joined this room as a student.'
                : 'Sign in with Google to enter this room.');
    }
} catch (Throwable $exception) {
    http_response_code(400);
    $pageTitle = 'Invalid room link';
    $contentTitle = 'Invalid room link';
    $content = $exception->getMessage();
    $user = Session::user(new UserRepository($runtime['database']));
    $role = null;
    $room = null;
}

$google = new GoogleClient($runtime['config']);
$appName = $escape($runtime['config']['app']['name']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $escape($pageTitle) ?> · <?= $appName ?></title>
    <link rel="stylesheet" href="/assets/vendor/framework7/framework7-bundle.min.css">
</head>
<body>
<div id="app">
    <div class="view view-main view-init safe-areas">
        <div class="page">
            <div class="navbar">
                <div class="navbar-bg"></div>
                <div class="navbar-inner">
                    <div class="left"><a class="link external" href="/">Home</a></div>
                    <div class="title"><?= $appName ?></div>
                </div>
            </div>
            <main class="page-content">
                <section class="block block-strong">
                    <?php if ($role !== null): ?>
                        <span class="badge color-<?= $role === 'owner' ? 'blue' : 'green' ?>">
                            <?= $role === 'owner' ? 'Teacher' : 'Student' ?>
                        </span>
                    <?php endif; ?>
                    <h1><?= $escape($contentTitle) ?></h1>
                    <p><?= $escape($content) ?></p>
                    <?php if ($room !== null && $user === null && $google->isConfigured()): ?>
                        <a class="button button-fill button-large external" href="/auth/google.php">Sign in with Google</a>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>
</div>
<script src="/assets/vendor/framework7/framework7-bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>

