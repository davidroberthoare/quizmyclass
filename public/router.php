<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

if ($path === '/admin' || $path === '/admin/') {
    require __DIR__ . '/admin/index.php';
    return true;
}

if ($path === '/health') {
    require __DIR__ . '/health.php';
    return true;
}

if (preg_match('#^/q/([A-Za-z0-9-]+)$#', $path, $matches) === 1) {
    $_GET['slug'] = $matches[1];
    require __DIR__ . '/q.php';
    return true;
}

if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
echo 'Not found';
return true;

