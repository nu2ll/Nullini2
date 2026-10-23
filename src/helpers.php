<?php

function mbTrim(string $s, string $chars = " \t\n\r\x0B"): string
{
    $set = preg_quote($chars, '/');
    return preg_replace('/^[' . $set . '\s]+|[' . $set . '\s]+$/u', '', $s) ?? trim($s);
}

function env(string $key, $default = null)
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = $_ENV[$key] ?? null;
    }
    return ($v === null || $v === '') ? $default : $v;
}

function envList(string $key, array $default = []): array
{
    $v = env($key);
    if ($v === null) {
        return $default;
    }
    return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $v) ?: []), static fn($x) => $x !== ''));
}

function h($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bidi(string $text): string
{
    return "\u{2068}{$text}\u{2069}";
}

function userName(array $from): string
{
    $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    return $name !== '' ? $name : 'کاربر';
}

function safeName(array $from, int $max = 32): string
{
    return bidi(h(mb_substr(userName($from), 0, $max)));
}

function userTag(?int $id, string $name, ?string $username = null): string
{
    $name = mbTrim($name);
    if ($id !== null && $id > 0) {
        return '<a href="tg://user?id=' . $id . '">' . bidi(h($name !== '' ? $name : (($username ?? '') !== '' ? $username : (string) $id))) . '</a>';
    }
    if ($username !== null && $username !== '') {
        return '@' . h($username);
    }
    return bidi(h($name));
}

function fa($text): string
{
    return strtr((string) $text, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

function en($text): string
{
    return strtr((string) $text, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function btn(string $text, string $data, ?string $style = 'primary'): array
{
    $b = ['text' => $text, 'callback_data' => $data];
    if ($style !== null) {
        $b['style'] = $style;
    }
    return $b;
}

function urlBtn(string $text, string $url, ?string $style = 'primary'): array
{
    $b = ['text' => $text, 'url' => $url];
    if ($style !== null) {
        $b['style'] = $style;
    }
    return $b;
}

function ikb(array $rows): array
{
    return ['inline_keyboard' => array_values(array_filter($rows, static fn($r) => !empty($r)))];
}

function logLine(string $level, string $tag, string $msg): void
{
    $msg  = preg_replace('/\s+/u', ' ', trim($msg)) ?? $msg;
    $line = sprintf('%-5s %-6s %s', $level, $tag, mb_substr($msg, 0, 500));
    if (PHP_SAPI === 'cli-server') {
        if (@file_put_contents('/proc/1/fd/' . ($level === 'ERROR' ? 2 : 1), $line . "\n", FILE_APPEND) === false) {
            error_log($line);
        }
        return;
    }
    if ($level === 'ERROR' || !defined('STDOUT')) {
        error_log($line);
        return;
    }
    fwrite(STDOUT, $line . "\n");
}

function logInfo(string $tag, string $msg): void
{
    logLine('INFO', $tag, $msg);
}

function logWarn(string $tag, string $msg): void
{
    logLine('WARN', $tag, $msg);
}

function logError(string $tag, string $msg = ''): void
{
    if ($msg === '') {
        [$tag, $msg] = ['app', $tag];
    }
    logLine('ERROR', $tag, $msg);
}

function divider(): string
{
    return '━━━━━━━━━━━━';
}
