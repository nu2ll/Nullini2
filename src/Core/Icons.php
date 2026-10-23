<?php

final class Icons
{
    public const DIR = '/tmp/nullini-ic';

    public static function path(string $dir, string $file): ?string
    {
        if (!preg_match('/^[A-Za-z0-9 _-]{2,60}$/', $dir) || !preg_match('/^[a-z0-9_]{2,60}$/', $file)) {
            return null;
        }
        $cache = self::DIR . '/' . $file . '.png';
        if (is_file($cache) && filesize($cache) > 100) {
            return $cache;
        }
        @mkdir(self::DIR, 0775, true);
        $ch = curl_init('https://raw.githubusercontent.com/microsoft/fluentui-emoji/main/assets/' . rawurlencode($dir) . '/3D/' . $file . '_3d.png');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6]);
        $png  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($png) || strncmp($png, "\x89PNG", 4) !== 0) {
            return null;
        }
        $raw = $cache . '.raw';
        $tmp = $cache . '.' . getmypid() . '.png';
        @file_put_contents($raw, $png);
        if (function_exists('exec')) {
            @exec('timeout 20 ffmpeg -y -v error -i ' . escapeshellarg($raw) . ' -vf scale=128:128:flags=lanczos ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
        }
        if (is_file($tmp) && filesize($tmp) > 100) {
            @rename($tmp, $cache);
        } else {
            @unlink($tmp);
            @rename($raw, $cache);
        }
        @unlink($raw);
        return is_file($cache) ? $cache : null;
    }

    public static function all(): array
    {
        $list = [];
        $html = (string) @file_get_contents(NULLINI_ROOT . '/public/app.html');
        if (preg_match_all("/IC\\('([A-Za-z0-9 _-]+)',\\s*'([a-z0-9_]+)'\\)/", $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $list[$x[2]] = $x[1];
            }
        }
        return $list;
    }

    public static function warm(): int
    {
        $n = 0;
        foreach (self::all() as $file => $dir) {
            if (self::path($dir, $file) !== null) {
                $n++;
            }
        }
        return $n;
    }
}
