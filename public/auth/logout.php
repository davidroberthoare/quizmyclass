<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    Csrf::validate();
    Session::logout();
    Session::start($runtime['config']);
    Session::set('flash_success', 'You are signed out.');
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}

Http::redirect('/');
