<?php

final class Photos
{
    private const SITES = ['instagram', 'pinterest', 'twitter', 'reddit'];
    private const UA    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const MAX   = 20;
    private const GROUP = 10;
    private const PHOTO_BYTES = 10 * 1048576;

    private static array $info = [];

    public static function supports(string $site): bool
    {
        return in_array($site, self::SITES, true);
    }

    public static function deliver(array $msg, array $link, int $mid, int $expect = 0): bool
    {
        $tg   = App::tg();
        $chat = $msg['chat']['id'];
        $tg->editMessage($chat, $mid, '🖼 دارم عکس‌هاش رو می‌گیرم...');
        $files = [];
        try {
            $files = self::collect($link['site'], $link['url'], self::MAX)['files'];
            logInfo('dl', "photo {$link['site']}: count=" . count($files) . " expect={$expect}");
            if (!$files) {
                return false;
            }
            if ($expect > 1 && count($files) < $expect) {
                logInfo('dl', "photo {$link['site']}: got " . count($files) . " of {$expect}, falling back to yt-dlp");
                return false;
            }
            $tg->action($chat, 'upload_photo');
            $cap  = self::caption($link);
            $sent = count($files) === 1 ? self::single($chat, $files[0], $cap, (int) $msg['message_id']) : self::album($chat, $files, $cap, (int) $msg['message_id']);
            if (!$sent) {
                return false;
            }
            Downloader::charge((int) $msg['from']['id']);
            Users::hit('dl:photo');
            $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => $mid], 8);
            return true;
        } catch (\Throwable $e) {
            logWarn('dl', $link['site'] . ' photo failed: ' . $e->getMessage());
            return false;
        } finally {
            self::cleanup($files);
        }
    }

    public static function inline(array $link, string $imid, int|string $store, int $uid): bool
    {
        $tg = App::tg();
        $tg->editInline($imid, '🖼 دارم عکسش رو می‌گیرم...');
        $files = [];
        try {
            $got   = self::collect($link['site'], $link['url'], 1);
            $files = $got['files'];
            foreach ($files as $f) {
                $r     = $tg->call('sendPhoto', ['chat_id' => $store, 'photo' => new CURLFile($f, 'image/jpeg', 'photo.jpg'), 'disable_notification' => true], 90);
                $sizes = (array) ($r['result']['photo'] ?? []);
                $fid   = (string) (end($sizes)['file_id'] ?? '');
                if ($fid === '') {
                    continue;
                }
                $more = $got['total'] > 1 ? "\n📚 " . fa((string) $got['total']) . ' تا عکس داره؛ بقیه رو تو پیوی بگیر.' : '';
                $e    = $tg->call('editMessageMedia', [
                    'inline_message_id' => $imid,
                    'media'             => ['type' => 'photo', 'media' => $fid, 'caption' => self::caption($link) . $more, 'parse_mode' => 'HTML'],
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
            return false;
        } catch (\Throwable $e) {
            logWarn('dl', $link['site'] . ' photo failed: ' . $e->getMessage());
            return false;
        } finally {
            self::cleanup($files);
        }
    }

    private static function caption(array $link): string
    {
        $info = GalleryDl::supports($link['site']) ? GalleryDl::info($link['url']) : self::$info;
        $bot  = App::botUsername();
        $head = (($info['title'] ?? '') !== '' ? '🖼 <b>' . h((string) $info['title']) . "</b>\n" : '')
            . (($info['uploader'] ?? '') !== '' ? '👤 ' . h((string) $info['uploader']) . "\n" : '');
        return $head . ($head !== '' ? "\n" : '') . "📥 <a href=\"https://t.me/{$bot}\">@{$bot}</a>";
    }

    private static function collect(string $site, string $url, int $max): array
    {
        if (GalleryDl::supports($site)) {
            $got = GalleryDl::photos($url, $max);
            $out = [];
            foreach ($got['files'] as $f) {
                if (($ok = self::prepared((string) $f)) !== null) {
                    $out[] = $ok;
                }
            }
            return ['files' => $out, 'total' => $got['total']];
        }
        self::$info = [];
        $sets = self::find($site, $url);
        $out  = [];
        foreach (array_slice($sets, 0, $max) as $cands) {
            foreach ($cands as $u) {
                if (($b = self::fetch($u)) === null) {
                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'nl_ph_');
                if ($tmp !== false && file_put_contents($tmp, $b) !== false) {
                    $out[] = $tmp;
                }
                break;
            }
        }
        return ['files' => $out, 'total' => count($sets)];
    }

    private static function prepared(string $f): ?string
    {
        $b = (string) @file_get_contents($f);
        if ($b === '' || strlen($b) < 800 || str_starts_with($b, 'GIF') || self::isMp4($b)) {
            @unlink($f);
            return null;
        }
        if (self::isHeic($b) || in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['heic', 'avif'], true)) {
            $jpg = self::heicToJpeg($b);
            @unlink($f);
            if ($jpg === null) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'nl_ph_');
            return $tmp !== false && file_put_contents($tmp, $jpg) !== false ? $tmp : null;
        }
        if (strlen($b) > self::PHOTO_BYTES || @getimagesizefromstring($b) === false) {
            @unlink($f);
            return null;
        }
        return $f;
    }

    private static function cleanup(array $files): void
    {
        GalleryDl::cleanup($files);
    }

    private static function find(string $site, string $url): array
    {
        return match ($site) {
            'twitter' => self::twitter($url),
            'reddit'  => self::reddit($url),
            default   => [],
        };
    }

    private static function twitter(string $url): array
    {
        if (!preg_match('#/status(?:es)?/(\d+)#', $url, $m)) {
            return [];
        }
        $out = [];
        $j   = Http::getJson('https://api.fxtwitter.com/status/' . $m[1], ['User-Agent: NulliniBot'], 15);
        self::$info = ['title' => mb_substr(trim((string) ($j['tweet']['text'] ?? '')), 0, 800), 'uploader' => mb_substr(trim((string) ($j['tweet']['author']['name'] ?? '')), 0, 80)];
        foreach ((array) ($j['tweet']['media']['photos'] ?? []) as $p) {
            if (!empty($p['url'])) {
                $out[] = [$p['url'] . (str_contains($p['url'], '?') ? '' : '?name=orig'), $p['url']];
            }
        }
        if (!$out) {
            $j = Http::getJson('https://api.vxtwitter.com/Twitter/status/' . $m[1], ['User-Agent: NulliniBot'], 15);
            self::$info = ['title' => mb_substr(trim((string) ($j['text'] ?? '')), 0, 800), 'uploader' => mb_substr(trim((string) ($j['user_name'] ?? '')), 0, 80)];
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
        self::$info = ['title' => mb_substr(trim((string) ($p['title'] ?? '')), 0, 800), 'uploader' => mb_substr(trim((string) ($p['author'] ?? '')), 0, 80)];
        $out = [];
        if (!empty($p['gallery_data']['items']) && is_array($p['media_metadata'] ?? null)) {
            foreach ($p['gallery_data']['items'] as $it) {
                $mm = $p['media_metadata'][$it['media_id'] ?? ''] ?? null;
                $u  = (string) ($mm['s']['u'] ?? $mm['s']['gif'] ?? '');
                if ($u !== '' && ($mm['e'] ?? 'Image') !== 'RedditVideo') {
                    $out[] = [Scrape::unescape($u)];
                }
            }
            return $out;
        }
        $direct = (string) ($p['url_overridden_by_dest'] ?? $p['url'] ?? '');
        $prev   = Scrape::unescape((string) ($p['preview']['images'][0]['source']['url'] ?? ''));
        if (preg_match('#\.(jpe?g|png|gif|webp)(\?|$)#i', $direct)) {
            return [self::unique([$direct, $prev])];
        }
        return $prev !== '' && empty($p['is_video']) ? [[$prev]] : [];
    }

    private static function unique(array $urls): array
    {
        return Scrape::unique($urls);
    }

    private static function fetch(string $url): ?string
    {
        $r = Http::request('GET', $url, ['timeout' => 40, 'headers' => ['User-Agent: ' . self::UA, 'Accept: image/avif,image/webp,image/*,*/*']]);
        $b = $r['body'];
        if ($b === null || $r['code'] >= 400 || strlen($b) < 800 || strlen($b) > self::PHOTO_BYTES || str_starts_with($b, 'GIF') || self::isMp4($b)) {
            return null;
        }
        if (self::isHeic($b)) {
            return self::heicToJpeg($b);
        }
        return @getimagesizefromstring($b) === false ? null : $b;
    }

    private static function isMp4(string $b): bool
    {
        return substr($b, 4, 4) === 'ftyp' && !self::isHeic($b);
    }

    private static function isHeic(string $b): bool
    {
        return substr($b, 4, 4) === 'ftyp' && in_array(substr($b, 8, 4), ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'], true);
    }

    public static function convertDir(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $f) {
            if (!is_file($f) || !in_array(strtolower(pathinfo((string) $f, PATHINFO_EXTENSION)), ['heic', 'heif', 'avif'], true)) {
                continue;
            }
            $jpg = self::heicToJpeg((string) file_get_contents($f));
            if ($jpg !== null && file_put_contents((string) preg_replace('/\.\w+$/', '.jpg', (string) $f), $jpg) !== false) {
                @unlink($f);
            }
        }
    }

    private static function heicToJpeg(string $b): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'nl_hc_');
        if ($base === false) {
            return null;
        }
        $in  = $base . '.heic';
        $out = $base . '.jpg';
        try {
            if (!rename($base, $in) || file_put_contents($in, $b) === false) {
                return null;
            }
            @exec('timeout 30 ffmpeg -y -v error -i ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>&1', $o, $rc);
            $jpg = is_file($out) ? (string) file_get_contents($out) : '';
            if ($jpg !== '' && @getimagesizefromstring($jpg) !== false) {
                return $jpg;
            }
            logWarn('dl', 'heic convert failed (ffmpeg exit ' . $rc . ')');
            return null;
        } finally {
            @unlink($base);
            @unlink($in);
            @unlink($out);
        }
    }

    private static function single(int|string $chat, string $file, string $cap, int $replyTo): bool
    {
        return App::tg()->sendPhotoBytes($chat, (string) file_get_contents($file), $cap, $replyTo);
    }

    private static function album(int|string $chat, array $files, string $cap, int $replyTo): bool
    {
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
    }
}
