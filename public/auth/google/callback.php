<?php

declare(strict_types=1);

use QuizMyClass\Auth\AccessPolicy;
use QuizMyClass\Auth\GoogleClient;
use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\UserRepository;

$runtime = require dirname(__DIR__, 3) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();

try {
    $state = $_GET['state'] ?? '';
    $code = $_GET['code'] ?? '';
    $expectedState = Session::forget('oauth_state');
    $verifier = Session::forget('oauth_verifier');

    if (!is_string($state)
        || !is_string($expectedState)
        || $state === ''
        || !hash_equals($expectedState, $state)
        || !is_string($code)
        || $code === ''
        || !is_string($verifier)
    ) {
        throw new RuntimeException('The Google sign-in request expired or was invalid. Please try again.');
    }

    $google = new GoogleClient($runtime['config']);
    $profile = $google->authenticate($code, $verifier);
    (new AccessPolicy($runtime['config']))->assertAllowed($profile);
    $user = (new UserRepository($runtime['database']))->syncGoogleProfile($profile);
    if (!empty($user['blocked_at'])) {
        throw new RuntimeException('This account has been blocked. Contact your administrator.');
    }
    Session::login((int) $user['id']);
    Session::set('flash_success', 'Signed in as ' . $user['display_name'] . '.');
    Http::redirect('/');
} catch (Throwable $exception) {
    Session::set('flash_error', Http::safeMessage($exception));
    Http::redirect('/');
}

