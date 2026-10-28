<?php

final class GalleryDl
{
    public const MAX = 20;
    public const TMP = 'nl_gdl_';

    private const SITES = ['instagram', 'pinterest'];
    private const VIDEO = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'm3u8', 'mpd', 'gif'];
    private const IMAGE = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'heif'];

    public static function supports(string $site): bool
    {
        return in_array($site, self::SITES, true);
    }

    public static function bin(): ?string
    {
        static $bin = false;
        if ($bin !== false) {
            return $bin;
        }
        $try = (string) env('GALLERYDL_BIN', '');
        if ($try !== '' && is_executable($try)) {
            return $bin = $try;
        }
        foreach (['/usr/local/bin/gallery-dl', '/usr/bin/gallery-dl'] as $p) {
            if (is_executable($p)) {
                return $bin = $p;
            }
        }
        $found = function_exists('shell_exec') ? trim((string) @shell_exec('command -v gallery-dl 2>/dev/null')) : '';
        return $bin = ($found !== '' ? $found : null);
    }

    public static function images(string $url, int $depth = 0, ?string &$raw = null, ?string &$final = null): array
    {
        $bin = self::bin();
        if ($bin === null) {
            logWarn('dl', 'gallery-dl not found, photo download disabled');
            return [];
        }
        $url   = self::resolve($url);
        $final = $url;
        $res   = self::list($bin, $url);
        $raw  = $res['out'];
        $sets = self::parse($res['out']);
        $host = (string) parse_url($url, PHP_URL_HOST);
        logInfo('dl', "gallery-dl {$host}: count=" . count($sets));
        if ($sets === [] && $depth < 2 && ($next = self::queued($res['out'])) !== null && $next !== $url) {
            logInfo('dl', "gallery-dl {$host}: queued -> " . (string) parse_url($next, PHP_URL_HOST));
            return self::images($next, $depth + 1, $raw, $final);
        }
        if ($sets === []) {
            logWarn('dl', "gallery-dl {$host} failed (exit {$res['code']}): " . self::lastLine($res['err']));
        }
        return $sets;
    }

    public static function photos(string $url, int $max = self::MAX): array
    {
        $final = $url;
        $sets  = self::images($url, 0, $raw, $final);
        if ($sets === []) {
            return ['files' => [], 'total' => 0];
        }
        $dir = sys_get_temp_dir() . '/' . self::TMP . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['files' => [], 'total' => count($sets)];
        }
        self::download($final, $dir, 120, Downloader::BOT_API_BYTES, $max, true);
        $files = (array) glob($dir . '/*');
        natsort($files);
        $out = [];
        foreach ($files as $f) {
            $ext = strtolower(pathinfo((string) $f, PATHINFO_EXTENSION));
            if (is_file($f) && !str_contains((string) $f, '.part') && (int) filesize($f) > 0 && in_array($ext, self::IMAGE, true)) {
                $out[] = $f;
            }
        }
        if ($out === []) {
            self::cleanup([$dir . '/x']);
        }
        return ['files' => $out, 'total' => count($sets)];
    }

    public static function cleanup(array $files): void
    {
        foreach ($files as $f) {
            $d = dirname((string) $f);
            if (str_starts_with(basename($d), self::TMP)) {
                foreach ((array) glob($d . '/*') as $x) {
                    @unlink((string) $x);
                }
                @rmdir($d);
            } else {
                @unlink((string) $f);
            }
        }
    }

    private static function lastLine(string $err): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $err)), static fn($l) => $l !== ''));
        return mb_substr((string) end($lines), 0, 300);
    }

    private static function list(string $bin, string $url): array
    {
        $args = [$bin, '--config-ignore', '-j', '-o', 'timeout=15'];
        if (($jar = Downloader::cookiesFile()) !== null) {
            $args[] = '--cookies';
            $args[] = $jar;
        }
        $proxy = (string) env('DL_PROXY', '');
        if ($proxy !== '') {
            $args[] = '--proxy';
            $args[] = $proxy;
        }
        $args[] = $url;
        return Downloader::exec($args, 45);
    }

    public static function kinds(string $url): array
    {
        $key = 'gdl_kinds:' . md5($url);
        $hit = Store::get('cache', $key);
        if (is_array($hit) && time() - (int) ($hit['at'] ?? 0) < 1800) {
            return (array) $hit['v'];
        }
        $bin = self::bin();
        if ($bin === null) {
            return [];
        }
        $res  = self::list($bin, self::resolve($url));
        $kind = self::kindsOf($res['out']);
        logInfo('dl', 'gallery-dl ' . (string) parse_url($url, PHP_URL_HOST) . ' kinds=' . implode('', $kind));
        Store::set('cache', $key, ['at' => time(), 'v' => $kind]);
        return $kind;
    }

    public static function kindsOf(string $json): array
    {
        $data = json_decode(trim($json), true);
        $out  = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (!is_array($row) || ($row[0] ?? null) !== 3) {
                continue;
            }
            $url = (string) ($row[1] ?? '');
            $ext = strtolower((string) (is_array($row[2] ?? null) ? ($row[2]['extension'] ?? '') : ''));
            $ext = $ext !== '' ? $ext : strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (str_starts_with($url, 'ytdl:') || in_array($ext, self::VIDEO, true)) {
                $out[] = 'v';
            } elseif (in_array($ext, self::IMAGE, true)) {
                $out[] = 'p';
            }
        }
        return array_slice($out, 0, self::MAX);
    }

    public static function download(string $url, string $dir, int $timeout, int $limit, int $max = self::MAX, bool $photosOnly = false): array
    {
        $bin = self::bin();
        if ($bin === null) {
            logWarn('dl', 'gallery-dl not found, album download disabled');
            return ['code' => -1, 'out' => '', 'err' => 'gallery-dl not found'];
        }
        $args = [$bin, '--config-ignore', '-D', $dir, '-f', '{num:>02}_{filename}.{extension}', '--range', '1-' . $max, '--filesize-max', max(1, (int) ($limit / 1048576)) . 'M', '-o', 'timeout=20'];
        if ($photosOnly) {
            array_push($args, '--filter', "extension in ('jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'heif')");
        }
        if (($jar = Downloader::cookiesFile()) !== null) {
            $args[] = '--cookies';
            $args[] = $jar;
        }
        $proxy = (string) env('DL_PROXY', '');
        if ($proxy !== '') {
            $args[] = '--proxy';
            $args[] = $proxy;
        }
        $args[] = $url;
        $res = Downloader::exec($args, $timeout);
        if ($res['code'] !== 0) {
            logWarn('dl', 'gallery-dl album ' . (string) parse_url($url, PHP_URL_HOST) . " exit {$res['code']}: " . self::lastLine($res['err']));
        }
        return $res;
    }

    public static function queued(string $json): ?string
    {
        $data = json_decode(trim($json), true);
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row) && ($row[0] ?? null) === 6 && preg_match('#^https?://#', (string) ($row[1] ?? ''))) {
                return (string) $row[1];
            }
        }
        return null;
    }

    private static function resolve(string $url): string
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'pin.it') {
            return $url;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => Scrape::UA, CURLOPT_NOBODY => true]);
        curl_exec($ch);
        $end = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return preg_match('#/pin/(?:[\w-]*?--)?(\d+)#', $end, $m) ? 'https://www.pinterest.com/pin/' . $m[1] . '/' : $url;
    }

    public static function parse(string $json): array
    {
        $data = json_decode(trim($json), true);
        if (!is_array($data)) {
            return [];
        }
        $urls = [];
        foreach ($data as $row) {
            if (!is_array($row) || ($row[0] ?? null) !== 3) {
                continue;
            }
            $url  = (string) ($row[1] ?? '');
            $meta = is_array($row[2] ?? null) ? $row[2] : [];
            $ext  = strtolower((string) ($meta['extension'] ?? pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)));
            if (str_starts_with($url, 'ytdl:') || in_array($ext, self::VIDEO, true)) {
                return [];
            }
            if (in_array($ext, self::IMAGE, true) && preg_match('#^https?://#', $url)) {
                $urls[] = $url;
            }
        }
        return array_map(static fn(string $u): array => [$u], array_slice(Scrape::unique($urls), 0, self::MAX));
    }
}
