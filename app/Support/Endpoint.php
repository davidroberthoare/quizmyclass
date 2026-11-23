<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use QuizMyClass\Auth\Session;
use RuntimeException;

/** Shared prologue for state-changing endpoints: session, headers, POST-only, CSRF, signed-in user. */
final class Endpoint
{
    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed> the signed-in user
     */
    public static function postUser(array $runtime): array
    {
        Session::start($runtime['config']);
        Http::securityHeaders();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit;
        }
        Csrf::validate();
        $user = Session::user(new UserRepository($runtime['database']));
        if ($user === null) {
            throw new RuntimeException('Sign in first.');
        }
        return $user;
    }

    /**
     * Start a page request and return the signed-in user, or send the visitor home with a message.
     *
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    public static function pageUser(array $runtime): array
    {
        Session::start($runtime['config']);
        Http::securityHeaders();
        $user = Session::user(new UserRepository($runtime['database']));
        if ($user === null) {
            Session::set('flash_error', 'Sign in to continue.');
            Http::redirect('/');
        }
        return $user;
    }
}
