<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use QuizMyClass\Auth\Session;
use RuntimeException;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('csrf_token');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }
        return $token;
    }

    public static function validate(): void
    {
        $expected = Session::get('csrf_token');
        $provided = $_POST['csrf_token'] ?? '';
        if (!is_string($expected)
            || !is_string($provided)
            || $expected === ''
            || !hash_equals($expected, $provided)
        ) {
            throw new RuntimeException('Your form expired. Please go back and try again.');
        }
    }
}

