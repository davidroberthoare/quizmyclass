<?php

declare(strict_types=1);

namespace QuizMyClass\Auth;

use QuizMyClass\Support\UserRepository;

final class Session
{
    /** Sign out after this long without a request. */
    private const IDLE_SECONDS = 8 * 3600;
    /** Sign out this long after signing in, however active the session is. */
    private const ABSOLUTE_SECONDS = 24 * 3600;

    private static bool $started = false;

    /** @param array<string, mixed> $config */
    public static function start(array $config): void
    {
        if (self::$started) {
            return;
        }

        $secure = str_starts_with(
            strtolower((string) ($config['app']['base_url'] ?? '')),
            'https://',
        ) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        // Keep session files in our private data directory, not a shared system temp folder.
        $directory = (string) ($config['paths']['sessions'] ?? '');
        if ($directory !== '') {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the session directory.');
            }
            session_save_path($directory);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_SECONDS);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('quizmyclass_session');
        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): mixed
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $value;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $userId, 'login_at' => time()];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
        session_id('');
        self::$started = false;
    }

    /** @return array<string, mixed>|null */
    public static function user(UserRepository $users): ?array
    {
        $userId = filter_var(self::get('user_id'), FILTER_VALIDATE_INT);
        if ($userId === false || $userId === null) {
            return null;
        }
        $loginAt = (int) self::get('login_at', 0);
        if ($loginAt <= 0 || time() - $loginAt > self::ABSOLUTE_SECONDS) {
            self::forget('user_id');
            return null;
        }
        $user = $users->findById($userId);
        // A user blocked by the super-admin loses access immediately, even mid-session.
        return ($user === null || !empty($user['blocked_at'])) ? null : $user;
    }
}

