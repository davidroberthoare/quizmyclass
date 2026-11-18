<?php

declare(strict_types=1);

use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Http;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

try {
    $google = new GoogleClient($runtime['config']);
    $state = GoogleClient::state();
    $verifier = GoogleClient::verifier();
    Session::set('oauth_state', $state);
    Session::set('oauth_verifier', $verifier);
    Http::redirectToHttps($google->authorizeUrl($state, $verifier));
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
    Http::redirect('/');
}

