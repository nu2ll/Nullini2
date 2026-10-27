<?php

date_default_timezone_set('Asia/Tehran');
mb_internal_encoding('UTF-8');

define('NULLINI_ROOT', dirname(__DIR__));
define('NULLINI_DATA', NULLINI_ROOT . '/data');

spl_autoload_register(static function (string $class): void {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (['', '/Core', '/Features', '/Games', '/Handlers', '/Sites'] as $sub) {
            foreach (glob(__DIR__ . $sub . '/*.php') ?: [] as $file) {
                $map[basename($file, '.php')] = $file;
            }
        }
    }
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

require_once __DIR__ . '/helpers.php';

(static function (string $file): void {
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
})(NULLINI_ROOT . '/.env');
