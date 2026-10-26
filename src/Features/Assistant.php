<?php

final class Assistant
{
    private const TAGS = [
        'title'         => 'عنوان',
        'artist'        => 'خواننده',
        'album'         => 'آلبوم',
        'album_artist'  => 'خواننده آلبوم',
        'date'          => 'تاریخ ساخت آهنگ',
        'year'          => 'سال',
        'originaldate'  => 'تاریخ انتشار اصلی',
        'genre'         => 'ژانر',
        'track'         => 'شماره ترک',
        'disc'          => 'شماره دیسک',
        'composer'      => 'آهنگساز',
        'lyricist'      => 'ترانه‌سرا',
        'comment'       => 'توضیحات',
        'copyright'     => 'کپی‌رایت',
        'publisher'     => 'ناشر',
        'language'      => 'زبان',
        'lyrics'        => 'متن آهنگ',
        'creation_time' => 'زمان ساخت فایل',
    ];

    private static function decideWebSearch(string $question, array $history = []): ?string
    {
        $question = trim($question);
        if ($question === '' || mb_strlen($question) < 4) {
            return null;
        }

        $messages = [[
            'role' => 'system',
            'content' => "تو دستیار تصمیم‌گیری جست‌وجوی وبِ نالینی هستی. بر اساس نیاز واقعی کاربر تصمیم بگیر، نه بر اساس تطبیق کلمه‌های کلیدی. اگر پاسخ دقیق به اطلاعات جدید یا به‌روز، بررسی خبر یا ادعا، تأیید شایعه، وضعیت فعلی افراد و رویدادها، قیمت‌ها، نسخه‌های جدید، برنامه‌ها، آب‌وهوا یا اطلاعات تخصصی نامطمئن نیاز دارد، جست‌وجو را انتخاب کن. اگر کاربر صریحاً خواست در اینترنت بگردی یا منبع را بررسی کنی نیز جست‌وجو کن. برای دانش عمومیِ پایدار، ریاضی، کمک برنامه‌نویسی بر اساس کد ارائه‌شده، ترجمه، بازنویسی، نوشتن خلاقانه و گفت‌وگوی عادی، مگر اینکه بررسی بیرونی واقعاً لازم باشد، جست‌وجو نکن. اگر جست‌وجو لازم است، فقط با قالب «SEARCH: عبارت جست‌وجوی مستقل و کوتاه به زبان کاربر» پاسخ بده. اگر لازم نیست، فقط «NO_SEARCH» بنویس. به کاربر پاسخ نده و توضیح اضافه نکن."
        ]];
        foreach (array_slice($history, -4) as $item) {
            if (isset($item['role'], $item['content']) && in_array($item['role'], ['user', 'assistant'], true) && is_string($item['content'])) {
                $messages[] = ['role' => $item['role'], 'content' => mb_substr($item['content'], 0, 1200)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($question, 0, 2000)];

        $decision = AI::chat($messages, [
            'tier' => 'lite',
            'temperature' => 0,
            'deadline' => microtime(true) + 12,
            'section' => 'web_search_decision',
        ]);
        if (!is_string($decision) || trim($decision) === '') {
            logWarn('tavily', 'Search decision model returned no decision');
            return null;
        }
        $decision = trim($decision);
        if (!preg_match('/^SEARCH\s*:\s*(.+)$/isu', $decision, $match)) {
            return null;
        }
        $query = trim(preg_replace('/\s+/u', ' ', $match[1]) ?? $match[1]);
        return $query !== '' ? mb_substr($query, 0, 500) : null;
    }

    private static function tavilyContext(string $question, array $history = []): ?string
    {
        $key = trim((string) env('TAVILY_API_KEY', ''));
        if ($key === '') {
            return null;
        }
        $query = self::decideWebSearch($question, $history);
        if ($query === null) {
            return null;
        }
        $response = Http::postJson('https://api.tavily.com/search', [
            'api_key' => $key,
            'query' => $query,
            'search_depth' => 'advanced',
            'topic' => 'general',
            'max_results' => 5,
            'include_answer' => false,
            'include_raw_content' => 'text',
            'include_images' => false,
        ], [], 18);
        if ($response['code'] < 200 || $response['code'] >= 300 || !is_array($response['data'])) {
            logWarn('tavily', 'Search failed: HTTP ' . $response['code']);
            return null;
        }
        $results = (array) ($response['data']['results'] ?? []);
        if (!$results) {
            return null;
        }
        $lines = [];
        foreach (array_slice($results, 0, 5) as $i => $result) {
            if (!is_array($result)) {
                continue;
            }
            $title = trim((string) ($result['title'] ?? ''));
            $content = trim((string) ($result['raw_content'] ?? $result['content'] ?? ''));
            if ($title === '' && $content === '') {
                continue;
            }
            $lines[] = ($i + 1) . '. ' . $title . "\nمحتوا: " . mb_substr($content, 0, 3500);
        }
        return $lines ? implode("\n\n", $lines) : null;
    }

    private static function memKey(int $chatId, int $userId): string
    {
        return "{$chatId}_{$userId}";
    }

    public static function history(int $chatId, int $userId): array
    {
        $m = Store::get('ai_memory', self::memKey($chatId, $userId));
        $msgs = is_array($m) && ($m['day'] ?? '') === date('Y-m-d') ? ($m['messages'] ?? []) : [];
        
        return array_values(array_filter($msgs, static fn($x) => !preg_match('/یه عکس براش ساختم|\[\[\s*image/u', (string) ($x['content'] ?? ''))));
    }

    private static function remember(int $chatId, int $userId, string $user, string $assistant): void
    {
        $limit = max(2, (int) App::cfg('ai_memory_messages', 20));
        $day   = date('Y-m-d');
        Store::update('ai_memory', self::memKey($chatId, $userId), static function ($m) use ($user, $assistant, $limit, $day) {
            $msgs   = is_array($m) && ($m['day'] ?? '') === $day ? ($m['messages'] ?? []) : [];
            $msgs[] = ['role' => 'user', 'content' => mb_substr($user, 0, 3000)];
            $msgs[] = ['role' => 'assistant', 'content' => mb_substr($assistant, 0, 3000)];
            return ['at' => time(), 'day' => $day, 'messages' => array_slice($msgs, -$limit)];
        });
    }

    private static function role(int $chatId, int $userId): ?string
    {
        $r = App::tg()->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId], 8);
        $m = $r['result'] ?? null;
        if (empty($r['ok']) || !is_array($m)) {
            return null;
        }
        $role = match ($m['status'] ?? '') {
            'creator'       => 'مالک گروه',
            'administrator' => 'ادمین',
            'restricted', 'member' => 'ممبر',
            default         => '',
        };
        if ($role === '') {
            return null;
        }
        $tag = trim((string) ($m['custom_title'] ?? $m['tag'] ?? ''));
        return $role . ($tag !== '' ? " (تگ: {$tag})" : '');
    }

