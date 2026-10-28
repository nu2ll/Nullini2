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

    private static function meta(string $url): array
    {
        $bin = self::bin();
        if ($bin === null) {
            return ['kinds' => [], 'urls' => [], 'title' => '', 'info' => []];
        }
        $res  = self::list($bin, self::resolve($url));
        $meta = ['kinds' => self::kindsOf($res['out']), 'urls' => self::pinUrls($res['out']), 'title' => self::pinTitle($res['out']), 'info' => self::pinInfo($res['out'])];
        logInfo('dl', 'gallery-dl ' . (string) parse_url($url, PHP_URL_HOST) . ' kinds=' . implode('', $meta['kinds']) . ' mp4=' . count($meta['urls']));
        return $meta;
    }

    public static function kinds(string $url): array
    {
        return (array) self::meta($url)['kinds'];
    }

    public static function videoUrls(string $url): array
    {
        return (array) self::meta($url)['urls'];
    }

    public static function title(string $url): string
    {
        return (string) self::meta($url)['title'];
    }

    public static function info(string $url): array
    {
        return (array) (self::meta($url)['info'] ?? []);
    }

    private static function pinMeta(array $row): ?array
    {
        $meta = ($row[0] ?? null) === 2 ? ($row[1] ?? null) : (($row[0] ?? null) === 3 ? ($row[2] ?? null) : null);
        return is_array($meta) ? $meta : null;
    }

    private static function hasVideoList(mixed $node): bool
    {
        return is_array($node) && is_array($node['videos']['video_list'] ?? null) && $node['videos']['video_list'] !== [];
    }

    public static function pinKinds(string $json): array
    {
        $data = json_decode(trim($json), true);
        foreach (is_array($data) ? $data : [] as $row) {
            $meta = is_array($row) ? self::pinMeta($row) : null;
            if ($meta === null) {
                continue;
            }
            $slots = $meta['carousel_data']['carousel_slots'] ?? null;
            if (is_array($slots) && $slots !== []) {
                $out = [];
                foreach ($slots as $slot) {
                    if (is_array($slot)) {
                        $out[] = self::hasVideoList($slot) ? 'v' : 'p';
                    }
                }
                return in_array('v', $out, true) ? array_slice($out, 0, self::MAX) : [];
            }
            if (self::hasVideoList($meta)) {
                return ['v'];
            }
        }
        return [];
    }

    private static function bestMp4(mixed $list): ?string
    {
        if (!is_array($list)) {
            return null;
        }
        $best = null;
        $area = -1;
        foreach ($list as $v) {
            $u = is_array($v) ? (string) ($v['url'] ?? '') : '';
            if (!preg_match('#^https?://\S+\.mp4(\?\S*)?$#i', $u)) {
                continue;
            }
            $a = (int) ($v['width'] ?? 0) * (int) ($v['height'] ?? 0);
            if ($a > $area) {
                $area = $a;
                $best = $u;
            }
        }
        if ($best === null) {
            foreach ($list as $v) {
                $u = is_array($v) ? (string) ($v['url'] ?? '') : '';
                if (preg_match('#^https?://\S+\.m3u8(\?\S*)?$#i', $u)) {
                    return $u;
                }
            }
        }
        return $best;
    }

    public static function pinUrls(string $json): array
    {
        $data = json_decode(trim($json), true);
        foreach (is_array($data) ? $data : [] as $row) {
            $meta = is_array($row) ? self::pinMeta($row) : null;
            if ($meta === null) {
                continue;
            }
            $slots = $meta['carousel_data']['carousel_slots'] ?? null;
            if (is_array($slots) && $slots !== []) {
                $out = [];
                foreach ($slots as $slot) {
                    if (is_array($slot) && self::hasVideoList($slot) && ($u = self::bestMp4($slot['videos']['video_list'])) !== null) {
                        $out[] = $u;
                    }
                }
                return array_slice($out, 0, self::MAX);
            }
            if (self::hasVideoList($meta)) {
                $u = self::bestMp4($meta['videos']['video_list']);
                return $u !== null ? [$u] : [];
            }
        }
        return [];
    }

    public static function pinTitle(string $json): string
    {
        $data = json_decode(trim($json), true);
        foreach (is_array($data) ? $data : [] as $row) {
            $meta = is_array($row) ? self::pinMeta($row) : null;
            if ($meta === null) {
                continue;
            }
            foreach (['title', 'grid_title', 'seo_title', 'description', 'closeup_description'] as $k) {
                if (($t = trim((string) ($meta[$k] ?? ''))) !== '') {
                    return mb_substr($t, 0, 200);
                }
            }
        }
        return '';
    }

    public static function pinInfo(string $json): array
    {
        $data = json_decode(trim($json), true);
        foreach (is_array($data) ? $data : [] as $row) {
            $meta = is_array($row) ? self::pinMeta($row) : null;
            if ($meta === null) {
                continue;
            }
            $slots = $meta['carousel_data']['carousel_slots'] ?? null;
            $slots = is_array($slots) ? array_values($slots) : [];
            $first = is_array($slots[0] ?? null) ? $slots[0] : [];
            $thumb = (string) ($meta['images']['orig']['url'] ?? $first['images']['orig']['url'] ?? '');
            $pin   = is_array($meta['pinner'] ?? null) ? $meta['pinner'] : [];
            $who   = trim((string) ($pin['full_name'] ?? '')) ?: trim((string) ($pin['username'] ?? ''));
            $dur   = 0;
            if ($slots === []) {
                foreach ((array) ($meta['videos']['video_list'] ?? []) as $v) {
                    $dur = max($dur, (int) round((int) (is_array($v) ? ($v['duration'] ?? 0) : 0) / 1000));
                }
            }
            $title = self::pinTitle($json);
            foreach ($slots as $slot) {
                foreach (is_array($slot) && $title === '' ? ['title', 'grid_title', 'description'] : [] as $k) {
                    if (($t = trim((string) ($slot[$k] ?? ''))) !== '') {
                        $title = mb_substr($t, 0, 200);
                        break 2;
                    }
                }
            }
            return ['title' => $title, 'uploader' => mb_substr($who, 0, 80), 'thumbnail' => $thumb, 'duration' => $dur];
        }
        return [];
    }

    public static function kindsOf(string $json): array
    {
        if (($pin = self::pinKinds($json)) !== []) {
            return $pin;
        }
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

    public static function download(string $url, string $dir, int $timeout, int $limit, int $max = self::MAX, bool $photosOnly = false, ?string $range = null): array
    {
        $bin = self::bin();
        if ($bin === null) {
            logWarn('dl', 'gallery-dl not found, album download disabled');
            return ['code' => -1, 'out' => '', 'err' => 'gallery-dl not found'];
        }
        $args = [$bin, '--config-ignore', '-D', $dir, '-f', '{num:>02}_{filename}.{extension}', '--range', $range ?? '1-' . $max, '--filesize-max', max(1, (int) ($limit / 1048576)) . 'M', '-o', 'timeout=20'];
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
        if (!is_array($data) || in_array('v', self::pinKinds($json), true)) {
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
