<?php

final class Downloader
{
    public const BOT_API_BYTES = 50 * 1024 * 1024;
    private const DAILY    = 20;
    private const SLOTS    = 2;
    private const TMP      = '/tmp/nullini-dl';

    private const SITES = [
        'youtube'     => ['یوتیوب', '/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', true],
        'instagram'   => ['اینستاگرام', '/(^|\.)(instagram\.com|instagr\.am)$/', true],
        'tiktok'      => ['تیک‌تاک', '/(^|\.)tiktok\.com$/', true],
        'twitter'     => ['توییتر', '/(^|\.)(twitter\.com|x\.com)$/', true],
        'soundcloud'  => ['ساندکلاد', '/(^|\.)(soundcloud\.com|snd\.sc)$/', false],
        'pinterest'   => ['پینترست', '/(^|\.)(pinterest\.[a-z.]+|pin\.it)$/', true],
        'reddit'      => ['ردیت', '/(^|\.)(reddit\.com|redd\.it)$/', true],
        'facebook'    => ['فیسبوک', '/(^|\.)(facebook\.com|fb\.watch)$/', true],
        'snapchat'    => ['اسنپ‌چت', '/(^|\.)snapchat\.com$/', true],
        'spotify'     => ['اسپاتیفای', '/(^|\.)(open\.spotify\.com|spotify\.link|spotify\.app\.link)$/', false],
    ];

    public static function mtproto(): bool
    {
        static $on = null;
        return $on ??= (self::apiId() > 0 && self::apiHash() !== '' && is_file(NULLINI_ROOT . '/bin/mtup.py'));
    }

    private static function apiId(): int
    {
        return (int) (env('API_ID') ?: env('TG_API_ID', 0));
    }

    private static function apiHash(): string
    {
        return trim((string) (env('API_HASH') ?: env('TG_API_HASH', '')));
    }

    public static function maxBytes(): int
    {
        return self::mtproto() ? max(50, min(2000, (int) env('DL_MAX_MB', 2000))) * 1048576 : self::BOT_API_BYTES;
    }

    public static function limitText(): string
    {
        $b = self::maxBytes();
        return $b >= 1073741824 ? fa((string) round($b / 1073741824, 1)) . ' گیگ' : fa((string) round($b / 1048576)) . ' مگ';
    }

    private static function jobTime(): int
    {
        return self::mtproto() ? 3600 : 900;
    }

    private static function options(string $site, array $info, bool $hasVideo, int $max): array
    {
        if (!$hasVideo) {
            return [['mp3', '🎵 دانلود آهنگ']];
        }
        $have = [];
        foreach ((array) ($info['formats'] ?? []) as $f) {
            $fh = (int) ($f['height'] ?? 0);
            if ($fh > 0 && ($f['vcodec'] ?? '') !== 'none') {
                foreach ([144, 240, 360, 480, 720, 1080, 1440, 2160] as $std) {
                    if ($fh <= $std + 40 && $fh >= $std * 0.85) {
                        $have[$std] = true;
                        break;
                    }
                }
            }
        }
        krsort($have);
        $opts = [];
        foreach (array_keys($have) as $h) {
            if (self::estimate($info, $h) > $max) {
                continue;
            }
            $name   = [2160 => '4K', 1440 => '2K'][$h] ?? fa($h) . 'p';
            $opts[] = ['v' . $h, ($h >= 720 ? '🎬 ' : '📱 ') . $name . self::sizeHint($info, $h)];
        }
        if (!$opts) {
            $opts[] = ['hd', '🎬 دانلود ویدیو'];
        }
        $opts[] = ['mp3', '🎵 فقط صدا (MP3)'];
        return $opts;
    }