    private static function chatInfo(int $chatId): array
    {
        return Store::remember('cache', 'gchat_' . $chatId, 3600, static function () use ($chatId) {
            $r = App::tg()->call('getChat', ['chat_id' => $chatId], 8);
            return !empty($r['ok']) ? ['title' => $r['result']['title'] ?? '', 'bio' => $r['result']['description'] ?? ''] : null;
        }) ?? ['title' => '', 'bio' => ''];
    }

    private static function bestPhoto(array $msg): ?string
    {
        $photos = $msg['photo'] ?? [];
        if (!$photos) {
            return self::frameId($msg);
        }
        $best = end($photos);
        return $best['file_id'] ?? null;
    }

    public static function frameId(array $msg): ?string
    {
        $s = $msg['sticker'] ?? null;
        if (is_array($s) && empty($s['is_animated']) && empty($s['is_video'])) {
            return $s['file_id'] ?? null;
        }
        $m = $s ?? $msg['animation'] ?? $msg['video'] ?? $msg['video_note'] ?? null;
        $t = is_array($m) ? ($m['thumbnail'] ?? $m['thumb'] ?? null) : null;
        return is_array($t) ? ($t['file_id'] ?? null) : null;
    }

    
    public static function isFrame(array $m): bool
    {
        if (isset($m['photo'])) {
            return false;
        }
        $s = $m['sticker'] ?? null;
        if (is_array($s)) {
            return (!empty($s['is_animated']) || !empty($s['is_video'])) && self::frameId($m) !== null;
        }
        return (isset($m['animation']) || isset($m['video']) || isset($m['video_note'])) && self::frameId($m) !== null;
    }

    
    public static function withAlbum(string $note, array $m): string
    {
        if (empty($m['media_group_id']) || !str_starts_with($note, '[کاربر') || !str_ends_with($note, ']')) {
            return $note;
        }
        return substr($note, 0, -1) . '؛ این بخشی از یه آلبوم چندتاییه و فقط همین یکی پیوست شده]';
    }

