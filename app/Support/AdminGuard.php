<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

/**
 * Access control for /admin. Apache's .htaccess Basic auth is the real gate; this class is
 * defence in depth and fails closed. It trusts only REMOTE_USER, which Apache sets after
 * verifying credentials. (PHP_AUTH_USER is just what the client sent, so it is never trusted.)
 *
 * Development-only fallback: when environment is "development" and config admin.dev_password_hash
 * is set, the guard checks HTTP Basic itself so the PHP built-in server (which ignores .htaccess)
 * can be used. That path is disabled in every other environment.
 */
final class AdminGuard
{
    /**
     * @param array<string, mixed> $runtime
     * @return string the authenticated admin's name
     */
    public static function require(array $runtime): string
    {
        $remote = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '';
        if (is_string($remote) && $remote !== '') {
            return $remote;
        }

        $config = $runtime['config'];
        $hash = $config['admin']['dev_password_hash'] ?? '';
        if (($config['app']['environment'] ?? '') === 'development' && is_string($hash) && $hash !== '') {
            $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
            $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
            if ($user === 'admin' && password_verify($password, $hash)) {
                return 'admin (dev)';
            }
            header('WWW-Authenticate: Basic realm="QuizMyClass admin (development)"');
            http_response_code(401);
            exit('Authentication required.');
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The admin console requires Apache authentication. See README: Super-admin.');
    }

    public static function nav(string $active): string
    {
        $items = ['index' => ['/admin/', 'Overview & quizzes'], 'users' => ['/admin/users.php', 'Users']];
        $html = '<div class="toolbar-actions" role="navigation" aria-label="Admin sections">';
        foreach ($items as $key => [$href, $label]) {
            $html .= '<a class="button button-small external ' . ($key === $active ? 'button-fill' : 'button-tonal')
                . '" href="' . $href . '">' . View::e($label) . '</a>';
        }
        return $html . '</div>';
    }
}