    public static function match(string $text): ?array
    {
        $text = mbTrim($text);
        if ($text === '' || mb_strlen($text) > 600) {
            return null;
        }
        if (!preg_match('/^((?:https?:\/\/)?(?:[\w-]+\.)+[a-z]{2,}(?:\/\S*)?)$/iu', $text, $m)) {
            return null;
        }
        $url = $m[1];
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www|m|mobile|vm|vt|music)\./', '', $host) ?? $host;
        foreach (self::SITES as $key => [$name, $re]) {
            if (preg_match($re, $host)) {
                return ['url' => $url, 'site' => $key, 'name' => $name];
            }
        }
        return null;
    }

    public static function find(string $text): ?array
    {
        if (preg_match_all('#(?:https?://)?(?:[\w-]+\.)+[a-z]{2,}/\S+#iu', $text, $m)) {
            foreach ($m[0] as $u) {
                if (($l = self::match(rtrim($u, '.,،!؟?)»"\''))) !== null) {
                    return $l;
                }
            }
        }
        return null;
    }

    public static function sitesText(): string
    {
        return '🌐 ' . implode('، ', array_column(self::SITES, 0));
    }

    public static function groupsEnabled(): bool
    {
        return env('DL_GROUPS', '1') !== '0';
    }

    public static function bin(): ?string
    {
        static $bin = false;
        if ($bin !== false) {
            return $bin;
        }
        $try = (string) env('YTDLP_BIN', '');
        if ($try !== '' && is_executable($try)) {
            return $bin = $try;
        }
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
            if (is_executable($p)) {
                return $bin = $p;
            }
        }
        $found = function_exists('shell_exec') ? trim((string) @shell_exec('command -v yt-dlp 2>/dev/null')) : '';
        return $bin = ($found !== '' ? $found : null);
    }

    public static function offer(array $msg, array $link): void
    {
        $tg  = App::tg();
        $uid = (int) $msg['from']['id'];
        if (($stop = self::gate($uid, $link)) !== null) {
            $tg->reply($msg, $stop);
            return;
        }
        $sent = $tg->reply($msg, '🔎 دارم لینک ' . $link['name'] . ' رو بررسی می‌کنم...');
        $mid  = (int) ($sent['result']['message_id'] ?? 0);
        if (!$mid) {
            return;
        }
        $tg->action($msg['chat']['id'], 'typing');
        self::prepare(['chat' => $msg['chat']['id'], 'card' => $mid, 'imid' => null], $link, $uid, (int) $msg['message_id'], $msg);
    }

    public static function guest(array $from, array $link, string $imid): void
    {
        $tg  = App::tg();
        $uid = (int) ($from['id'] ?? 0);
        if (($stop = self::gate($uid, $link)) !== null) {
            $tg->editInline($imid, $stop);
            return;
        }
        $store = self::storeChat($uid);
        if ($store === null) {
            $bot = App::botUsername();
            $tg->editInline($imid, "🤖 برای دانلود این‌جا،\nاول یه بار ربات رو تو پیوی استارت کن.", ikb([[urlBtn('🤖 استارت ربات', "https://t.me/{$bot}?start=dl", 'success')]]));
            return;
        }
        self::prepare(['chat' => $store, 'card' => 0, 'imid' => $imid], $link, $uid, 0, null);
    }

    private static function gate(int $uid, array $link): ?string
    {
        if (self::bin() === null) {
            return '⚠️ دانلودر هنوز رو سرور نصب نشده؛' . "\n" . 'یه‌کم دیگه دوباره امتحان کن.';
        }
        if (!App::isAdmin($uid) && self::usedToday($uid) >= self::DAILY) {
            return '✋ امروز سهمیه‌ی دانلودت (' . fa(self::DAILY) . ' تا) تموم شده؛' . "\n" . 'فردا دوباره بیا 🙂';
        }
        if ($link['site'] === 'instagram' && preg_match('#instagram\.com/(stories/|s/)#i', $link['url'])) {
            return "📸 استوری اینستا رو دانلود نمی‌کنم؛\nپست و ریلز رو بفرست 🙂";
        }
        return null;
    }

    private static function storeChat(int $uid): int|string|null
    {
        $fixed = (string) env('DL_STORE_CHAT', '');
        if ($fixed !== '') {
            return preg_match('/^-?\d+$/', $fixed) ? (int) $fixed : $fixed;
        }
        $r = App::tg()->call('sendChatAction', ['chat_id' => $uid, 'action' => 'upload_document'], 8);
        return !empty($r['ok']) ? $uid : null;
    }

    private static function show(array $t, string $text, ?array $kb = null, ?array $preview = null): void
    {
        $tg = App::tg();
        if (!empty($t['imid'])) {
            $tg->call('editMessageText', [
                'inline_message_id'    => $t['imid'],
                'text'                 => $text,
                'parse_mode'           => 'HTML',
                'reply_markup'         => $kb,
                'link_preview_options' => $preview ?? ['is_disabled' => true],
            ]);
            return;
        }
        if (!empty($t['card'])) {
            $tg->call('editMessageText', [
                'chat_id'              => $t['chat'],
                'message_id'           => (int) $t['card'],
                'text'                 => $text,
                'parse_mode'           => 'HTML',
                'reply_markup'         => $kb,
                'link_preview_options' => $preview ?? ['is_disabled' => true],
            ]);
        }
    }

    private static function limit(array $t): int
    {
        return !empty($t['imid']) ? self::BOT_API_BYTES : self::maxBytes();
    }

    private static function prepare(array $t, array $link, int $uid, int $replyTo, ?array $msg): void
    {
        $sp = null;
        if ($link['site'] === 'spotify') {
            $sp = Spotify::track($link['url']);
            if (isset($sp['error'])) {
                self::show($t, $sp['error']);
                return;
            }
            $hit = $sp['yt'] ?? self::ytMatch(Spotify::query($sp), (int) $sp['duration']);
            if ($hit === null) {
                self::show($t, "🤷 این آهنگ رو جایی برای دانلود پیدا نکردم 😕");
                return;
            }
            Spotify::remember($sp, $hit);
            $link['url'] = $hit;
        }

        $photos = static fn() => $sp === null && Photos::supports($link['site'])
            && ($msg !== null ? Photos::deliver($msg, $link, (int) $t['card']) : Photos::inline($link, (string) $t['imid'], $t['chat'], $uid));

        $p = self::probe($link['url']);
        if (isset($p['error'])) {
            if (!$photos()) {
                self::show($t, $p['error']);
            }
            return;
        }
        $info = $p['info'];
        if (!self::hasVideo($info) && $sp === null && Photos::supports($link['site']) && $photos()) {
            return;
        }
        if ($sp !== null) {
            $info['title']    = $sp['title'];
            $info['uploader'] = $sp['artist'];
            $info['channel']  = $sp['artist'];
            $info['duration'] = $sp['duration'] ?: ($info['duration'] ?? 0);
            if ($sp['cover'] !== '') {
                $info['thumbnail'] = $sp['cover'];
            }
        }

        if (!empty($info['is_live']) || in_array($info['live_status'] ?? '', ['is_live', 'is_upcoming'], true)) {
            self::show($t, '📡 این یه پخش زنده‌ست؛' . "\n" . 'وقتی تموم شد دوباره لینکش رو بفرست.');
            return;
        }

        $hasVideo = self::SITES[$link['site']][2] && self::hasVideo($info);
        $token    = self::newJob($link, $info, $uid, $t['chat'], (int) $t['card'], $replyTo, $t['imid']);
        self::show($t, self::cardText($info, $link, $hasVideo), self::cardKeyboard($token, $link['site'], $info, $hasVideo, self::limit($t)), self::preview($info));
    }

    private static function newJob(array $link, array $info, int $uid, int|string $chat, int $card, int $replyTo, ?string $imid = null): string
    {
        $token = bin2hex(random_bytes(6));
        Store::set('dl', $token, [
            'url'      => (string) ($info['webpage_url'] ?? $link['url']),
            'site'     => $link['site'],
            'title'    => mb_substr((string) ($info['title'] ?? $link['name']), 0, 3500),
            'uploader' => mb_substr((string) ($info['uploader'] ?? $info['channel'] ?? $info['uploader_id'] ?? ''), 0, 80),
            'duration' => (int) ($info['duration'] ?? 0),
            'width'    => (int) ($info['width'] ?? 0),
            'height'   => (int) ($info['height'] ?? 0),
            'owner'    => $uid,
            'chat'     => $chat,
            'card'     => $card,
            'reply_to' => $replyTo,
            'imid'     => $imid,
        ]);
        return $token;
    }

    public static function charge(int $uid): void
    {
        Store::update('dl_quota', self::quotaKey($uid), static fn($n) => (int) $n + 1);
    }

    private static function ytMatch(string $q, int $dur): ?string
    {
        if ($q === '') {
            return null;
        }
        $res = self::run(array_merge([self::bin()], self::commonArgs(), [
            '-J', '--flat-playlist', '--no-warnings', '--socket-timeout', '15', 'ytsearch6:' . $q,
        ]), 45);
        $j    = json_decode($res['out'], true);
        $list = [];
        foreach (array_values((array) ($j['entries'] ?? [])) as $e) {
            $id = (string) ($e['id'] ?? '');
            if (preg_match('/^[\w-]{11}$/', $id)) {
                $list[] = ['id' => $id, 'title' => (string) ($e['title'] ?? ''), 'by' => (string) ($e['channel'] ?? $e['uploader'] ?? ''), 'd' => (int) ($e['duration'] ?? 0)];
            }
        }
        $best = null;
        if ($list) {
            $lines = [];
            foreach ($list as $n => $e) {
                $lines[] = ($n + 1) . '. ' . $e['title'] . ' | ' . $e['by'] . ' | ' . $e['d'] . 's';
            }
            $pick = AI::chat([
                ['role' => 'system', 'content' => 'Pick the YouTube result that is the original studio recording of the requested track. Reply with its number only.'],
                ['role' => 'user', 'content' => "Track: {$q}" . ($dur > 0 ? " ({$dur}s)" : '') . "\n" . implode("\n", $lines)],
            ], ['tier' => 'lite', 'temperature' => 0, 'thinking' => null, 'deadline' => microtime(true) + 10]);
            $n = $pick !== null && preg_match('/\d+/', $pick, $pm) ? (int) $pm[0] - 1 : -1;
            if (!isset($list[$n])) {
                usort($list, static fn($a, $b) => ($dur > 0 && $a['d'] > 0 ? abs($a['d'] - $dur) : 999) <=> ($dur > 0 && $b['d'] > 0 ? abs($b['d'] - $dur) : 999));
                $n = 0;
            }
            $best = 'https://www.youtube.com/watch?v=' . $list[$n]['id'];
        }
        if ($best === null) {
            logWarn('dl', 'spotify yt match failed: ' . self::lastLine($res['err']));
        }
        return $best;
    }

    public static function begin(string $token, string $fmt, int $uid): array
    {
        if (!preg_match('/^(fhd|hd|sd|mp3|v\d{3,4})$/', $fmt) || !preg_match('/^[a-f0-9]{6,32}$/', $token)) {
            return ['error' => '⌛️ منقضی شده؛' . "\n" . 'لینک رو دوباره بفرست.'];
        }
        $tg  = App::tg();
        $job = Store::get('dl', $token);
        if (!is_array($job)) {
            return ['error' => '⌛️ منقضی شده؛' . "\n" . 'لینک رو دوباره بفرست.'];
        }
        if ($uid !== (int) $job['owner'] && !App::isAdmin($uid)) {
            return ['error' => '🙅 این دکمه مال کسیه که لینک رو فرستاده؛' . "\n" . 'لینک خودت رو بفرست 🙂'];
        }
        $cached = Store::get('dl_files', self::cacheKey($job['url'], $fmt));
        if (is_array($cached) && (!empty($job['imid']) ? self::toInline($job, $cached) : self::sendCached($job, $cached))) {
            if ($job['card'] && empty($job['imid'])) {
                $tg->call('deleteMessage', ['chat_id' => $job['chat'], 'message_id' => $job['card']], 8);
            }
            Users::hit('dl:' . $job['site']);
            return ['ok' => 'sent'];
        }
        if (!App::isAdmin($uid) && self::usedToday($uid) >= self::DAILY) {
            return ['error' => '✋ امروز سهمیه‌ی دانلودت (' . fa(self::DAILY) . ' تا) تموم شده؛' . "\n" . 'فردا دوباره بیا 🙂'];
        }
        $got = Store::update('dl_busy', (string) $uid, static fn($at) => (is_int($at) && time() - $at < self::jobTime() + 20) ? null : time());
        if ($got === null) {
            return ['error' => '⏳ یه دانلود دیگه‌ت در جریانه؛' . "\n" . 'صبر کن اون تموم شه.'];
        }
        if (!empty($job['imid'])) {
            self::show($job, self::stageText($job, '⏳ آماده‌ی دانلود...'));
        } elseif (!$job['card']) {
            $r = $tg->send($job['chat'], self::stageText($job, '⏳ آماده‌ی دانلود...'));
            $card = (int) ($r['result']['message_id'] ?? 0);
            if (!$card) {
                Store::delete('dl_busy', (string) $uid);
                return ['error' => "🤖 اول ربات رو تو پیوی استارت کن،\nبعد دوباره بزن."];
            }
            $job['card'] = $card;
            Store::set('dl', $token, $job);
        } else {
            self::show($job, self::stageText($job, '⏳ آماده‌ی دانلود...'));
        }
        if (!self::spawn($token, $fmt)) {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(self::jobTime());
            }
            @set_time_limit(self::jobTime());
            self::work($token, $fmt);
        }
        return ['ok' => 'started'];
    }

    private static function hasVideo(array $info): bool
    {
        if (!empty($info['formats']) && is_array($info['formats'])) {
            foreach ($info['formats'] as $f) {
                if (($f['vcodec'] ?? 'none') !== 'none' || !empty($f['height'])) {
                    return true;
                }
            }
            return false;
        }
        return !empty($info['height']) || ($info['vcodec'] ?? 'none') !== 'none';
    }

    private static function preview(array $info): array
    {
        $thumb = (string) ($info['thumbnail'] ?? '');
        if ($thumb === '' || !preg_match('#^https://#', $thumb)) {
            return ['is_disabled' => true];
        }
        return ['url' => $thumb, 'prefer_large_media' => true, 'show_above_text' => true];
    }

    private static function cardText(array $info, array $link, bool $hasVideo): string
    {
        $title = mb_substr((string) ($info['title'] ?? ''), 0, 3500);
        $who   = (string) ($info['uploader'] ?? $info['channel'] ?? '');
        $meta  = [];
        if ($who !== '') {
            $meta[] = '👤 ' . h(mb_substr($who, 0, 40));
        }
        if (!empty($info['duration'])) {
            $meta[] = '⏱ ' . fa(self::clock((int) $info['duration']));
        }
        $meta[] = '📍 ' . $link['name'];

        return ($hasVideo ? '🎬 ' : '🎵 ') . '<b>' . h($title !== '' ? $title : 'بدون عنوان') . "</b>\n"
            . implode(' · ', $meta) . "\n\n"
            . ($hasVideo ? 'چی می‌خوای؟ 👇' : 'آماده‌ی دانلود 👇');
    }

    private static function cardKeyboard(string $token, string $site, array $info, bool $hasVideo, int $max): array
    {
        $rows = [];
        foreach (self::options($site, $info, $hasVideo, $max) as $i => [$fmt, $label]) {
            $rows[] = [btn($label, "dl:{$token}:{$fmt}", $i === 0 ? 'success' : 'primary')];
        }
        $rows[] = [btn('✖️ بیخیال', "dl:{$token}:x", null)];
        return ikb($rows);
    }

    private static function sizeHint(array $info, int $h): string
    {
        $mb = self::estimate($info, $h) / 1048576;
        return $mb >= 1 ? ' (~' . fa((string) round($mb)) . 'MB)' : '';
    }

    private static function estimate(array $info, int $h): int
    {
        $best  = 0;
        $bestH = 0;
        $audio = 0;
        foreach ((array) ($info['formats'] ?? []) as $f) {
            $size = (int) ($f['filesize'] ?? $f['filesize_approx'] ?? 0);
            if ($size <= 0) {
                continue;
            }
            $fh = (int) ($f['height'] ?? 0);
            if (($f['vcodec'] ?? 'none') === 'none') {
                $audio = max($audio, $size);
            } elseif ($fh <= $h && $fh >= $bestH) {
                if ($fh > $bestH || $size < $best || $best === 0) {
                    $best = $size;
                }
                $bestH = $fh;
            }
        }
        return $best > 0 ? $best + $audio : 0;
    }

    public static function onCallback(array $cq, string $data): void
    {
        $tg = App::tg();
        if (!preg_match('/^dl:([a-f0-9]{12}):(fhd|hd|sd|mp3|x|v\d{3,4})$/', $data, $m)) {
            $tg->answer($cq);
            return;
        }
        [, $token, $fmt] = $m;
        $uid = (int) $cq['from']['id'];
        $job = Store::get('dl', $token);
        if (!is_array($job)) {
            $tg->alert($cq, '⌛️ این کارت منقضی شده؛' . "\n" . 'لینک رو دوباره بفرست.');
            return;
        }
        if ($uid !== (int) $job['owner'] && !App::isAdmin($uid)) {
            $tg->alert($cq, '🙅 این دکمه مال کسیه که لینک رو فرستاده؛' . "\n" . 'لینک خودت رو بفرست 🙂');
            return;
        }
        if ($fmt === 'x') {
            if (!empty($job['imid'])) {
                self::show($job, '✖️ بیخیال شدی.');
            } else {
                $tg->call('deleteMessage', ['chat_id' => $job['chat'], 'message_id' => $job['card']], 8);
            }
            Store::delete('dl', $token);
            $tg->answer($cq, 'باشه 👌');
            return;
        }

        $r = self::begin($token, $fmt, $uid);
        if (isset($r['error'])) {
            $tg->alert($cq, $r['error']);
            return;
        }
        $tg->answer($cq, $r['ok'] === 'sent' ? '⚡️ فرستادمش' : '⏳ شروع شد');
    }

    private static function spawn(string $token, string $fmt): bool
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return false;
        }
        $cmd = 'timeout -k 10 ' . self::jobTime() . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(NULLINI_ROOT . '/bot.php')
            . ' --download ' . escapeshellarg($token) . ' ' . escapeshellarg($fmt) . ' ' . App::botIndex() . ' > /dev/null 2>&1 &';
        exec($cmd, $o, $code);
        return $code === 0;
    }

    public static function work(string $token, string $fmt): void
    {
        $tg  = App::tg();
        $job = Store::get('dl', $token);
        if (!is_array($job)) {
            return;
        }
        $chat = $job['chat'];
        $card = (int) $job['card'];
        $dir  = self::TMP . "/{$token}-{$fmt}";
        $slot = null;

        try {
            $slot = self::acquireSlot($job);
            if ($slot === null) {
                self::show($job, '😮‍💨 سرور الان خیلی شلوغه؛' . "\n" . 'چند دقیقه دیگه لینک رو دوباره بفرست.');
                return;
            }
            self::rmrf($dir);
            @mkdir($dir, 0775, true);

            self::show($job, self::stageText($job, self::bar(0) . ' ۰٪'));
            $limit = self::limit($job);
            $last  = 0.0;
            $stage = '';
            $res   = self::run(array_merge([self::bin()], self::commonArgs(), self::formatArgs($fmt, $limit <= self::BOT_API_BYTES), [
                '--no-playlist', '--newline', '--no-warnings', '--no-mtime', '--restrict-filenames',
                '--socket-timeout', '20', '--retries', '3', '--fragment-retries', '3', '--concurrent-fragments', '4',
                '--max-filesize', (int) ($limit / 1048576) - 1 . 'M',
                '--progress-template', 'download:NLP %(progress.downloaded_bytes)s %(progress.total_bytes)s %(progress.total_bytes_estimate)s %(progress.speed)s',
                '-o', $dir . '/%(id).40s.%(ext)s',
            ], [], [
                $job['url'],
            ]), self::jobTime() - 60, static function (string $line) use ($job, &$last, &$stage): void {
                $now = microtime(true);
                if (str_starts_with($line, 'NLP ')) {
                    if ($now - $last < 3.5) {
                        return;
                    }
                    $p     = explode(' ', $line);
                    $done  = (float) ($p[1] ?? 0);
                    $total = (float) (is_numeric($p[2] ?? '') ? $p[2] : ($p[3] ?? 0));
                    $speed = (float) (is_numeric($p[4] ?? '') ? $p[4] : 0);
                    $pct   = $total > 0 ? min(100, (int) floor($done / $total * 100)) : 0;
                    $line2 = '📦 ' . self::mb($done) . ($total > 0 ? ' از ' . self::mb($total) : '') . ($speed > 0 ? ' · ⚡️ ' . self::mb($speed) . '/ث' : '');
                    self::show($job, self::stageText($job, self::bar($pct) . ' ' . fa((string) $pct) . "٪\n" . $line2));
                    $last = $now;
                } elseif (preg_match('/^\[(Merger|ExtractAudio|VideoConvertor|FixupM3u8|EmbedThumbnail)\]/', $line) && $stage === '') {
                    $stage = 'post';
                    self::show($job, self::stageText($job, '⚙️ دارم آماده‌اش می‌کنم...'));
                    $last = $now;
                }
            });

            $file = self::pickFile($dir);
            if ($file === null) {
                logError('dl', "{$job['site']} {$fmt} failed (exit {$res['code']}): " . self::lastLine($res['err']));
                self::show($job, self::explain($res['err'] . "\n" . $res['out'], $res['code']), self::retryKeyboard($token, $fmt));
                return;
            }
            $size = (int) filesize($file);
            if ($size > $limit) {
                self::show($job, '📦 حجمش ' . self::mb($size) . ' شد و از سقف ' . self::mb($limit) . " بیشتره 😕\n"
                    . ($fmt === 'mp3' ? '' : '💡 «کم‌حجم» یا «فقط صدا» رو امتحان کن.'), self::retryKeyboard($token, $fmt));
                return;
            }

            self::show($job, self::stageText($job, '📤 دارم می‌فرستمش... (' . self::mb($size) . ')'));
            if (empty($job['imid'])) {
                $tg->action($chat, $fmt === 'mp3' ? 'upload_voice' : 'upload_video');
            }
            $meta = $fmt === 'mp3' ? [] : self::videoMeta($file);
            $sent = $size > self::BOT_API_BYTES
                ? self::uploadMtproto($job, $fmt, $file, $meta, $dir)
                : self::upload($job, $fmt, $file, $meta);
            if ($sent === null || (!empty($job['imid']) && !self::toInline($job, $sent))) {
                if ($sent !== null && !empty($sent['msg'])) {
                    $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => (int) $sent['msg']], 8);
                }
                self::show($job, '❌ آپلود تو تلگرام نشد؛' . "\n" . 'یه بار دیگه امتحان کن.', self::retryKeyboard($token, $fmt));
                return;
            }
            $stored = $sent;
            unset($stored['msg']);
            Store::set('dl_files', self::cacheKey($job['url'], $fmt), $stored);
            self::charge((int) $job['owner']);
            Users::hit('dl:' . $job['site'] . (!empty($job['imid']) ? ':guest' : ''));
            if (!empty($job['imid'])) {
                if (!empty($sent['msg']) && env('DL_STORE_CHAT', '') === '') {
                    $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => (int) $sent['msg']], 8);
                }
            } else {
                $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => $card], 8);
            }
        } catch (\Throwable $e) {
            logError('dl', 'worker: ' . $e->getMessage());
            self::show($job, '❌ یه مشکلی پیش اومد؛' . "\n" . 'یه بار دیگه امتحان کن.', self::retryKeyboard($token, $fmt));
        } finally {
            self::rmrf($dir);
            Store::delete('dl_busy', (string) $job['owner']);
            if ($slot) {
                flock($slot, LOCK_UN);
                fclose($slot);
            }
        }
    }

    private static function formatArgs(string $fmt, bool $small = false): array
    {
        if ($fmt === 'mp3') {
            return ['-f', 'ba[filesize<45M]/ba[filesize_approx<45M]/ba/b', '-x', '--audio-format', 'mp3', '--audio-quality', '5',
                '--embed-metadata', '--embed-thumbnail', '--convert-thumbnails', 'jpg'];
        }
        $h = ['sd' => 360, 'hd' => 720, 'fhd' => 1080][$fmt] ?? (preg_match('/^v(\d{3,4})$/', $fmt, $vm) ? (int) $vm[1] : 720);
        $v = "[height<={$h}]";
        if (self::mtproto() && !$small) {
            $sel = "bv*{$v}+ba/b{$v}/bv*+ba/b";
        } else {
            $sel = "bv*{$v}[filesize<44M]+ba[filesize<5M]/bv*{$v}[filesize_approx<44M]+ba/b{$v}[filesize<49M]/b{$v}[filesize_approx<49M]/bv*{$v}+ba/b{$v}/bv*+ba/b";
        }
        return ['-f', $sel, '-S', 'vcodec:h264,ext:mp4:m4a', '--merge-output-format', 'mp4', '--remux-video', 'mp4'];
    }

    private static function commonArgs(): array
    {
        $args = ['--ignore-config', '--no-color'];
        $src  = NULLINI_ROOT . '/cookies.txt';
        $jar  = is_file($src) && filesize($src) > 0 ? rtrim((string) file_get_contents($src)) . "\n" : '';
        if ($jar !== '') {
            $tmp = self::TMP . '/cookies-' . getmypid() . '.txt';
            @mkdir(self::TMP, 0775, true);
            if (@file_put_contents($tmp, $jar) !== false) {
                $args[] = '--cookies';
                $args[] = $tmp;
            }
        }
        $proxy = (string) env('DL_PROXY', '');
        if ($proxy !== '') {
            $args[] = '--proxy';
            $args[] = $proxy;
        }
        return $args;
    }

    private static function acquireSlot(array $job)
    {
        @mkdir(self::TMP, 0775, true);
        $told = false;
        for ($wait = 0; $wait < 120; $wait += 2) {
            for ($i = 0; $i < self::SLOTS; $i++) {
                $fh = @fopen(self::TMP . "/slot{$i}.lock", 'c');
                if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
                    return $fh;
                }
                if ($fh) {
                    fclose($fh);
                }
            }
            if (!$told) {
                self::show($job, self::stageText($job, '🕐 تو صفم؛ چند لحظه صبر کن...'));
                $told = true;
            }
            sleep(2);
        }
        return null;
    }

    private static function pickFile(string $dir): ?string
    {
        $best = null;
        $size = 0;
        foreach ((array) glob($dir . '/*') as $f) {
            $ext = strtolower(pathinfo((string) $f, PATHINFO_EXTENSION));
            if (!is_file($f) || in_array($ext, ['part', 'ytdl', 'jpg', 'jpeg', 'png', 'webp', 'json', 'temp', 'txt'], true) || str_contains((string) $f, '.part')) {
                continue;
            }
            $s = (int) filesize($f);
            if ($s > $size) {
                $best = $f;
                $size = $s;
            }
        }
        return $size > 0 ? $best : null;
    }

    private static function upload(array $job, string $fmt, string $file, array $meta = []): ?array
    {
        $tg      = App::tg();
        $caption = self::caption($job);
        $reply   = $job['reply_to'] ? ['message_id' => (int) $job['reply_to'], 'allow_sending_without_reply' => true] : null;
        $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($fmt === 'mp3') {
            $r = $tg->call('sendAudio', [
                'chat_id'          => $job['chat'],
                'audio'            => new CURLFile($file, $ext === 'mp3' ? 'audio/mpeg' : 'audio/mp4', self::fileName($job, $ext)),
                'title'            => mb_substr($job['title'], 0, 64),
                'performer'        => $job['uploader'] !== '' ? mb_substr($job['uploader'], 0, 64) : null,
                'duration'         => $job['duration'] ?: null,
                'caption'          => $caption,
                'parse_mode'       => 'HTML',
                'reply_parameters' => $reply,
            ], 300);
            $id = $r['result']['audio']['file_id'] ?? $r['result']['document']['file_id'] ?? null;
            return $id ? ['type' => 'audio', 'file_id' => $id, 'msg' => (int) ($r['result']['message_id'] ?? 0)] : null;
        }

        $r = $tg->call('sendVideo', [
            'chat_id'            => $job['chat'],
            'video'              => new CURLFile($file, 'video/mp4', self::fileName($job, $ext)),
            'duration'           => ($meta['duration'] ?? 0) ?: ($job['duration'] ?: null),
            'width'              => ($meta['width'] ?? 0) ?: null,
            'height'             => ($meta['height'] ?? 0) ?: null,
            'supports_streaming' => true,
            'caption'            => $caption,
            'parse_mode'         => 'HTML',
            'reply_parameters'   => $reply,
        ], 300);
        $id = $r['result']['video']['file_id'] ?? null;
        if ($id) {
            return ['type' => 'video', 'file_id' => $id, 'msg' => (int) ($r['result']['message_id'] ?? 0)];
        }
        $id = $r['result']['document']['file_id'] ?? $r['result']['animation']['file_id'] ?? null;
        if ($id) {
            return ['type' => isset($r['result']['animation']) ? 'animation' : 'document', 'file_id' => $id, 'msg' => (int) ($r['result']['message_id'] ?? 0)];
        }
        $r  = $tg->call('sendDocument', [
            'chat_id'          => $job['chat'],
            'document'         => new CURLFile($file, 'application/octet-stream', self::fileName($job, $ext)),
            'caption'          => $caption,
            'parse_mode'       => 'HTML',
            'reply_parameters' => $reply,
        ], 300);
        $id = $r['result']['document']['file_id'] ?? null;
        return $id ? ['type' => 'document', 'file_id' => $id, 'msg' => (int) ($r['result']['message_id'] ?? 0)] : null;
    }

    private static function videoMeta(string $file): array
    {
        $r = self::run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $file], 30);
        $j = json_decode($r['out'], true);
        return [
            'width'    => (int) ($j['streams'][0]['width'] ?? 0),
            'height'   => (int) ($j['streams'][0]['height'] ?? 0),
            'duration' => (int) round((float) ($j['format']['duration'] ?? 0)),
        ];
    }

    private static function uploadMtproto(array $job, string $fmt, string $file, array $meta, string $dir): ?array
    {
        if (!self::mtproto()) {
            return null;
        }
        $tg    = App::tg();
        $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $kind  = $fmt === 'mp3' ? 'audio' : ($ext === 'mp4' ? 'video' : 'document');
        $thumb = '';
        if ($kind === 'video') {
            $thumb = $dir . '/thumb.jpg';
            self::run(['ffmpeg', '-y', '-v', 'error', '-ss', '1', '-i', $file, '-frames:v', '1', '-vf', 'scale=320:-2', '-q:v', '4', $thumb], 40);
            if (!is_file($thumb)) {
                $thumb = '';
            }
        }
        $job2 = [
            'session_file' => NULLINI_DATA . '/mtproto' . (App::botIndex() === 0 ? '' : (string) App::botIndex()) . '.session',
            'api_id'       => self::apiId(),
            'api_hash'     => self::apiHash(),
            'bot_token'    => $tg->token(),
            'chat'         => (int) $job['chat'],
            'reply_to'     => (int) $job['reply_to'],
            'file'         => $file,
            'name'         => self::fileName($job, $ext),
            'kind'         => $kind,
            'mime'         => $kind === 'audio' ? ($ext === 'mp3' ? 'audio/mpeg' : 'audio/mp4') : ($kind === 'video' ? 'video/mp4' : null),
            'thumb'        => $thumb,
            'caption'      => self::caption($job),
            'duration'     => ($meta['duration'] ?? 0) ?: $job['duration'],
            'width'        => $meta['width'] ?? 0,
            'height'       => $meta['height'] ?? 0,
            'title'        => mb_substr($job['title'], 0, 64),
            'performer'    => mb_substr($job['uploader'], 0, 64),
        ];
        $last = 0.0;
        $out  = null;
        $res  = self::run(['python3', NULLINI_ROOT . '/bin/mtup.py'], self::jobTime() - 120, static function (string $line) use ($tg, $job, &$last, &$out): void {
            $j = json_decode($line, true);
            if (!is_array($j)) {
                return;
            }
            if (isset($j['ok'])) {
                $out = $j;
                return;
            }
            if (isset($j['p'], $j['t']) && microtime(true) - $last >= 3.5) {
                $pct = $j['t'] > 0 ? (int) floor($j['p'] / $j['t'] * 100) : 0;
                self::show($job, self::stageText($job, '📤 ' . self::bar($pct) . ' ' . fa((string) $pct) . "٪\n📦 " . self::mb((float) $j['p']) . ' از ' . self::mb((float) $j['t'])));
                $last = microtime(true);
            }
        }, ['NL_JOB' => json_encode($job2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        if (empty($out['ok']) || empty($out['msg_id'])) {
            logError('dl', 'mtproto upload failed (exit ' . $res['code'] . '): ' . (string) ($out['error'] ?? self::lastLine($res['err'])));
            return null;
        }
        return ['type' => 'copy', 'chat' => $job['chat'], 'msg' => (int) $out['msg_id']];
    }

    private static function toInline(array $job, array $c): bool
    {
        if (!in_array($c['type'] ?? '', ['video', 'audio', 'animation', 'document'], true) || empty($c['file_id'])) {
            return false;
        }
        $r = App::tg()->call('editMessageMedia', [
            'inline_message_id' => $job['imid'],
            'media'             => ['type' => $c['type'], 'media' => $c['file_id'], 'caption' => self::caption($job), 'parse_mode' => 'HTML'],
        ], 30);
        return !empty($r['ok']);
    }

    private static function sendCached(array $job, array $c): bool
    {
        if (($c['type'] ?? '') === 'copy') {
            $r = App::tg()->call('copyMessage', [
                'chat_id'          => $job['chat'],
                'from_chat_id'     => $c['chat'],
                'message_id'       => (int) $c['msg'],
                'reply_parameters' => $job['reply_to'] ? ['message_id' => (int) $job['reply_to'], 'allow_sending_without_reply' => true] : null,
            ], 20);
            return !empty($r['ok']);
        }
        $method = ['video' => 'sendVideo', 'audio' => 'sendAudio', 'animation' => 'sendAnimation', 'document' => 'sendDocument'][$c['type'] ?? ''] ?? null;
        if ($method === null || empty($c['file_id'])) {
            return false;
        }
        $r = App::tg()->call($method, [
            'chat_id'          => $job['chat'],
            $c['type']         => $c['file_id'],
            'caption'          => self::caption($job),
            'parse_mode'       => 'HTML',
            'reply_parameters' => $job['reply_to'] ? ['message_id' => (int) $job['reply_to'], 'allow_sending_without_reply' => true] : null,
        ], 30);
        return !empty($r['ok']);
    }

    public static function probe(string $url): array
    {
        $key = 'dl_probe:' . md5($url);
        $hit = Store::get('cache', $key);
        if (is_array($hit) && time() - (int) ($hit['at'] ?? 0) < 1800) {
            return ['info' => $hit['v']];
        }
        $res = self::run(array_merge([self::bin()], self::commonArgs(), [
            '-J', '--no-playlist', '--no-warnings', '--skip-download', '--socket-timeout', '15', '--playlist-items', '1', $url,
        ]), 60);
        $info = json_decode($res['out'], true);
        if (!is_array($info)) {
            logError('dl', 'probe ' . parse_url($url, PHP_URL_HOST) . ' failed (exit ' . $res['code'] . '): ' . self::lastLine($res['err']));
            return ['error' => self::explain($res['err'], $res['code'])];
        }
        if (($info['_type'] ?? '') === 'playlist') {
            $first = $info['entries'][0] ?? null;
            if (!is_array($first)) {
                return ['error' => '🤷 تو این لینک چیزی برای دانلود پیدا نکردم.'];
            }
            $first['title'] ??= $info['title'] ?? '';
            $first['webpage_url'] ??= $url;
            $info = $first;
        }
        $slim = array_intersect_key($info, array_flip(['title', 'uploader', 'channel', 'uploader_id', 'duration', 'thumbnail', 'webpage_url',
            'is_live', 'live_status', 'width', 'height', 'vcodec']));
        $slim['formats'] = array_map(static fn($f) => array_intersect_key((array) $f, array_flip(['height', 'vcodec', 'filesize', 'filesize_approx'])),
            array_values((array) ($info['formats'] ?? [])));
        Store::set('cache', $key, ['at' => time(), 'v' => $slim]);
        return ['info' => $slim];
    }

    private static function run(array $args, int $timeout, ?callable $onLine = null, array $env = []): array
    {
        $cmd  = array_merge(['timeout', '-k', '5', (string) $timeout], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['HOME' => '/tmp', 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8']);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => '', 'err' => 'proc_open failed'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = $buf = '';
        $end = time() + $timeout + 15;
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($r, $w, $e, 1) === false) {
                break;
            }
            foreach ($r as $p) {
                $chunk = (string) fread($p, 65536);
                if ($p === $pipes[1]) {
                    $out .= $chunk;
                    if ($onLine && $chunk !== '') {
                        $buf .= $chunk;
                        while (($nl = strpos($buf, "\n")) !== false) {
                            $line = rtrim(substr($buf, 0, $nl), "\r");
                            $buf  = substr($buf, $nl + 1);
                            $onLine($line);
                        }
                        if (strlen($out) > 2 * 1048576) {
                            $out = substr($out, -65536);
                        }
                    }
                } else {
                    $err = substr($err . $chunk, -65536);
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (time() > $end) {
                proc_terminate($proc, 9);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    public static function explain(string $err, int $code = 1): string
    {
        $e = strtolower($err);
        return match (true) {
            $code === 124 || $code === 137                                         => '⌛️ سایت دیر جواب داد؛' . "\n" . 'یه‌کم دیگه دوباره امتحان کن.',
            str_contains($e, 'confirm you') && str_contains($e, 'bot')              => "🤖 یوتیوب سرور رو ربات حساب کرد و اجازه نداد 😕\nیه‌کم دیگه دوباره امتحان کن.",
            str_contains($e, 'larger than max-filesize') || str_contains($e, 'file is larger') => '📦 حجمش از سقف ' . self::limitText() . " بیشتره 😕\n💡 «کم‌حجم» یا «فقط صدا» رو امتحان کن.",
            str_contains($e, 'private') || str_contains($e, 'login') || str_contains($e, 'log in')
                || str_contains($e, 'sign in') || str_contains($e, 'cookies')            => '🔒 این پست خصوصیه یا برای دیدنش باید لاگین کرد.',
            str_contains($e, 'age') && str_contains($e, 'restrict')                 => "🔞 این ویدیو محدودیت سنی داره و\nبدون لاگین باز نمی‌شه.",
            str_contains($e, 'no video formats') || str_contains($e, 'no media')
                || str_contains($e, 'there is no video')                             => "🖼 این پست ویدیو یا صدا نداره\n(احتمالاً فقط عکسه).",
            str_contains($e, 'unsupported url')                                     => '🤷 این لینک پشتیبانی نمی‌شه.',
            str_contains($e, 'unavailable') || str_contains($e, '404') || str_contains($e, 'not found')
                || str_contains($e, 'removed') || str_contains($e, 'deleted')        => '🚫 این محتوا در دسترس نیست یا پاک شده.',
            str_contains($e, 'geo') || str_contains($e, 'country')                  => '🌍 این محتوا تو منطقه‌ی سرور در دسترس نیست.',
            str_contains($e, 'rate') && str_contains($e, 'limit') || str_contains($e, '429') => '🐢 سایت گفت زیادی درخواست دادیم؛' . "\n" . 'چند دقیقه دیگه امتحان کن.',
            default                                                                => "❌ نتونستم دانلودش کنم 😕\nلینک رو چک کن یا بعداً امتحان کن.",
        };
    }

    private static function retryKeyboard(string $token, string $fmt): array
    {
        $rows = [[btn('🔁 دوباره', "dl:{$token}:{$fmt}", 'primary')]];
        if ($fmt !== 'mp3') {
            $rows[0][] = btn('🎵 فقط صدا', "dl:{$token}:mp3", null);
        }
        if ($fmt !== 'mp3' && $fmt !== 'v360' && $fmt !== 'sd') {
            $rows[0][] = btn('📱 کم‌حجم', "dl:{$token}:v360", null);
        }
        $rows[] = [btn('✖️ بیخیال', "dl:{$token}:x", null)];
        return ikb($rows);
    }

    private static function stageText(array $job, string $status): string
    {
        return '📥 <b>' . h(mb_substr($job['title'], 0, 3500)) . "</b>\n\n" . $status;
    }

    private static function caption(array $job): string
    {
        $bot = App::botUsername();
        $head = '🎬 <b>' . h(mb_substr($job['title'], 0, 800)) . "</b>\n"
            . ($job['uploader'] !== '' ? '👤 ' . h($job['uploader']) . "\n" : '');
        return $head . "\n📥 <a href=\"https://t.me/{$bot}\">@{$bot}</a>";
    }

    private static function fileName(array $job, string $ext): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $job['title']) ?? 'nullini';
        $name = mbTrim(mb_substr($name, 0, 60));
        return ($name !== '' ? $name : 'nullini') . '.' . ($ext !== '' ? $ext : 'mp4');
    }

    public static function bar(int $pct): string
    {
        $n = (int) round(max(0, min(100, $pct)) / 10);
        return str_repeat('▰', $n) . str_repeat('▱', 10 - $n);
    }

    public static function mb(float $bytes): string
    {
        $mb = $bytes / 1048576;
        return fa($mb >= 10 ? (string) round($mb) : number_format($mb, 1)) . ' مگ';
    }

    public static function clock(int $s): string
    {
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    private static function cacheKey(string $url, string $fmt): string
    {
        return md5($url . '|' . $fmt . '|' . App::botIndex());
    }

    private static function quotaKey(int $uid): string
    {
        return $uid . ':' . date('Ymd');
    }

    private static function usedToday(int $uid): int
    {
        return (int) Store::get('dl_quota', self::quotaKey($uid), 0);
    }

    private static function lastLine(string $err): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $err)), static fn($l) => $l !== ''));
        foreach (array_reverse($lines) as $l) {
            if (stripos($l, 'ERROR') !== false) {
                return mb_substr($l, 0, 300);
            }
        }
        return mb_substr((string) end($lines), 0, 300);
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/{,.}[!.]*', GLOB_BRACE) as $f) {
            is_dir($f) ? self::rmrf($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    public static function cleanupTmp(): void
    {
        foreach ((array) glob(self::TMP . '/*') as $f) {
            if (is_dir($f) && time() - (int) @filemtime($f) > self::jobTime() + 60) {
                self::rmrf($f);
            } elseif (is_file($f) && str_starts_with(basename($f), 'cookies-') && time() - (int) @filemtime($f) > 3600) {
                @unlink($f);
            }
        }
    }
}
