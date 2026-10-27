<?php

/**
 * gallery-dl wrapper (same idea as yt-dlp, but for image posts).
 * Used for Instagram and Pinterest. images() returns "sets": one [url] per photo.
 * Posts that contain any video return [] so the yt-dlp path handles them.
 */
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

    public static function images(string $url): array
    {
        $bin = self::bin();
        if ($bin === null) {
            logWarn('photo', 'gallery-dl is not installed');
            return [];
        }
        $args = [$bin, '--config-ignore', '-q', '-j', '-o', 'timeout=15'];
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
        if ($sets === [] && $res['code'] !== 0) {
            $lines = array_filter(array_map('trim', explode("\n", $res['err'])));
            logWarn('photo', 'gallery-dl: ' . (string) end($lines));
        }
        return $sets;
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
