<?php

final class Photos
{
    private const SITES = ['instagram', 'pinterest', 'twitter', 'reddit'];
    private const UA    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const MAX   = 20;
    private const GROUP = 10;

    public static function supports(string $site): bool
    {
        return in_array($site, self::SITES, true);
    }

    public static function deliver(array $msg, array $link, int $mid, int $expect = 0): bool
    {
        $tg   = App::tg();
        $chat = $msg['chat']['id'];
        $tg->editMessage($chat, $mid, '🖼 دارم عکس‌هاش رو می‌گیرم...');
        try {
            $sets = array_slice(self::find($link['site'], $link['url']), 0, self::MAX);
        } catch (\Throwable $e) {
            logWarn('photo', $link['site'] . ': ' . $e->getMessage());
            return false;
        }
        logInfo('photo', $link['site'] . ': found ' . count($sets) . ' image(s), expect ' . $expect);
        if (!$sets) {
            logInfo('photo', $link['site'] . ' no images found');
            return false;
        }
        if ($expect > 1 && count($sets) < $expect) {
            logInfo('photo', $link['site'] . ': found ' . count($sets) . ' of ' . $expect . ', falling back to yt-dlp');
            return false;
        }
        $tg->action($chat, 'upload_photo');
        $cap  = '🖼 از ' . $link['name'];
        $sent = count($sets) === 1 ? self::single($chat, $sets[0], $cap, (int) $msg['message_id']) : self::album($chat, $sets, $cap, (int) $msg['message_id']);
        if (!$sent) {
            return false;
        }
        Downloader::charge((int) $msg['from']['id']);
        Users::hit('dl:photo');
        $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => $mid], 8);
        return true;
    }

    public static function inline(array $link, string $imid, int|string $store, int $uid): bool
    {
        $tg = App::tg();
        $tg->editInline($imid, '🖼 دارم عکسش رو می‌گیرم...');
        try {
            $sets = self::find($link['site'], $link['url']);
        } catch (\Throwable $e) {
            logWarn('photo', $link['site'] . ': ' . $e->getMessage());
            return false;
        }
        foreach (array_slice($sets, 0, 1) as $cands) {
            foreach ($cands as $u) {
                $b = self::fetch($u);
                if ($b === null) {
                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'nl_ph_');
                if ($tmp === false || file_put_contents($tmp, $b) === false) {
                    return false;
                }
                try {
                    $r = $tg->call('sendPhoto', ['chat_id' => $store, 'photo' => new CURLFile($tmp, 'image/jpeg', 'photo.jpg'), 'disable_notification' => true], 90);
                } finally {
                    @unlink($tmp);
                }
                $sizes = (array) ($r['result']['photo'] ?? []);
                $fid   = (string) (end($sizes)['file_id'] ?? '');
                if ($fid === '') {
                    continue;
                }
                $more = count($sets) > 1 ? "\n📚 " . fa((string) count($sets)) . ' تا عکس داره؛ بقیه رو تو پیوی بگیر.' : '';
                $e    = $tg->call('editMessageMedia', [
                    'inline_message_id' => $imid,
                    'media'             => ['type' => 'photo', 'media' => $fid, 'caption' => '🖼 از ' . $link['name'] . $more, 'parse_mode' => 'HTML'],
                ], 30);
                if (env('DL_STORE_CHAT', '') === '' && !empty($r['result']['message_id'])) {
                    $tg->call('deleteMessage', ['chat_id' => $store, 'message_id' => (int) $r['result']['message_id']], 8);
                }
                if (!empty($e['ok'])) {
                    Downloader::charge($uid);
                    Users::hit('dl:photo:guest');
                    return true;
                }
                return false;
            }
        }
        return false;
    }

    public static function igCode(string $url): ?string
    {
        return preg_match('#instagram\.com/(?:[\w.]+/)?(?:p|reel|reels|tv)/([\w-]{5,})#i', $url, $m) ? $m[1] : null;
    }

    public static function unescape(string $s): string
    {
        $s = preg_replace('#\\\\+u0026#', '&', $s) ?? $s;
        $s = preg_replace('#\\\\+/#', '/', $s) ?? $s;
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function find(string $site, string $url): array
    {
        return match ($site) {
            'instagram' => self::instagram($url),
            'pinterest' => self::pinterest($url),
            'twitter'   => self::twitter($url),
            'reddit'    => self::reddit($url),
            default     => [],
        };
    }

    private static function instagram(string $url): array
    {
        $code = self::igCode($url);
        if ($code === null) {
            return [];
        }
        $r = Http::request('GET', "https://www.instagram.com/p/{$code}/embed/captioned/", ['timeout' => 15, 'headers' => ['User-Agent: ' . self::UA, 'Accept-Language: en-US,en;q=0.9']]);
        $html = (string) ($r['body'] ?? '');
        $out  = [];
        logInfo('photo', "ig embed {$code}: http={$r['code']} len=" . strlen($html) . ' display_url=' . substr_count($html, 'display_url') . ' video=' . (preg_match('#is_video|video_url#', $html) ? '1' : '0') . ' sidecar=' . (str_contains($html, 'edge_sidecar_to_children') ? '1' : '0'));
        if (preg_match_all('#display_url[\\\\"\s:]+(https?:(?:[^"\\\\]|\\\\+/|\\\\+u0026)+)#', $html, $m)) {
            foreach ($m[1] as $u) {
                $out[] = self::unescape($u);
            }
        }
        $out = self::unique($out);
        if (count($out) <= 1 && preg_match('#is_video[\\\\"\s:]+true|video_url[\\\\"\s:]+https#', $html)) {
            return [];
        }
        if (!$out && preg_match('#class="EmbeddedMediaImage"[^>]*\ssrc="([^"]+)"#', $html, $m)) {
            $out[] = self::unescape($m[1]);
        }
        if (!$out) {
            $r = Http::request('GET', "https://www.instagram.com/p/{$code}/", ['timeout' => 12, 'headers' => ['User-Agent: facebookexternalhit/1.1']]);
            if (($og = self::og((string) ($r['body'] ?? ''))) !== '') {
                $out[] = $og;
            }
        }
        return array_map(static fn($u) => [$u], self::unique($out));
    }

    private static function pinterest(string $url): array
    {
        if (preg_match('#/pin/(?:[\w-]*?--)?(\d+)#', $url, $pu)) {
            $url = 'https://www.pinterest.com/pin/' . $pu[1] . '/';
        }
        $r = Http::request('GET', $url, ['timeout' => 15, 'headers' => [
            'User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
        ]]);
        $pin  = self::pinRelay((string) ($r['body'] ?? ''));
        $sets = $pin !== null ? self::pinRelaySets($pin) : [];
        logInfo('photo', 'pin relay: ' . ($pin !== null ? 'yes' : 'no') . ' http=' . $r['code'] . ' sets=' . count($sets));
        return $sets;
    }

    public static function pinRelay(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*data-relay-response="true"[^>]*>(.*?)</script>#s', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $raw) {
            $j   = json_decode(trim($raw), true);
            $pin = is_array($j) && ($j['requestParameters']['name'] ?? '') === 'CloseupDetailQuery'
                ? ($j['response']['data']['v3GetPinQuery']['data'] ?? null) : null;
            if (is_array($pin)) {
                return $pin;
            }
        }
        return null;
    }

    private static function pinUrls(mixed $node): array
    {
        $found = [];
        $walk  = static function ($n) use (&$walk, &$found): void {
            if (is_array($n)) {
                foreach ($n as $v) {
                    $walk($v);
                }
            } elseif (is_string($n) && preg_match('#^https://i\.pinimg\.com/\S+\.(?:jpe?g|png|webp|gif)(?:\?\S*)?$#i', $n)) {
                $found[] = $n;
            }
        };
        $walk($node);
        $rank = static function (string $u): int {
            if (str_contains($u, '/originals/')) {
                return 100000;
            }
            return preg_match('#i\.pinimg\.com/(\d+)x#', $u, $mm) ? (int) $mm[1] : 0;
        };
        usort($found, static fn($a, $b): int => $rank($b) <=> $rank($a));
        return self::unique($found);
    }

    public static function pinRelaySets(array $pin): array
    {
        if (str_contains((string) json_encode($pin), 'videoDataV2')) {
            return [];
        }
        $out   = [];
        $slots = $pin['carouselData']['carouselSlots'] ?? $pin['carousel_data']['carousel_slots'] ?? null;
        foreach (is_array($slots) ? $slots : [] as $slot) {
            if (($c = self::pinUrls($slot)) !== []) {
                $out[] = $c;
            }
        }
        if (count($out) > 1) {
            return array_slice($out, 0, self::MAX);
        }
        $out   = [];
        $pages = $pin['storyPinData']['pages'] ?? null;
        foreach (is_array($pages) ? $pages : [] as $page) {
            if (($c = self::pinUrls($page['blocks'][0] ?? $page)) !== []) {
                $out[] = $c;
            }
        }
        if (count($out) > 1) {
            return array_slice($out, 0, self::MAX);
        }
        $one = self::pinUrls([$pin['imageSpec_orig'] ?? null, $pin['images'] ?? null, $pin['imageLargeUrl'] ?? null]);
        return $one !== [] ? [$one] : [];
    }

    private static function twitter(string $url): array
    {
        if (!preg_match('#/status(?:es)?/(\d+)#', $url, $m)) {
            return [];
        }
        $out = [];
        $j   = Http::getJson('https://api.fxtwitter.com/status/' . $m[1], ['User-Agent: NulliniBot'], 15);
        foreach ((array) ($j['tweet']['media']['photos'] ?? []) as $p) {
            if (!empty($p['url'])) {
                $out[] = [$p['url'] . (str_contains($p['url'], '?') ? '' : '?name=orig'), $p['url']];
            }
        }
        if (!$out) {
            $j = Http::getJson('https://api.vxtwitter.com/Twitter/status/' . $m[1], ['User-Agent: NulliniBot'], 15);
            foreach ((array) ($j['media_extended'] ?? []) as $p) {
                if (($p['type'] ?? '') === 'image' && !empty($p['url'])) {
                    $out[] = [$p['url']];
                }
            }
        }
        return $out;
    }

    private static function reddit(string $url): array
    {
        $id = null;
        if (preg_match('#/comments/(\w+)#', $url, $m) || preg_match('#redd\.it/(\w+)#', $url, $m)) {
            $id = $m[1];
        } elseif (preg_match('#/s/\w+#', $url)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => self::UA, CURLOPT_NOBODY => true]);
            curl_exec($ch);
            $end = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if (preg_match('#/comments/(\w+)#', $end, $m)) {
                $id = $m[1];
            }
        }
        if ($id === null) {
            return [];
        }
        $j = Http::getJson("https://www.reddit.com/comments/{$id}.json?raw_json=1", ['User-Agent: web:nullini:4.3 (by /u/nullini)'], 15);
        $p = $j[0]['data']['children'][0]['data'] ?? null;
        if (!is_array($p)) {
            return [];
        }
        $p   = $p['crosspost_parent_list'][0] ?? $p;
        $out = [];
        if (!empty($p['gallery_data']['items']) && is_array($p['media_metadata'] ?? null)) {
            foreach ($p['gallery_data']['items'] as $it) {
                $mm = $p['media_metadata'][$it['media_id'] ?? ''] ?? null;
                $u  = (string) ($mm['s']['u'] ?? $mm['s']['gif'] ?? '');
                if ($u !== '' && ($mm['e'] ?? 'Image') !== 'RedditVideo') {
                    $out[] = [self::unescape($u)];
                }
            }
            return $out;
        }
        $direct = (string) ($p['url_overridden_by_dest'] ?? $p['url'] ?? '');
        $prev   = self::unescape((string) ($p['preview']['images'][0]['source']['url'] ?? ''));
        if (preg_match('#\.(jpe?g|png|gif|webp)(\?|$)#i', $direct)) {
            return [self::unique([$direct, $prev])];
        }
        return $prev !== '' && empty($p['is_video']) ? [[$prev]] : [];
    }

    private static function og(string $html): string
    {
        if (preg_match('#<meta[^>]+(?:property|name)=["\']og:image["\'][^>]*content=["\']([^"\']+)#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']og:image["\']#i', $html, $m)) {
            return self::unescape($m[1]);
        }
        return '';
    }

    private static function unique(array $urls): array
    {
        $seen = [];
        $out  = [];
        foreach ($urls as $u) {
            $k = (string) strtok((string) $u, '?');
            if ($u !== '' && preg_match('#^https?://#', $u) && !isset($seen[$k])) {
                $seen[$k] = true;
                $out[]    = $u;
            }
        }
        return $out;
    }

    private static function fetch(string $url): ?string
    {
        $r = Http::request('GET', $url, ['timeout' => 25, 'headers' => ['User-Agent: ' . self::UA, 'Accept: image/avif,image/webp,image/*,*/*']]);
        $b = $r['body'];
        if ($b === null || $r['code'] >= 400 || strlen($b) < 800 || strlen($b) > 10 * 1048576 || @getimagesizefromstring($b) === false) {
            return null;
        }
        return $b;
    }

    private static function single(int|string $chat, array $cands, string $cap, int $replyTo): bool
    {
        foreach ($cands as $u) {
            $b = self::fetch($u);
            if ($b !== null && App::tg()->sendPhotoBytes($chat, $b, $cap, $replyTo)) {
                return true;
            }
        }
        return false;
    }

    private static function album(int|string $chat, array $sets, string $cap, int $replyTo): bool
    {
        $files = [];
        try {
            foreach ($sets as $cands) {
                foreach ($cands as $u) {
                    if (($b = self::fetch($u)) !== null) {
                        $tmp = tempnam(sys_get_temp_dir(), 'nl_ph_');
                        if ($tmp !== false && file_put_contents($tmp, $b) !== false) {
                            $files[] = $tmp;
                        }
                        break;
                    }
                }
            }
            if (!$files) {
                return false;
            }
            if (count($files) === 1) {
                return App::tg()->sendPhotoBytes($chat, (string) file_get_contents($files[0]), $cap, $replyTo);
            }
            $ok = false;
            foreach (array_chunk($files, self::GROUP) as $g => $chunk) {
                $capG = $g === 0 ? $cap : '';
                if (count($chunk) === 1) {
                    $ok = App::tg()->sendPhotoBytes($chat, (string) file_get_contents($chunk[0]), $capG, $replyTo) || $ok;
                    continue;
                }
                $media  = [];
                $params = ['chat_id' => $chat, 'reply_parameters' => ['message_id' => $replyTo, 'allow_sending_without_reply' => true]];
                foreach ($chunk as $i => $f) {
                    $media[]         = ['type' => 'photo', 'media' => "attach://p{$i}"] + ($i === 0 && $capG !== '' ? ['caption' => $capG, 'parse_mode' => 'HTML'] : []);
                    $params["p{$i}"] = new CURLFile($f, 'image/jpeg', "p{$i}.jpg");
                }
                $params['media'] = $media;
                $r = App::tg()->call('sendMediaGroup', $params, 180);
                if (!empty($r['ok'])) {
                    $ok = true;
                    continue;
                }
                foreach ($chunk as $i => $f) {
                    $ok = App::tg()->sendPhotoBytes($chat, (string) file_get_contents($f), $i === 0 ? $capG : '', $replyTo) || $ok;
                }
            }
            return $ok;
        } finally {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    }
}
