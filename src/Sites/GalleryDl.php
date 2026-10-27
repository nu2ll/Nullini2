<?php

final class GalleryDl
{
    public const MAX = 20;

    private const SITES = ['instagram', 'pinterest'];
    private const VIDEO = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'm3u8', 'mpd'];
    private const IMAGE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

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

    public static function images(string $url, int $depth = 0): array
    {
        $bin = self::bin();
        if ($bin === null) {
            logWarn('photo', 'gallery-dl is not installed');
            return [];
        }
        $url  = self::resolve($url);
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
        $res  = Downloader::exec($args, 45);
        $sets = self::parse($res['out']);
        logInfo('photo', 'gallery-dl ' . (string) parse_url($url, PHP_URL_HOST) . ': exit=' . $res['code'] . ' sets=' . count($sets));
        if ($sets === [] && $depth < 2 && ($next = self::queued($res['out'])) !== null && $next !== $url) {
            logInfo('photo', 'gallery-dl queued -> ' . (string) parse_url($next, PHP_URL_HOST));
            return self::images($next, $depth + 1);
        }
        if ($sets === []) {
            logWarn('photo', 'gallery-dl empty: rows=' . self::summary($res['out']) . ' err=' . substr(trim($res['err']), -300));
        }
        return $sets;
    }

    private static function summary(string $json): string
    {
        $data = json_decode(trim($json), true);
        $out  = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta  = is_array($row[2] ?? null) ? $row[2] : [];
            $label = 't' . (string) ($row[0] ?? '?');
            if (($row[0] ?? null) === 3) {
                $label .= ':' . (string) ($meta['extension'] ?? pathinfo((string) parse_url((string) ($row[1] ?? ''), PHP_URL_PATH), PATHINFO_EXTENSION));
            } elseif (($row[0] ?? null) === -1) {
                $label .= ':' . substr((string) ($row[1]['message'] ?? ''), 0, 80);
            }
            $out[] = $label;
        }
        return $out === [] ? 'unparsed:' . substr((string) preg_replace('/\\s+/', ' ', $json), 0, 200) : implode(' ', $out);
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
