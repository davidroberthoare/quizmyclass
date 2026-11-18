<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

final class Http
{
    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' https: data:; font-src \'self\' data:; connect-src \'self\'; frame-ancestors \'none\'; base-uri \'self\'; form-action \'self\'');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    public static function redirect(string $path, int $status = 303): never
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('Redirect destination must be a local path.');
        }
        http_response_code($status);
        header('Location: ' . $path);
        exit;
    }

    public static function redirectToHttps(string $url, int $status = 303): never
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException('External redirects must use HTTPS.');
        }
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            throw new \RuntimeException('This endpoint accepts POST requests only.');
        }
    }
}

