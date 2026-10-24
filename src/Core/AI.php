<?php

final class AI
{
    public const REACTIONS = [
        '👍', '👎', '❤', '🔥', '🥰', '👏', '😁', '🤔', '🤯', '😱', '🤬', '😢', '🎉', '🤩', '🤮',
        '💩', '🙏', '👌', '🕊', '🤡', '🥱', '🥴', '😍', '🐳', '❤‍🔥', '🌚', '🌭', '💯', '🤣', '⚡',
        '🍌', '🏆', '💔', '🤨', '😐', '🍓', '🍾', '💋', '🖕', '😈', '😴', '😭', '🤓', '👻', '👨‍💻',
        '👀', '🎃', '🙈', '😇', '😨', '🤝', '✍', '🤗', '🫡', '🎅', '🎄', '☃', '💅', '🤪', '🗿',
        '🆒', '💘', '🙉', '🦄', '😘', '💊', '🙊', '😎', '👾', '🤷‍♂', '🤷', '🤷‍♀', '😡',
    ];

    public const SERVICE_IDS = [777000, 1087968824, 136817688];

    public const FAIL_TEXT = '😕 الان مغزم هنگ کرده، یه‌کم دیگه دوباره بپرس.';

    private const API = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const STREAM_API = 'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse';

    private const SAFETY = ['HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH', 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT'];

    public static function keys(): array
    {
        $keys = array_map('trim', (array) (App::cfg('ai', [])['keys'] ?? []));
        return array_values(array_unique(array_filter($keys, static fn($k) => $k !== '' && !preg_match('/^(PUT|YOUR)_/i', $k))));
    }

    public static function models(string $tier = 'smart'): array
    {
        $cfg = (array) App::cfg('ai', []);
        return array_values((array) ($tier === 'lite' ? ($cfg['lite_models'] ?? []) : ($cfg['models'] ?? [])));
    }

    public static function allModels(): array
    {
        return array_values(array_unique(array_merge(self::models('smart'), self::models('lite'))));
    }

    private static function startIndex(int $count): int
    {
        if ($count <= 1) {
            return 0;
        }
        $idx = 0;
        Store::update('ai', 'rr', static function ($s) use (&$idx, $count) {
            $idx = ((int) ($s['i'] ?? 0)) % $count;
            return ['i' => ($idx + 1) % $count];
        });
        return $idx;
    }

    private static function coolKey(string $key, string $model): string
    {
        return 'cool_' . substr(md5($key), 0, 10) . '_' . $model;
    }

    public static function quotaResetAt(): int
    {
        return (new DateTime('tomorrow', new DateTimeZone('America/Los_Angeles')))->getTimestamp() + 60;
    }

    private static function cool(string $key, string $model, int $until, string $why): void
    {
        Store::set('ai', self::coolKey($key, $model), ['until' => $until, 'why' => $why, 'model' => $model]);
    }

    private static function isCooling(string $key, string $model): bool
    {
        $c = Store::get('ai', self::coolKey($key, $model));
        return is_array($c) && (int) ($c['until'] ?? 0) > time();
    }

    public static function toGemini(array $messages): array
    {
        $system   = [];
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                $system[] = (string) $m['content'];
                continue;
            }
            $parts = [];
            foreach (is_array($m['content']) ? $m['content'] : [['type' => 'text', 'text' => (string) $m['content']]] as $p) {
                $type = $p['type'] ?? 'text';
                if ($type === 'text') {
                    if (($p['text'] ?? '') !== '') {
                        $parts[] = ['text' => (string) $p['text']];
                    }
                } elseif ($type === 'media') {
                    $parts[] = ['inline_data' => ['mime_type' => $p['mime'], 'data' => $p['data']]];
                } elseif ($type === 'image_url' && preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $p['image_url']['url'] ?? '', $mm)) {
                    $parts[] = ['inline_data' => ['mime_type' => $mm[1], 'data' => $mm[2]]];
                }
            }
            if ($parts) {
                $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => $parts];
            }
        }
        return [implode("\n\n", $system), $contents];
    }

    private static function inlineSystem(array $contents, string $system): array
    {
        foreach ($contents as $i => $c) {
            if (($c['role'] ?? '') === 'user') {
                array_unshift($contents[$i]['parts'], ['text' => "[دستورالعمل]\n{$system}\n[پایان دستورالعمل]"]);
                return $contents;
            }
        }
        return $contents;
    }

    public static function route(): string
    {
        $v = (string) Store::get('cfg', 'ai_route', '');
        if ($v === '') {
            $v = (string) (((array) Store::get('cfg', 'ai_routes', []))['chat'] ?? 'gemini');
        }
        return self::validRoute($v) ? $v : 'gemini';
    }

    public static function validRoute(string $route): bool
    {
        if ($route === 'gemini') {
            return true;
        }
        if (str_starts_with($route, 'gemini:')) {
            return in_array(substr($route, 7), self::allModels(), true);
        }
        return FreeAI::valid($route);
    }

    public static function setRoute(string $route): void
    {
        if (self::validRoute($route)) {
            Store::set('cfg', 'ai_route', $route);
        }
    }

    public static function hasTiers(): bool
    {
        return self::route() === 'gemini';
    }

    public static function chat(array $messages, array $opt = []): ?string
    {
        $route = self::route();
        if ($route !== 'gemini' && !str_starts_with($route, 'gemini:')) {
            $messages = self::audioToText($messages, explode(':', $route, 2)[0], $opt['deadline'] ?? null);
            return $messages === null ? null : FreeAI::chat($route, $messages, $opt);
        }
        return self::gemini($messages, $opt, $route === 'gemini' ? null : substr($route, 7));
    }

    private static function audioToText(array $messages, string $prov, ?float $deadline): ?array
    {
        foreach ($messages as $i => $m) {
            if (!is_array($m['content'] ?? null)) {
                continue;
            }
            foreach ($m['content'] as $j => $p) {
                if (($p['type'] ?? '') !== 'media' || !str_starts_with((string) ($p['mime'] ?? ''), 'audio/')) {
                    continue;
                }
                $text = FreeAI::transcribe($prov, (string) base64_decode((string) ($p['data'] ?? '')), (string) $p['mime'], $deadline);
                if ($text === null) {
                    return null;
                }
                $messages[$i]['content'][$j] = ['type' => 'text', 'text' => "[متنِ ویس]: {$text}"];
            }
        }
        return $messages;
    }

    private static function gemini(array $messages, array $opt = [], ?string $only = null): ?string
    {
        $keys = self::keys();
        if (!$keys) {
            logError('ai', 'no Gemini key configured (GEMINI_KEYS)');
            return null;
        }
        $deadline = $opt['deadline'] ?? (microtime(true) + 60);
        [$system, $contents] = self::toGemini($messages);

        $base = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => $opt['temperature'] ?? 0.7,
                'maxOutputTokens' => (int) ($opt['max_tokens'] ?? 2000) + 3000,
            ],
            'safetySettings'   => array_map(static fn($c) => ['category' => $c, 'threshold' => 'BLOCK_ONLY_HIGH'], self::SAFETY),
        ];
        if ($system !== '') {
            $base['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $n     = count($keys);
        $start = self::startIndex($n);

        $chain = $only !== null ? [$only] : self::models($opt['tier'] ?? 'smart');
        foreach ($chain as $model) {
            $thinking = array_key_exists('thinking', $opt) ? $opt['thinking'] : 'low';
            for ($i = 0; $i < $n; $i++) {
                $key = $keys[($start + $i) % $n];
                if (self::isCooling($key, $model)) {
                    continue;
                }
                $left = $deadline - microtime(true);
                if ($left < 2) {
                    return null;
                }

                $payload = $base;
                $gemma   = str_starts_with($model, 'gemma');
                if ($gemma && isset($payload['systemInstruction'])) {
                    unset($payload['systemInstruction']);
                    $payload['contents'] = self::inlineSystem($contents, $system);
                }
                if ($thinking && !$gemma) {
                    $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $thinking];
                }
                $r = is_callable($opt['stream'] ?? null)
                    ? self::geminiStream($model, $payload, $key, (int) max(5, min($left, 55)), $opt['stream'])
                    : Http::postJson(sprintf(self::API, rawurlencode($model)), $payload, ['x-goog-api-key: ' . $key], (int) max(3, min($left, 10)));

                if ($r['code'] === 200 && $r['data'] !== null) {
                    $text = self::extractText($r['data']);
                    if ($text !== null) {
                        Users::hit('model:' . $model);
                        return $text;
                    }
                    logWarn('ai', "{$model}: empty reply (" . ($r['data']['candidates'][0]['finishReason'] ?? $r['data']['promptFeedback']['blockReason'] ?? '?') . ')');
                    continue 2;
                }

                $raw = (string) $r['raw'];
                logWarn('ai', "{$model} key" . (($start + $i) % $n) . " HTTP {$r['code']}: " . mb_substr((string) ($r['data']['error']['message'] ?? $raw), 0, 160));

                switch (true) {
                    case $r['code'] === 429:
                        if (preg_match('/per ?day|PerDay|daily/i', $raw)) {
                            self::cool($key, $model, self::quotaResetAt(), 'سهمیه‌ی روزانه تموم شد');
                        } else {
                            $wait = preg_match('/"retryDelay"\s*:\s*"(\d+)/', $raw, $w) ? (int) $w[1] : 60;
                            self::cool($key, $model, time() + max(10, min($wait, 600)), 'محدودیت دقیقه‌ای');
                        }
                        break;
                    case $r['code'] === 400 && $thinking && stripos($raw, 'think') !== false:
                        $thinking = null;
                        $i--;
                        break;
                    case $r['code'] === 400:
                        if (preg_match('/model.{0,80}(not found|not supported|not available|is not enabled|unsupported)/is', $raw)) {
                            self::cool($key, $model, time() + 3600, 'برای این کلید در دسترس نیست');
                        }
                        continue 3;
                    case $r['code'] === 404:
                        self::cool($key, $model, time() + 86400, 'مدل پیدا نشد');
                        continue 3;
                    case $r['code'] === 401 || $r['code'] === 403:
                        self::cool($key, $model, time() + 3600, 'کلید نامعتبر یا بدون دسترسی');
                        break;
                    case $r['code'] === 0 || $r['code'] === 503 || $r['code'] === 504:
                        self::cool($key, $model, time() + 120, 'شلوغه / جواب نداد');
                        continue 3;
                }
            }
        }
        return null;
    }

    private static function geminiStream(string $model, array $payload, string $key, int $timeout, callable $emit): array
    {
        $text   = '';
        $buf    = '';
        $finish = null;

        $r = Http::stream(sprintf(self::STREAM_API, rawurlencode($model)), $payload, ['x-goog-api-key: ' . $key], $timeout,
            static function (string $chunk) use (&$text, &$buf, &$finish, $emit): void {
                foreach (Http::sseData($buf, $chunk) as $json) {
                    $d = json_decode($json, true);
                    if (!is_array($d)) {
                        continue;
                    }
                    $finish = $d['candidates'][0]['finishReason'] ?? $d['promptFeedback']['blockReason'] ?? $finish;
                    $delta  = '';
                    foreach ($d['candidates'][0]['content']['parts'] ?? [] as $p) {
                        if (empty($p['thought']) && isset($p['text'])) {
                            $delta .= $p['text'];
                        }
                    }
                    if ($delta !== '') {
                        $text .= $delta;
                        $emit($text);
                    }
                }
            });

        if ($r['code'] === 200) {
            if ($text === '' && $r['error'] !== '') {
                return ['code' => 0, 'data' => null, 'raw' => $r['error']];
            }
            $cand = $text !== '' ? ['content' => ['parts' => [['text' => $text]]]] : ['finishReason' => $finish];
            return ['code' => 200, 'data' => ['candidates' => [$cand]], 'raw' => ''];
        }

        $data = $r['raw'] !== '' ? json_decode($r['raw'], true) : null;
        if (is_array($data) && isset($data[0]['error'])) {
            $data = $data[0];
        }
        return ['code' => $r['code'], 'data' => is_array($data) ? $data : null, 'raw' => $r['raw'] !== '' ? $r['raw'] : $r['error']];
    }

    private static function extractText(array $data): ?string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text  = '';
        foreach ($parts as $p) {
            if (empty($p['thought']) && isset($p['text'])) {
                $text .= $p['text'];
            }
        }
        $text = trim($text);
        return $text !== '' ? $text : null;
    }

    public static function shortModel(string $m): string
    {
        return preg_replace(['/^gemini-/', '/-it$/'], '', $m) ?? $m;
    }

    public static function quotaReport(): string
    {
        $use = [];
        foreach (Users::topEvents(1, 30) as $e) {
            if (str_starts_with($e['event'], 'model:')) {
                $use[] = "\u{2066}" . h(self::shortModel(substr($e['event'], 6))) . "\u{2069} · " . fa((int) $e['hits']);
            }
        }
        $all        = self::allModels();
        $groups     = [];
        $coolGemini = [];
        foreach (Db::run("SELECT v FROM kv WHERE ns = 'ai' AND k LIKE 'cool_%'")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            $c = json_decode((string) $raw, true);
            $m = is_array($c) ? (string) ($c['model'] ?? '') : '';
            if ($m === '' || (int) ($c['until'] ?? 0) <= time()) {
                continue;
            }
            $isGroq = str_starts_with($m, 'groq:');
            if (!$isGroq && !in_array($m, $all, true)) {
                continue;
            }
            $label = h((string) $c['why']) . ' (تا ' . Jalali::time((int) $c['until']) . ')';
            $groups[$label][$isGroq ? 'groq-' . substr($m, 5) : self::shortModel($m)] = true;
            if (!$isGroq) {
                $coolGemini[$m] = true;
            }
        }
        $cool = [];
        foreach ($groups as $label => $models) {
            $cool[] = "<b>{$label}:</b>\n" . implode("\n", array_map(static fn($m) => "▫️ \u{2066}" . h($m) . "\u{2069}", array_keys($models)));
        }
        $ready = count($all) - count($coolGemini);
        return "🎛 <b>مدل فعال (روی همه‌ی بخش‌ها):</b> " . bidi(h(AiPanel::label(self::route())))
            . "\n\n🧠 <b>مصرف امروز</b>\n" . ($use ? implode("\n", $use) : '— هنوز هیچی')
            . "\n\n⏸ <b>در استراحت</b>\n" . ($cool ? implode("\n\n", $cool) : '— هیچ‌کدوم 👌')
            . "\n\n✅ آماده (Gemini): " . fa((string) max(0, $ready)) . ' مدل'
            . "\n🔑 کلید: " . fa(count(self::keys())) . ' · ریست: ' . Jalali::time(self::quotaResetAt());
    }

    public static function isServiceSender(?array $from, ?array $senderChat = null): bool
    {
        if (!empty($senderChat) || empty($from)) {
            return true;
        }
        return in_array((int) ($from['id'] ?? 0), self::SERVICE_IDS, true);
    }

    public static function systemPrompt(array $ctx, bool $withReaction): string
    {
        $creator = App::cfg('creator_name', 'Arown');
        $bot     = '@' . App::botUsername();
        $now     = time();
        $date    = Jalali::fullDate($now) . ' ساعت ' . Jalali::time($now) . ' (' . date('Y-m-d') . ')';

        $l   = [];
        $l[] = "تو «نالینی» (Nullini) هستی؛ یه دستیار هوشمند، باحال و خودمونی داخل تلگرام ({$bot}). سازنده‌ت «{$creator}» است. هیچ‌وقت نگو مدل زبانیِ شرکت دیگه‌ای هستی.";
        $l[] = "الان {$date} به وقت ایرانه.";
        $l[] = 'با زبان خود کاربر جواب بده (پیش‌فرض فارسی محاوره‌ای). کوتاه و مفید جواب بده، مگر توضیح کامل خواسته بشه. دقیق، باهوش و صادق باش؛ اگه چیزی رو نمی‌دونی یا مطمئن نیستی، راحت بگو و از خودت اطلاعات نساز.';
        $l[] = 'برای سؤال‌های فنی، ریاضی، برنامه‌نویسی و استدلالی قدم‌به‌قدم و درست فکر کن ولی فقط نتیجه‌ی تمیز رو بنویس. کد رو کامل و درست بنویس.';
        $l[] = 'فرمت: متن ساده‌ی تلگرام؛ بدون ** یا # یا جدول مارک‌داون. برای لیست از «-» یا ایموجی اول خط استفاده کن. کد رو داخل ``` بذار.';
        $l[] = 'پیام‌های قبلی فقط زمینه‌ان؛ فقط به آخرین پیام کاربر جواب بده، مگه این‌که خودش به قبلی‌ها اشاره کنه. سؤال اضافه نپرس؛ اگه مبهم بود بهترین برداشت رو بکن.';
        $l[] = "اگه کاربر درباره‌ی قابلیت‌هات پرسید: تو هر چتی با نوشتن {$bot} منوی ابزارها باز می‌شه: ترجمه، قرعه‌کشی گروه و کانال، سرچ گوگل، ماشین‌حساب، نرخ ارز و طلا، آب‌وهوا، نجوا، QR، پسورد، شطرنج و کلی بازی دیگه. در گروه با «نالینی» و تو هر چتی (حتی بدون عضویت) با تگ کردنت صدات می‌کنن؛ می‌تونی عکس هم بسازی (مثلاً «نالینی یه عکس از گربه‌ی فضانورد بساز»).";

        $name = trim(preg_replace('/[\x{2066}-\x{2069}]/u', '', (string) ($ctx['user_name'] ?? '')));
        if ($name !== '' && empty($ctx['service'])) {
            $l[] = "اسم مخاطب «{$name}» است؛ گاهی (نه همیشه و نه اول جمله) صداش کن.";
        } else {
            $l[] = 'اسم مخاطب معلوم نیست (کانال یا ادمین ناشناس)؛ هیچ اسمی صدا نزن.';
        }

        if (in_array($ctx['chat_type'] ?? '', ['group', 'supergroup'], true)) {
            if (!empty($ctx['chat_title'])) {
                $l[] = "داخل گروه «{$ctx['chat_title']}» هستی" . (!empty($ctx['chat_bio']) ? " (بیو: {$ctx['chat_bio']})" : '') . '؛ لحنت همون‌قدر خودمونی بمونه.';
            }
        } elseif (($ctx['chat_type'] ?? '') === 'guest') {
            $l[] = 'تو یه چت تگت کردن که عضوش نیستی (حالت مهمان)؛ فقط همین پیام (و پیامی که روش ریپلای شده) رو می‌بینی. جوابت کامل و مستقل باشه.';
        } elseif (($ctx['chat_type'] ?? '') === 'miniapp') {
            $l[] = 'الان داخل مینی‌اپ نالینی (یه صفحه‌ی چت داخل تلگرام) با کاربر حرف می‌زنی.';
        } elseif (($ctx['chat_type'] ?? '') === 'inline') {
            $l[] = 'الان در حالت اینلاین جواب می‌دی؛ جوابت مستقیم وسط یه چت فرستاده می‌شه، پس کامل و مستقل باشه.';
        }

        if (($ctx['chat_type'] ?? '') !== 'inline') {
            $l[] = 'تو می‌تونی عکس بسازی. اگه منظور کاربر واقعاً ساختن یه تصویر جدید بود، به‌جای جواب فقط یک خط بنویس: «IMAGE: <توصیف کامل تصویر به انگلیسی: سوژه، فضا، سبک، نور؛ حداکثر ۷۰ کلمه>» و هیچ چیز دیگه‌ای ننویس. اگه فقط درباره‌ی یه عکس سؤال کرد، عکس نساز. محتوای جنسی یا خشونت شدید نکش.';
        }

        if (!in_array($ctx['chat_type'] ?? '', ['inline', 'miniapp'], true) && empty($ctx['no_action'])) {
            $l[] = Intent::prompt();
        }

        if ($withReaction) {
            $l[] = (self::isGroqRoute() ? 'خط اول جوابت همیشه و حتماً با کلمه‌ی REACTION و دونقطه شروع بشه: «REACTION: <ایموجی>»، متناسب با پیام، فقط یکی از این لیست (بیرون از لیست قبول نیست): ' : 'خط اول جوابت دقیقاً «REACTION: <ایموجی>» باشه، متناسب با پیام، فقط از این لیست: ') . implode(' ', self::REACTIONS) . '. بعد یه خط خالی و بعد جواب اصلی. درباره‌ی این خط چیزی نگو.';
        }

        return implode("\n", $l);
    }

    public static function reply($content, array $ctx, array $history = [], array $opt = []): array
    {
        $withReaction = $opt['reaction'] ?? true;
        $messages     = [['role' => 'system', 'content' => self::systemPrompt($ctx, $withReaction)]];
        foreach ($history as $m) {
            if (isset($m['role'], $m['content']) && $m['content'] !== '') {
                $messages[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $content];

        $deadline = $opt['deadline'] ?? (microtime(true) + 60);
        $text     = self::chat($messages, [
            'tier'        => $opt['tier'] ?? 'smart',
            'temperature' => 0.7,
            'max_tokens'  => $opt['max_tokens'] ?? 2000,
            'deadline'    => $deadline,
            'section'     => 'chat',
            'stream'      => $opt['on_text'] ?? null,
        ]);
        if ($text === null && ($opt['tier'] ?? 'smart') === 'smart' && self::hasTiers() && $deadline - microtime(true) > 5) {
            logWarn('ai', 'smart chain failed, retrying on lite');
            $text = self::chat($messages, ['tier' => 'lite', 'temperature' => 0.7, 'max_tokens' => $opt['max_tokens'] ?? 2000, 'deadline' => $deadline, 'thinking' => null, 'section' => 'chat', 'stream' => $opt['on_text'] ?? null]);
        }

        $out = ['answer' => null, 'reaction' => null, 'image' => null, 'action' => null];
        if ($text === null) {
            return $out;
        }

        if (self::isGroqRoute()) {
            [$out['reaction'], $text] = self::splitReaction($text, $withReaction);
        } else {
            $parts = preg_split('/\r?\n/', $text, 2);
            if (preg_match('/^\s*reaction\s*:?\s*(.*)$/iu', $parts[0] ?? '', $m)) {
                $out['reaction'] = self::matchReaction($m[1]);
                $text            = ltrim($parts[1] ?? '');
            }
        }

        if (($ctx['chat_type'] ?? '') !== 'inline' && preg_match('/^\s*IMAGE\s*:\s*(.+)$/isu', $text, $im)) {
            $out['image'] = mb_substr(mbTrim($im[1], " \t\n\"'«»"), 0, 700);
            return $out;
        }

        if (!in_array($ctx['chat_type'] ?? '', ['inline', 'miniapp'], true) && empty($ctx['no_action']) && ($act = Intent::parse($text)) !== null) {
            $out['action'] = $act;
            return $out;
        }

        $text          = self::clean($text);
        $out['answer'] = $text !== '' ? $text : null;
        return $out;
    }

    private const REACTION_ALIAS = [
        '😂' => '🤣', '😆' => '😁', '😄' => '😁', '😃' => '😁', '😀' => '😁', '😅' => '😁', '🙂' => '😁', '😊' => '🥰', '☺' => '🥰',
        '😉' => '😘', '🥳' => '🎉', '🙌' => '👏', '💪' => '🔥', '✅' => '👌', '👋' => '🤝', '💖' => '💘', '💕' => '💘', '💗' => '💘',
        '💓' => '💘', '😔' => '😢', '😞' => '😢', '😥' => '😢', '🥹' => '😢', '😠' => '😡', '😤' => '😡', '😬' => '😨', '🤭' => '🙈',
        '💙' => '❤', '💜' => '❤', '🖤' => '❤', '💛' => '❤', '💚' => '❤', '🧡' => '❤', '🤍' => '❤',
    ];

    private static function matchReaction(string $candidate, bool $loose = false): ?string
    {
        $c = str_replace("\u{FE0F}", '', mbTrim($candidate, " \t\"'()[]«».,!،" . ($loose ? '*_' : '')));
        if ($loose) {
            $c = preg_replace('/[\x{1F3FB}-\x{1F3FF}]/u', '', $c) ?? $c;
        }
        foreach (self::REACTIONS as $e) {
            if ($c === str_replace("\u{FE0F}", '', $e)) {
                return $e;
            }
        }
        foreach (self::REACTIONS as $e) {
            $plain = str_replace("\u{FE0F}", '', $e);
            if ($plain !== '' && mb_strpos($c, $plain) !== false) {
                return $e;
            }
        }
        if ($loose && $c !== '') {
            foreach (self::REACTION_ALIAS as $from => $to) {
                if (mb_strpos($c, (string) $from) !== false) {
                    return $to;
                }
            }
        }
        return null;
    }

    public static function isGroqRoute(): bool
    {
        return str_starts_with(self::route(), 'groq');
    }

    public static function splitReaction(string $text, bool $bare): array
    {
        $text  = ltrim($text);
        $parts = preg_split('/\r?\n/', $text, 2) ?: [$text];
        $first = trim((string) preg_replace('/[*_`\[\]()<>"«»]/u', '', $parts[0] ?? ''));
        $rest  = ltrim($parts[1] ?? '');

        if (preg_match('/^reaction\b\s*([:：=\-–]?)\s*(\S*)\s*(.*)$/iu', $first, $m) && ($m[1] !== '' || !preg_match('/[\p{L}\p{N}]/u', $m[2]))) {
            return [self::matchReaction($m[2], true), trim($m[3] . "\n" . $rest)];
        }
        if ($bare && $rest !== '' && $first !== '' && mb_strlen($first) <= 14 && !preg_match('/[\p{L}\p{N}]/u', $first)) {
            $e = self::matchReaction($first, true);
            if ($e !== null) {
                return [$e, $rest];
            }
        }
        return [null, $text];
    }

    public static function clean(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/su', '$1', $text) ?? $text;
        $text = preg_replace('/(?<![\*\w])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\*\w])/u', '$1', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{FEFF}\x{2060}]/u', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3990) . '…';
        }
        return $text;
    }

    public static function toHtml(string $text): string
    {
        $parts = preg_split('/```[a-zA-Z0-9_+\-]*\n?(.*?)```/su', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return h($text);
        }
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? '<pre>' . h(rtrim($part)) . '</pre>' : h($part);
        }
        return preg_replace('/`([^`\n]{1,200})`/u', '<code>$1</code>', $out) ?? $out;
    }

    public static function translate(string $text, string $target = 'auto', ?float $deadline = null): ?string
    {
        $rule = $target === 'auto'
            ? 'If the text is Persian, translate it to natural English; otherwise translate it to natural, fluent Persian.'
            : "Translate the text to {$target}.";
        $deadline ??= microtime(true) + 40;
        $msgs = [
            ['role' => 'system', 'content' => "You are a professional translator. {$rule} Output ONLY the translation, no notes, no quotes."],
            ['role' => 'user', 'content' => $text],
        ];
        $out = self::chat($msgs, ['tier' => 'lite', 'temperature' => 0.2, 'max_tokens' => 1500, 'deadline' => min($deadline, microtime(true) + 18), 'thinking' => null, 'section' => 'translate']);
        if ($out === null || trim($out) === '') {
            $out = self::googleTranslate($text, $target);
        }
        if (($out === null || trim($out) === '') && self::hasTiers() && $deadline - microtime(true) > 8) {
            $out = self::chat($msgs, ['tier' => 'smart', 'temperature' => 0.2, 'max_tokens' => 1500, 'deadline' => $deadline, 'section' => 'translate']);
        }
        return $out !== null && trim($out) !== '' ? $out : null;
    }

    public static function translateFast(string $text, string $target = 'auto'): ?string
    {
        return self::googleTranslate($text, $target) ?? self::translate($text, $target);
    }

    public static function googleTranslate(string $text, string $target = 'auto'): ?string
    {
        $codes = ['English' => 'en', 'Persian' => 'fa', 'Arabic' => 'ar', 'Turkish' => 'tr', 'German' => 'de', 'French' => 'fr', 'Russian' => 'ru',
            'Spanish' => 'es', 'Chinese' => 'zh-CN', 'Japanese' => 'ja', 'Korean' => 'ko', 'Italian' => 'it'];
        $tl = $codes[$target] ?? (preg_match('/[\x{0600}-\x{06FF}]/u', $text) && !preg_match('/[\x{0629}\x{0643}\x{064A}]/u', $text) ? 'en' : 'fa');
        $r  = Http::request('POST', 'https://translate.googleapis.com/translate_a/single?' . http_build_query(['client' => 'gtx', 'sl' => 'auto', 'tl' => $tl, 'dt' => 't']), [
            'body'    => http_build_query(['q' => $text]),
            'headers' => ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'],
            'timeout' => 12,
        ]);
        $j = $r['body'] !== null && $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($j) || !is_array($j[0] ?? null)) {
            logWarn('ai', 'google translate http ' . $r['code']);
            return null;
        }
        $out = '';
        foreach ($j[0] as $seg) {
            $out .= is_array($seg) && is_string($seg[0] ?? null) ? $seg[0] : '';
        }
        return trim($out) !== '' ? trim($out) : null;
    }

    public static function imagePrompt(string $request): ?array
    {
        $text = self::chat([
            ['role' => 'system', 'content' => implode("\n", [
                "You turn a user's image request (Persian or English) into a prompt for an AI image generator.",
                'Reply with EXACTLY two lines:',
                'PROMPT: <detailed English prompt: subject, setting, style, lighting, composition; max 70 words; keep names/on-image text as written>',
                'CAPTION: <one short, friendly Persian sentence (max 12 words), at most one emoji, no markdown>',
                'If no subject is given, invent a striking creative one. Never ask questions.',
                'If the request is sexually explicit or extreme gore, reply exactly: REFUSE',
            ])],
            ['role' => 'user', 'content' => $request],
        ], ['tier' => 'lite', 'temperature' => 0.5, 'max_tokens' => 600, 'thinking' => null, 'section' => 'image']);

        if ($text === null) {
            return null;
        }
        if (preg_match('/^\s*REFUSE\b/i', $text)) {
            return ['status' => 'refuse'];
        }
        if (!preg_match('/PROMPT\s*:\s*(.+)/iu', $text, $pm)) {
            return null;
        }
        $caption = preg_match('/CAPTION\s*:\s*(.+)/iu', $text, $cm) ? self::clean(mbTrim($cm[1], " \t\"'«»")) : null;
        return [
            'status'  => 'ok',
            'prompt'  => mb_substr(mbTrim($pm[1], " \t\"'«»"), 0, 700),
            'caption' => $caption ? mb_substr($caption, 0, 200) : null,
        ];
    }

    public static function transcribe(string $bytes, string $mime = 'audio/ogg'): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $route = self::route();
        if ($route !== 'gemini' && !str_starts_with($route, 'gemini:')) {
            return FreeAI::transcribe(explode(':', $route, 2)[0], $bytes, $mime);
        }
        return self::chat([['role' => 'user', 'content' => [
            ['type' => 'media', 'mime' => $mime, 'data' => base64_encode($bytes)],
            ['type' => 'text', 'text' => 'Transcribe this audio exactly, in its original language. Output only the transcript.'],
        ]]], ['tier' => 'lite', 'temperature' => 0, 'max_tokens' => 2000, 'thinking' => null, 'section' => 'voice']);
    }
}
