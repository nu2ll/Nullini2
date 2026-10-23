<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/ic') {
    require_once dirname(__DIR__) . '/src/Core/Icons.php';
    $cache = Icons::path((string) ($_GET['d'] ?? ''), (string) ($_GET['f'] ?? ''));
    if ($cache === null) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000, immutable');
    readfile($cache);
    return true;
}

if ($path === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return true;
}

if ($path === '/') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nullini is running. Open https://t.me/NulliniBot';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'not found';
return true;
