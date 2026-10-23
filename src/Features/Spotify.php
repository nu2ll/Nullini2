<?php

final class Spotify
{
    public static function track(string $url): array
    {
        $id = self::id($url);
        if ($id === null) {
            return ['error' => preg_match('#/(album|playlist|artist|show|episode)/#', $url)
                ? "🎵 فعلاً فقط لینک تک‌آهنگ اسپاتیفای رو می‌گیرم؛\nآلبوم و پلی‌لیست نه."
                : '🤷 این لینک اسپاتیفای رو نشناختم.'];
        }
        $hit = Store::get('cache', 'sp:' . $id);
        if (is_array($hit)) {
            return $hit;
        }
        $t = self::embed($id) ?? self::page($id);
        if ($t === null || $t['title'] === '') {
            logWarn('spotify', "meta failed {$id}");
            return ['error' => "❌ نتونستم مشخصات این آهنگ رو از اسپاتیفای بگیرم 😕\nیه‌کم دیگه امتحان کن."];
        }
        $t['id'] = $id;
        Store::set('cache', 'sp:' . $id, $t);
        return $t;
    }

    private static function id(string $url): ?string
    {
        if (preg_match('#spotify\.com/(?:intl-[\w-]+/)?(?:embed/)?track/([A-Za-z0-9]{22})#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#(spotify\.link|spotify\.app\.link)/#i', $url)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36']);
            $body = (string) curl_exec($ch);
            $end  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if (preg_match('#open\.spotify\.com/(?:intl-[\w-]+/)?track/([A-Za-z0-9]{22})#', $end . ' ' . $body, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private static function embed(string $id): ?array
    {
        $r = Http::request('GET', 'https://open.spotify.com/embed/track/' . $id, ['timeout' => 12]);
        if ($r['body'] === null || !preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $r['body'], $m)) {
            return null;
        }
        $e = json_decode($m[1], true)['props']['pageProps']['state']['data']['entity'] ?? null;
        if (!is_array($e) || empty($e['name'])) {
            return null;
        }
        $artists = array_values(array_filter(array_map(static fn($a) => (string) ($a['name'] ?? ''), (array) ($e['artists'] ?? []))));
        $imgs    = (array) ($e['visualIdentity']['image'] ?? $e['coverArt']['sources'] ?? []);
        usort($imgs, static fn($a, $b) => ((int) ($b['maxWidth'] ?? $b['width'] ?? 0)) <=> ((int) ($a['maxWidth'] ?? $a['width'] ?? 0)));
        return [
            'title'    => (string) $e['name'],
            'artist'   => implode(', ', $artists) ?: (string) ($e['subtitle'] ?? ''),
            'duration' => (int) round(((int) ($e['duration'] ?? 0)) / 1000),
            'cover'    => (string) ($imgs[0]['url'] ?? ''),
        ];
    }

    private static function page(string $id): ?array
    {
        $j = Http::getJson('https://open.spotify.com/oembed?url=' . rawurlencode('https://open.spotify.com/track/' . $id));
        $r = Http::request('GET', 'https://open.spotify.com/track/' . $id, ['timeout' => 12]);
        $artist = '';
        $title  = (string) ($j['title'] ?? '');
        if ($r['body'] !== null && preg_match('#<title>(.+?)</title>#s', $r['body'], $m)) {
            $t = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^(.+?) - song(?: and lyrics)? by (.+?) \| Spotify/u', $t, $tm)) {
                [$title, $artist] = [$tm[1], $tm[2]];
            }
        }
        return $title === '' ? null : ['title' => $title, 'artist' => $artist, 'duration' => 0, 'cover' => (string) ($j['thumbnail_url'] ?? '')];
    }

    public static function remember(array $t, string $yt): void
    {
        if (!empty($t['id']) && ($t['yt'] ?? '') !== $yt) {
            Store::set('cache', 'sp:' . $t['id'], $t + ['yt' => $yt]);
        }
    }

    public static function query(array $t): string
    {
        return trim(($t['artist'] !== '' ? $t['artist'] . ' - ' : '') . $t['title']);
    }
}
