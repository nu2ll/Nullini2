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
        logInfo('photo', $link['site'] . ': urls=' . implode(' | ', array_map(static fn(array $c): string => substr((string) ($c[0] ?? ''), -45), array_slice($sets, 0, 4))));
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

    private static function find(string $site, string $url): array
    {
        return match ($site) {
            'instagram' => GalleryDl::images($url),
            'pinterest' => self::pinGif(GalleryDl::images($url)),
            'twitter'   => self::twitter($url),
            'reddit'    => self::reddit($url),
            default     => [],
        };
    }

    private static function pinGif(array $sets): array
    {
        return array_map(static function (array $c): array {
            $u = (string) ($c[0] ?? '');
            return preg_match('#^https://i\.pinimg\.com/originals/.+\.jpg$#', $u) ? array_merge([substr($u, 0, -3) . 'gif'], $c) : $c;
        }, $sets);
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

    private static function unique(array $urls): array
    {
        return Scrape::unique($urls);
    }

    private static function fetch(string $url): ?string
    {
        $r   = Http::request('GET', $url, ['timeout' => 40, 'headers' => ['User-Agent: ' . self::UA, 'Accept: image/avif,image/webp,image/*,*/*']]);
        $b   = $r['body'];
        $gif = $b !== null && str_starts_with($b, 'GIF');
        if (str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.gif')) {
            logInfo('photo', 'gif fetch code=' . $r['code'] . ' bytes=' . strlen((string) $b) . ' gif=' . ($gif ? '1' : '0'));
        }
        if ($b === null || $r['code'] >= 400 || strlen($b) < 800 || strlen($b) > ($gif ? 30 : 10) * 1048576) {
            return null;
        }
        if (self::isHeic($b)) {
            return self::heicToJpeg($b);
        }
        return @getimagesizefromstring($b) === false ? null : $b;
    }

    private static function isHeic(string $b): bool
    {
        return substr($b, 4, 4) === 'ftyp' && in_array(substr($b, 8, 4), ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'], true);
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
            foreach (['timeout 30 heif-convert -q 92 ', 'timeout 30 ffmpeg -y -v error -i '] as $cmd) {
                @unlink($out);
                @exec($cmd . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>&1', $o, $rc);
                $jpg = is_file($out) ? (string) file_get_contents($out) : '';
                if ($jpg !== '' && @getimagesizefromstring($jpg) !== false) {
                    return $jpg;
                }
            }
            logWarn('photo', 'heic convert failed');
            return null;
        } finally {
            @unlink($base);
            @unlink($in);
            @unlink($out);
        }
    }

    private static function single(int|string $chat, array $cands, string $cap, int $replyTo): bool
    {
        foreach ($cands as $u) {
            $b = self::fetch($u);
            if ($b === null) {
                continue;
            }
            logInfo('photo', 'single bytes=' . strlen($b) . ' head=' . bin2hex(substr($b, 0, 4)) . ' gif=' . (str_starts_with($b, 'GIF') ? '1' : '0'));
            $sent = str_starts_with($b, 'GIF')
                ? App::tg()->sendAnimationBytes($chat, $b, $cap, $replyTo)
                : App::tg()->sendPhotoBytes($chat, $b, $cap, $replyTo);
            if ($sent) {
                return true;
            }
        }
        return false;
    }

    private static function album(int|string $chat, array $sets, string $cap, int $replyTo): bool
    {
        $files = [];
        $gifs  = [];
        try {
            foreach ($sets as $cands) {
                foreach ($cands as $u) {
                    if (($b = self::fetch($u)) !== null) {
                        $tmp = tempnam(sys_get_temp_dir(), 'nl_ph_');
                        if ($tmp !== false && file_put_contents($tmp, $b) !== false) {
                            if (str_starts_with($b, 'GIF')) {
                                $gifs[] = $tmp;
                            } else {
                                $files[] = $tmp;
                            }
                        }
                        break;
                    }
                }
            }
            if (!$files && !$gifs) {
                return false;
            }
            $ok = false;
            foreach ($gifs as $g => $f) {
                $ok = App::tg()->sendAnimationBytes($chat, (string) file_get_contents($f), $g === 0 && !$files ? $cap : '', $replyTo) || $ok;
            }
            if (!$files) {
                return $ok;
            }
            if (count($files) === 1) {
                return App::tg()->sendPhotoBytes($chat, (string) file_get_contents($files[0]), $cap, $replyTo) || $ok;
            }
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
            foreach (array_merge($files, $gifs) as $f) {
                @unlink($f);
            }
        }
    }
}
