<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use QuizMyClass\Auth\Session;

/**
 * Shared page chrome: <head>, Framework7 navbar, flash messages, scripts.
 * Pages call View::begin(), echo their content, then View::end().
 */
final class View
{
    /** Material-style 24px paths, rendered inline so no icon font or CDN is needed. */
    private const ICONS = [
        'check' => 'M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41Z',
        'close' => 'M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.41l-6.3 6.3-1.41-1.42L9.17 12l-6.29-6.29 1.41-1.42 6.3 6.3 6.29-6.3Z',
        'edit' => 'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25Zm17.71-10.04a1 1 0 0 0 0-1.42l-2.5-2.5a1 1 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 1.99-1.66Z',
        'plus' => 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2Z',
        'more' => 'M6 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm12 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm-6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z',
        'back' => 'M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2Z',
        'home' => 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8h5Z',
        'link' => 'M3.9 12a5 5 0 0 1 5-5h3v2h-3a3 3 0 0 0 0 6h3v2h-3a5 5 0 0 1-5-5Zm4.1 1h8v-2H8v2Zm4.1-6h3a5 5 0 0 1 0 10h-3v-2h3a3 3 0 0 0 0-6h-3V7Z',
        'copy' => 'M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1Zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Zm0 16H8V7h11v14Z',
        'settings' => 'M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.07-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.61-.22l-2.39.96a7.1 7.1 0 0 0-1.62-.94L14.38 2.8a.49.49 0 0 0-.49-.42h-3.84a.49.49 0 0 0-.49.42l-.36 2.52c-.59.24-1.13.56-1.62.94l-2.39-.96a.49.49 0 0 0-.61.22L2.66 8.86a.49.49 0 0 0 .12.64l2.03 1.58c-.05.31-.09.64-.09.94s.03.63.08.94l-2.02 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.31.61.22l2.39-.96c.5.38 1.04.7 1.62.94l.36 2.52c.04.24.25.42.49.42h3.84c.24 0 .45-.18.49-.42l.36-2.52c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.09.48 0 .61-.22l1.92-3.32a.49.49 0 0 0-.12-.64l-2.04-1.6ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z',
        'logout' => 'M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5ZM4 5h8V3H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H4V5Z',
        'groups' => 'M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05A4.2 4.2 0 0 1 17 16.5V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z',
        'quiz' => 'M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm-6 15h-2v-2h2v2Zm2.07-7.75-.9.92A3.4 3.4 0 0 0 13 14h-2v-.5c0-.83.33-1.58.88-2.12l1.24-1.26A1.9 1.9 0 0 0 13.66 8.4 2 2 0 0 0 10 9.5H8a4 4 0 0 1 8 0c0 .88-.36 1.68-.93 2.25Z',
        'visibility_off' => 'm2.1 3.51 1.41-1.42 18.9 18.9-1.41 1.42-3.17-3.17A11.7 11.7 0 0 1 12 21C5 21 1 12 1 12a17.7 17.7 0 0 1 4.11-5.3Zm3.4 3.4A15.8 15.8 0 0 0 3.14 12c1.1 2.06 3.53 6 8.86 6 1.46 0 2.73-.3 3.84-.77l-2.12-2.12A4 4 0 0 1 9.1 9.1Zm10.68 10.68-2.38-2.38A4 4 0 0 1 10.1 11.5l-2.5-2.5A4 4 0 0 1 12 8c2.21 0 4 1.79 4 4 0 .79-.23 1.53-.64 2.16l2.82 2.82A15.5 15.5 0 0 0 20.86 12 12 12 0 0 0 18.8 8.2Z',
        'undo' => 'M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62A8 8 0 0 1 12.5 10c3.54 0 6.55 2.31 7.6 5.5l2.37-.78A10 10 0 0 0 12.5 8Z',
        'up' => 'M7.41 15.41 12 10.83l4.59 4.58L18 14l-6-6-6 6 1.41 1.41Z',
        'down' => 'M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41Z',
        'bold' => 'M15.6 10.79c.97-.68 1.6-1.75 1.6-3.04C17.2 5.03 15.19 3 12.25 3H6v18h7.04c2.88 0 4.96-1.93 4.96-4.56 0-2.02-1.15-3.76-2.4-5.65ZM8 5h4.25c1.62 0 2.95.85 2.95 2.75S13.87 10.5 12.25 10.5H8V5Zm4.96 13.5H8v-6h5.04c1.74 0 2.96 1.08 2.96 3s-1.22 3-2.96 3Z',
        'italic' => 'M10 4v3h2.21l-3.42 8H6v3h8v-3h-2.21l3.42-8H18V4h-8Z',
        'underline' => 'M12 17c3.31 0 6-2.69 6-6V3h-2.5v8c0 1.93-1.57 3.5-3.5 3.5S8.5 12.93 8.5 11V3H6v8c0 3.31 2.69 6 6 6ZM5 19v2h14v-2H5Z',
        'ol' => 'M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1Zm1-9h1V4H2v1h1v3Zm-1 3h1.8L2 13.1V14h3v-1H3.2L5 11.9V11H2v1Zm4-7v2h12V4H6Zm0 16h12v-2H6v2Zm0-6h12v-2H6v2Z',
        'ul' => 'M4 10.5A1.5 1.5 0 1 0 4 7.5a1.5 1.5 0 0 0 0 3Zm0 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM7 8v2h12V8H7Zm0 8h12v-2H7v2Z',
        'image' => 'M21 19V5H3v14h18ZM5 17l4.5-5.5 3.5 4 2.5-3 3.5 4.5H5Zm12-9a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z',
    ];

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function icon(string $name, int $size = 20): string
    {
        $path = self::ICONS[$name] ?? self::ICONS['more'];
        return '<svg class="icon" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="'
            . $path . '"/></svg>';
    }