    public static function fileInfo(array $m): ?string
    {
        $a = $m['audio'] ?? (empty($m['animation']) ? ($m['document'] ?? null) : null);
        if (!is_array($a)) {
            return null;
        }
        $name  = (string) ($a['file_name'] ?? '');
        $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(ltrim((string) strrchr((string) ($a['mime_type'] ?? ''), '/'), '/'));
        }
        $music = isset($m['audio']) || str_starts_with((string) ($a['mime_type'] ?? ''), 'audio/');
        $size  = (int) ($a['file_size'] ?? 0);
        $f     = ['نوع: ' . ($music ? 'آهنگ' : 'فایل') . ($ext !== '' ? " ({$ext})" : '')];
        if ($name !== '') {
            $f[] = 'نام: ' . $name;
        }
        if ($music) {
            $f = array_merge($f, self::musicInfo($a));
        }
        if ($size > 0) {
            $f[] = 'حجم: ' . ($size >= 1048576 ? round($size / 1048576, 1) . ' مگابایت' : max(1, (int) round($size / 1024)) . ' کیلوبایت');
        }
        return implode(' | ', $f);
    }

    public static function dataInfo(array $m): ?array
    {
        if (is_array($m['poll'] ?? null)) {
            $p = $m['poll'];
            $o = [];
            foreach ((array) ($p['options'] ?? []) as $i => $opt) {
                $o[] = ($i + 1) . ') ' . ($opt['text'] ?? '') . (isset($opt['voter_count']) ? ' (' . $opt['voter_count'] . ' رأی)' : '');
            }
            $quiz = ($p['type'] ?? '') === 'quiz';
            $f    = ['سؤال: ' . ($p['question'] ?? ''), 'گزینه‌ها: ' . implode('، ', $o)];
            if (isset($p['total_voter_count'])) {
                $f[] = 'مجموع رأی: ' . $p['total_voter_count'];
            }
            $f[] = !empty($p['is_anonymous']) ? 'ناشناس' : 'عمومی';
            if (!empty($p['allows_multiple_answers'])) {
                $f[] = 'چندجوابی';
            }
            if (!empty($p['is_closed'])) {
                $f[] = 'بسته‌شده';
            }
            if ($quiz && isset($p['correct_option_id'])) {
                $f[] = 'جواب درست: گزینه‌ی ' . ((int) $p['correct_option_id'] + 1);
            }
            if (!empty($p['explanation'])) {
                $f[] = 'توضیح: ' . $p['explanation'];
            }
            return [$quiz ? 'آزمون' : 'نظرسنجی', implode(' | ', $f)];
        }
        $v = is_array($m['venue'] ?? null) ? $m['venue'] : null;
        $l = $v['location'] ?? $m['location'] ?? null;
        if (!is_array($l) || !isset($l['latitude'], $l['longitude'])) {
            return null;
        }
        $f = [];
        if (!empty($v['title'])) {
            $f[] = 'نام: ' . $v['title'];
        }
        if (!empty($v['address'])) {
            $f[] = 'آدرس: ' . $v['address'];
        }
        $f[] = 'عرض جغرافیایی: ' . $l['latitude'];
        $f[] = 'طول جغرافیایی: ' . $l['longitude'];
        if (!empty($l['live_period'])) {
            $f[] = 'لوکیشن زنده برای ' . intdiv((int) $l['live_period'], 60) . ' دقیقه';
        }
        if (($place = self::placeName((float) $l['latitude'], (float) $l['longitude'])) !== '') {
            $f[] = 'آدرس تقریبی روی نقشه: ' . $place;
        }
        return [$v ? 'مکان' : 'لوکیشن', implode(' | ', $f)];
    }

    private static function placeName(float $lat, float $lon): string
    {
        $j = Http::getJson('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=16&accept-language=fa&lat=' . $lat . '&lon=' . $lon, ['User-Agent: NulliniBot'], 8);
        return is_array($j) && !empty($j['display_name']) ? (string) $j['display_name'] : '';
    }

    private static function musicInfo(array $a): array
    {
        $j   = [];
        $fid = (string) ($a['file_id'] ?? '');
        if ($fid !== '' && (int) ($a['file_size'] ?? 0) <= 20_000_000 && ($bytes = App::tg()->download($fid)) !== null && ($tmp = tempnam(sys_get_temp_dir(), 'nl_au_')) !== false) {
            try {
                if (file_put_contents($tmp, $bytes) !== false) {
                    $o = [];
                    @exec('timeout 20 ffprobe -v error -print_format json -show_format -show_streams ' . escapeshellarg($tmp) . ' 2>/dev/null', $o);
                    $j = json_decode(implode('', $o), true) ?: [];
                }
            } finally {
                @unlink($tmp);
            }
        }
        $src    = [(array) ($j['format']['tags'] ?? [])];
        $stream = [];
        foreach ((array) ($j['streams'] ?? []) as $st) {
            if (($st['codec_type'] ?? '') === 'audio') {
                $stream = $stream ?: $st;
                $src[]  = (array) ($st['tags'] ?? []);
            }
        }
        $tags = [];
        foreach ($src as $t) {
            foreach ($t as $k => $v) {
                $k = strtolower((string) $k);
                $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
                if ($v !== '' && !isset($tags[$k])) {
                    $tags[$k] = $v;
                }
            }
        }
        if (!isset($tags['title']) && !empty($a['title'])) {
            $tags['title'] = (string) $a['title'];
        }
        if (!isset($tags['artist']) && !empty($a['performer'])) {
            $tags['artist'] = (string) $a['performer'];
        }
        $f = [];
        foreach (array_slice($tags, 0, 40, true) as $k => $v) {
            $f[] = (self::TAGS[$k] ?? $k) . ': ' . mb_substr($v, 0, 300);
        }
        $dur = (int) round((float) ($j['format']['duration'] ?? $a['duration'] ?? 0));
        if ($dur > 0) {
            $f[] = 'مدت: ' . intdiv($dur, 60) . ':' . sprintf('%02d', $dur % 60);
        }
        if (!empty($stream['codec_name'])) {
            $f[] = 'کدک: ' . $stream['codec_name'];
        }
        $br = (int) ($j['format']['bit_rate'] ?? $stream['bit_rate'] ?? 0);
        if ($br > 0) {
            $f[] = 'بیت‌ریت: ' . (int) round($br / 1000) . ' kbps';
        }
        if (!empty($stream['sample_rate'])) {
            $f[] = 'سمپل‌ریت: ' . $stream['sample_rate'] . ' Hz';
        }
        if (!empty($stream['channels'])) {
            $f[] = 'کانال: ' . $stream['channels'];
        }
        return $f;
    }

    public static function hasMedia(array $m): bool
    {
        return isset($m['voice']) || isset($m['audio']) || isset($m['document']) || isset($m['poll']) || isset($m['venue']) || isset($m['location']) || isset($m['photo']) || isset($m['sticker']) || isset($m['animation']) || isset($m['video']) || isset($m['video_note']);
    }

    public static function mediaPart(array $m): ?array
    {
        $tg = App::tg();
        if (isset($m['voice'])) {
            $a     = $m['voice'];
            $bytes = ($a['file_size'] ?? 0) <= 20_000_000 ? $tg->download($a['file_id']) : null;
            return $bytes ? ['type' => 'media', 'mime' => $a['mime_type'] ?? 'audio/ogg', 'data' => base64_encode($bytes)] : null;
        }
        if ($fid = self::bestPhoto($m)) {
            $bytes = $tg->download($fid);
            $info  = $bytes ? @getimagesizefromstring($bytes) : false;
            if ($info === false) {
                return null;
            }
            return ['type' => 'media', 'mime' => $info['mime'] ?? 'image/jpeg', 'data' => base64_encode($bytes)];
        }
        return null;
    }

    private static function routeKind(array $m): string
    {
        return match (true) {
            isset($m['photo'])                            => 'photo',
            isset($m['voice'])                            => 'voice message',
            isset($m['audio'])                            => 'music file',
            isset($m['sticker'])                          => 'sticker',
            isset($m['animation'])                        => 'gif',
            isset($m['video']) || isset($m['video_note']) => 'video',
            isset($m['document'])                         => 'file',
            isset($m['poll'])                             => 'poll',
            isset($m['venue']) || isset($m['location'])   => 'location',
            default                                       => '',
        };
    }

    public static function routeContext(array $msg, array $history = []): array
    {
        $reply = $msg['reply_to_message'] ?? null;
        return [
            'attach'     => self::routeKind($msg),
            'reply_kind' => is_array($reply) ? self::routeKind($reply) : '',
            'reply_text' => is_array($reply) ? trim((string) ($reply['text'] ?? $reply['caption'] ?? '')) : '',
            'reply_bot'  => !empty($reply['from']['is_bot']),
            'history'    => $history,
        ];
    }

    public static function kind(array $m): string
    {
        return match (true) {
            isset($m['voice'])                       => 'ویس',
            isset($m['audio'])                       => 'آهنگ',
            isset($m['photo'])                       => 'عکس',
            isset($m['sticker'])                     => 'استیکر',
            isset($m['animation'])                   => 'گیف',
            isset($m['video']) || isset($m['video_note']) => 'ویدیو',
            isset($m['document'])                    => 'فایل',
            isset($m['poll'])                        => 'نظرسنجی',
            isset($m['venue'])                       => 'مکان',
            isset($m['location'])                    => 'لوکیشن',
            default                                  => 'پیام',
        };
    }

    private static function typing(int $chatId, string $answer): void
    {
        if (!App::cfg('ai_typing_effect', true)) {
            return;
        }
        $seconds = min(3.5, max(0.8, mb_strlen($answer) / 40));
        App::tg()->action($chatId, 'typing');
        usleep((int) ($seconds * 1_000_000));
    }

    private static function send(array $msg, string $answer): void
    {
        $tg = App::tg();
        foreach (AI::chunks($answer) as $part) {
            $r = $tg->reply($msg, AI::toHtml($part));
            if (empty($r['ok'])) {
                $tg->call('sendMessage', [
                    'chat_id'          => $msg['chat']['id'],
                    'text'             => mb_substr($part, 0, 4096),
                    'reply_parameters' => ['message_id' => $msg['message_id'], 'allow_sending_without_reply' => true],
                ]);
            }
        }
    }

    public static function handle(array $msg, string $question): void
    {
        $tg       = App::tg();
        $chatId   = (int) $msg['chat']['id'];
        $chatType = (string) $msg['chat']['type'];
        $from     = $msg['from'] ?? [];
        $userId   = (int) ($from['id'] ?? 0);
        $service  = AI::isServiceSender($from, $msg['sender_chat'] ?? null);

        $tg->action($chatId, 'typing');
        Users::hit($chatType === 'private' ? 'ai:private' : 'ai:group');

        $ctx = ['user_name' => $service ? '' : userName($from), 'service' => $service, 'chat_type' => $chatType];
        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $info              = self::chatInfo($chatId);
            $ctx['chat_title'] = $info['title'];
            $ctx['chat_bio']   = mb_substr((string) $info['bio'], 0, 300);
            if (!$service && $userId) {
                $ctx['user_role'] = self::role($chatId, $userId);
            }
            $rf = $msg['reply_to_message']['from'] ?? null;
            $rid = (int) ($rf['id'] ?? 0);
            if ($rid && empty($rf['is_bot']) && empty($msg['reply_to_message']['sender_chat']) && empty($msg['reply_to_message']['is_automatic_forward'])) {
                $ctx['reply_role'] = $rid === $userId && isset($ctx['user_role']) ? $ctx['user_role'] : self::role($chatId, $rid);
            }
        }

        $parts     = [];
        $notes     = [];
        $log       = $question;
        $voiceText = '';

        $reply = $msg['reply_to_message'] ?? null;
        $n0    = count($notes);
        if ($reply) {
            $rt = trim((string) ($reply['text'] ?? $reply['caption'] ?? ''));
            if ($media = self::mediaPart($reply)) {
                $parts[] = $media;
                $notes[] = '[کاربر روی یه ' . self::kind($reply) . ' ریپلای کرده که پیوست شده' . (self::isFrame($reply) ? '؛ فقط عکسِ فریم اولش پیوسته' : '') . ($rt !== '' ? "؛ متنش: {$rt}" : '') . ']';
            } elseif ($info = self::fileInfo($reply)) {
                $notes[] = "[کاربر روی یه فایل ریپلای کرده؛ {$info}" . ($rt !== '' ? "؛ متنش: {$rt}" : '') . ']';
            } elseif ($data = self::dataInfo($reply)) {
                $notes[] = "[کاربر روی یه {$data[0]} ریپلای کرده؛ {$data[1]}]";
            } elseif ($rt !== '') {
                $notes[] = "[پیامی که کاربر روش ریپلای کرده]: {$rt}";
            } elseif (!empty($reply['from'])) {
                $notes[] = '[کاربر روی یه پیام ریپلای کرده که متن نداره]';
            }
        }
        if ($reply && count($notes) > $n0) {
            $notes[$n0] = self::withAlbum($notes[$n0], $reply);
        }
        $n1 = count($notes);

        if (self::hasMedia($msg)) {
            $media = self::mediaPart($msg);
            $info  = self::fileInfo($msg);
            $data  = self::dataInfo($msg);
            if (!$media && $info === null && $data === null) {
                $tg->reply($msg, '😕 نتونستم فایلت رو دانلود کنم؛' . "\n" . 'دوباره بفرست.');
                return;
            }
            if ($media) {
                $parts[] = $media;
            }
            if ($media && $question === '' && isset($msg['voice']) && !$service) {
                $voiceText = trim((string) AI::transcribe((string) base64_decode((string) $media['data']), (string) $media['mime']));
            }
            $isVoice = isset($msg['voice']);
            $notes[] = $data !== null
                ? "[کاربر یه {$data[0]} فرستاده؛ {$data[1]}]"
                : ($info !== null
                ? "[کاربر یه فایل فرستاده؛ {$info}]"
                : ($isVoice
                    ? '[کاربر ویس فرستاده؛ گوش بده و دقیقاً به حرفش جواب بده، انگار متنی پرسیده]'
                    : (isset($msg['photo']) ? '[کاربر عکس فرستاده؛ ببینش و جواب بده]' : '[کاربر ' . self::kind($msg) . ' فرستاده؛ ' . (self::isFrame($msg) ? 'فقط عکسِ فریم اولش پیوست شده' : 'عکسش پیوست شده') . '، ببینش و جواب بده]')));
            $log = '[' . self::kind($msg) . '] ' . ($question !== '' ? $question : $voiceText);
            if (count($notes) > $n1) {
                $notes[$n1] = self::withAlbum($notes[$n1], $msg);
            }
        }

        $text = trim(implode("\n", $notes) . ($question !== '' ? "\n\n{$question}" : ''));
        if ($text === '') {
            $text = 'سلام';
        }
        $content = $parts ? array_merge($parts, [['type' => 'text', 'text' => $text]]) : $text;
        if ($notes && $reply) {
            $log = $notes[0] . "\n" . $log;
        }

        $history = $service ? [] : self::history($chatId, $userId);

        if (!$service && ($act = Intent::decide($question !== '' ? $question : $voiceText, self::routeContext($msg, $history))) !== null) {
            [$name, $arg] = $act;
            if ($name === 'image' && $arg === '') {
                $arg = $voiceText;
            }
            if (Intent::run($msg, $name, $arg)) {
                self::remember($chatId, $userId, $log, '[' . $name . ($arg !== '' ? ': ' . $arg : '') . ' رو براش انجام دادم]');
                return;
            }
        }

        if (!$service && $question !== '') {
            $webContext = self::tavilyContext($question, $history);
            if ($webContext !== null) {
                $ctx['web_search_context'] = $webContext;
            }
        }

        $live    = App::cfg('ai_stream', true) ? new LiveReply($msg) : null;
        $opt     = ['max_len' => PHP_INT_MAX] + ($live ? ['on_text' => $live->feed(...)] : []);
        $res     = AI::reply($content, $ctx, $history, $opt);

        if ($res['reaction'] !== null) {
            $tg->react($chatId, (int) $msg['message_id'], $res['reaction']);
        }

        $answer = $res['answer'] ?? AI::FAIL_TEXT;
        if (isset($ctx['web_search_context'])) {
            $answer = preg_replace('~https?://\S+|www\.\S+~iu', '', $answer) ?? $answer;
            $answer = preg_replace('/[ \t]+([,،؛.!؟])/u', '$1', $answer) ?? $answer;
            $answer = trim(preg_replace('/\n{3,}/u', "\n\n", $answer) ?? $answer);
        }

        if (!$live || !$live->finish($answer)) {
            self::typing($chatId, $answer);
            self::send($msg, $answer);
        }

        if ($res['answer'] !== null && !$service) {
            self::remember($chatId, $userId, $log, $res['answer']);
        }
    }

    public static function translate(array $msg, string $text, string $target = 'auto'): void
    {
        $tg     = App::tg();
        $source = trim($text);
        if ($source === '' && isset($msg['reply_to_message'])) {
            $source = trim((string) ($msg['reply_to_message']['text'] ?? $msg['reply_to_message']['caption'] ?? ''));
        }
        if ($source === '') {
            $tg->reply($msg, Ui::guide('translate'));
            return;
        }
        $tg->action($msg['chat']['id'], 'typing');
        $out = AI::translateFast($source, $target !== '' ? $target : 'auto');
        $tg->reply($msg, $out ? "🌐 <b>ترجمه:</b>\n\n" . h($out) : AI::FAIL_TEXT);
    }
}
