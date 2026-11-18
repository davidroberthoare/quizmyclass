<?php

declare(strict_types=1);

use QuizMyClass\Support\Http;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
$appName = htmlspecialchars($runtime['config']['app']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

Http::securityHeaders();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#164e63">
    <title><?= $appName ?></title>
    <link rel="stylesheet" href="/assets/vendor/framework7/framework7-bundle.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link button button-fill" href="#main-content">Skip to main content</a>
<div id="app">
    <div class="view view-main view-init safe-areas">
        <div class="page">
            <div class="navbar">
                <div class="navbar-bg"></div>
                <div class="navbar-inner">
                    <div class="title"><?= $appName ?></div>
                </div>
            </div>
            <main id="main-content" class="page-content">
                <section class="block block-strong">
                    <div class="block-title">Collaborative classroom quizzes</div>
                    <h1>Build the quiz together.</h1>
                    <p>
                        Create questions with your class, review them as the teacher,
                        and activate a stable quiz revision when everyone is ready.
                    </p>
                </section>
                <section class="block" aria-labelledby="getting-started-title">
                    <article class="card">
                        <div class="card-header" id="getting-started-title">Foundation ready</div>
                        <div class="card-content card-content-padding">
                            <p>
                                The secure PHP and SQLite runtime is installed. Google
                                sign-in, room creation, and collaboration will be added next.
                            </p>
                            <div class="chip color-green">
                                <div class="chip-label">Database-backed service online</div>
                            </div>
                        </div>
                    </article>
                </section>
            </main>
        </div>
    </div>
</div>
<script src="/assets/vendor/framework7/framework7-bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>