    /** @return array<string, string> name => svg path, for client scripts that build markup */
    public static function iconPaths(): array
    {
        return self::ICONS;
    }

    /**
     * Send headers and print everything up to the opening of the page container.
     *
     * Options: title, user (array|null), back (url|null), backLabel,
     * heading (navbar title; defaults to app name), css (extra stylesheets),
     * wide (bool: widen the content container for the editor / room lists).
     *
     * @param array{config: array<string, mixed>} $runtime
     * @param array<string, mixed> $options
     */
    public static function begin(array $runtime, array $options = []): void
    {
        Http::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        $appName = (string) $runtime['config']['app']['name'];
        $title = (string) ($options['title'] ?? '');
        $fullTitle = $title === '' ? $appName : $title . ' · ' . $appName;
        $user = $options['user'] ?? null;
        $back = $options['back'] ?? null;
        $heading = (string) ($options['heading'] ?? $appName);
        $width = !empty($options['wide']) ? 'container-wide' : '';
        $error = Session::forget('flash_error');
        $success = Session::forget('flash_success');

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="color-scheme" content="light dark">'
            . '<title>' . self::e($fullTitle) . '</title>'
            . '<link rel="stylesheet" href="/assets/vendor/framework7/framework7-bundle.min.css">'
            . '<link rel="stylesheet" href="/assets/css/app.css">';
        foreach ((array) ($options['css'] ?? []) as $sheet) {
            echo '<link rel="stylesheet" href="' . self::e($sheet) . '">';
        }
        echo '</head><body><div id="app"><div class="view view-main view-init safe-areas"><div class="page">';

        echo '<div class="navbar"><div class="navbar-bg"></div><div class="navbar-inner">';
        echo '<div class="left">';
        if (is_string($back)) {
            echo '<a class="link external" href="' . self::e($back) . '">' . self::icon('back')
                . '<span>' . self::e((string) ($options['backLabel'] ?? 'Back')) . '</span></a>';
        } else {
            echo '<a class="link external brand" href="/" aria-label="' . self::e($appName) . ' home">'
                . self::icon('quiz', 24) . '</a>';
        }
        echo '</div><div class="title">' . self::e($heading) . '</div><div class="right">';
        if (is_array($user)) {
            echo '<span class="user-chip">' . self::e($user['display_name'] ?? '') . '</span>'
                . '<form action="/auth/logout.php" method="post" class="inline">'
                . '<input type="hidden" name="csrf_token" value="' . self::e(Csrf::token()) . '">'
                . '<button class="link icon-only" type="submit" aria-label="Sign out" title="Sign out">'
                . self::icon('logout') . '</button></form>';
        }
        echo '</div></div></div>';

        echo '<main class="page-content" id="main"><div class="container ' . $width . '">';
        if (is_string($error) && $error !== '') {
            echo '<div class="notice notice-error" role="alert"><strong>Problem:</strong> '
                . self::e($error) . '</div>';
        }
        if (is_string($success) && $success !== '') {
            echo '<div class="notice notice-success" role="status"><strong>Done:</strong> '
                . self::e($success) . '</div>';
        }
    }

    /**
     * Close the page container, print modal markup, and load scripts.
     *
     * @param list<string> $scripts
     */
    public static function end(string $afterMain = '', array $scripts = [], string $fab = ''): void
    {
        echo '</div></main>' . $fab . $afterMain . '</div></div></div>'
            . '<script src="/assets/vendor/framework7/framework7-bundle.min.js"></script>'
            . '<script src="/assets/js/app.js"></script>';
        foreach ($scripts as $script) {
            echo '<script src="' . self::e($script) . '"></script>';
        }
        echo '</body></html>';
    }

    /** Escaped markup for a state/status chip. Text always carries the meaning, color only reinforces it. */
    public static function chip(string $label, string $color = 'gray'): string
    {
        return '<span class="badge color-' . self::e($color) . '">' . self::e($label) . '</span>';
    }
}
