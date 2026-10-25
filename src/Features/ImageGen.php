<?php

final class ImageGen
{
    private const ENDPOINTS = [
        'https://image.pollinations.ai/prompt/',
        'https://gen.pollinations.ai/image/',
    ];

    public static function url(string $prompt, int $seed): string
    {
        $size = (int) App::cfg('image_size', 1024);
        return self::ENDPOINTS[0] . rawurlencode($prompt) . '?' . http_build_query([
            'width'  => $size,
            'height' => $size,
            'seed'   => $seed,
            'nologo' => 'true',
            'model'  => App::cfg('image_model', 'flux'),
        ]);
    }

    public static function warmUrl(string $prompt, callable $tick): ?string
    {
        $url = self::url($prompt, random_int(1, 2000000));
        return self::fetch($url, '', $tick) !== null ? $url : null;
    }

    public static function fileId(string $bytes): ?string
    {
        $chat = (string) App::cfg('storage_chat', '');
        $chat = $chat !== '' ? $chat : (string) (App::cfg('admins', [])[0] ?? '');
        return $chat === '' ? null : App::tg()->photoFileId($chat, $bytes);
    }

    private static function topTools(string $prompt, callable $tick): ?string
    {
        $keys = FreeAI::keys('toptools');
        if (!$keys) {
            return null;
        }
        $key = $keys[array_rand($keys)];
        $tick();
        $r = Http::postJson(
            FreeAI::PROVIDERS['toptools']['url'] . '/chat/completions',
            [
                'model'    => App::cfg('image_toptools_model', 'top-tools-ai'),
                'messages' => [['role' => 'user', 'content' => 'Generate an image: ' . $prompt]],
            ],
            ['Authorization: Bearer ' . $key],
            120
        );
        $text = (string) ($r['data']['choices'][0]['message']['content'] ?? '');
        if ($r['code'] !== 200 || !preg_match('~https?://[^\s)\]>"\']+~u', $text, $m)) {
            logWarn('image', "toptools failed (HTTP {$r['code']}): " . mb_substr((string) ($r['data']['error']['message'] ?? $text ?: $r['raw']), 0, 160));
            return null;
        }
        return self::fetch($m[0], '', $tick);
    }

    public static function generate(string $prompt, callable $tick): ?string
    {
        $bytes = self::topTools($prompt, $tick);
        if ($bytes !== null) {
            return $bytes;
        }
        $size  = (int) App::cfg('image_size', 1024);
        $key   = trim((string) App::cfg('pollinations_api_key', ''));
        $query = http_build_query([
            'width'  => $size,
            'height' => $size,
            'seed'   => random_int(1, 2000000),
            'nologo' => 'true',
            'model'  => App::cfg('image_model', 'flux'),
        ]);

        foreach (self::ENDPOINTS as $i => $base) {
            if ($i === 1 && $key === '') {
                continue;
            }
            $bytes = self::fetch($base . rawurlencode($prompt) . '?' . $query, $i === 1 ? $key : '', $tick);
            if ($bytes !== null) {
                return $bytes;
            }
        }
        return null;
    }

    private static function fetch(string $url, string $key, callable $tick): ?string
    {
        $headers = ['Accept: image/*'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 NulliniBot/3.0',
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $last = microtime(true);
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 1.0) === -1) {
                usleep(100000);
            }
            if (microtime(true) - $last >= 4) {
                $tick();
                $last = microtime(true);
            }
        } while ($running && $status === CURLM_OK);

        $raw  = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);

        if ($code !== 200 || !is_string($raw) || $raw === '' || @getimagesizefromstring($raw) === false) {
            logWarn('image', "generation failed (HTTP {$code})");
            return null;
        }
        return $raw;
    }

    private static function status(array $msg): ?int
    {
        $r = App::tg()->reply($msg, LiveReply::DRAWING);
        return isset($r['result']['message_id']) ? (int) $r['result']['message_id'] : null;
    }

    private static function clear(int|string $chatId, ?LiveReply $live, ?int $statusId): void
    {
        if ($live?->active()) {
            $live->discard();
        }
        if ($statusId !== null) {
            App::tg()->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $statusId], 8);
        }
    }

    public static function handle(array $msg, string $request): void
    {
        $tg       = App::tg();
        $chatId   = $msg['chat']['id'];
        $tg->action($chatId, 'upload_photo');
        Users::hit('image');
        $statusId = self::status($msg);

        $en = AI::googleTranslate($request, 'English');
        if ($en !== null) {
            self::draw($msg, $en, null, null, $statusId);
            return;
        }
        $plan = AI::imagePrompt($request);
        if ($plan === null) {
            self::clear($chatId, null, $statusId);
            $tg->reply($msg, '😕 الان نتونستم درخواستت رو بفهمم؛' . "\n" . 'یه‌کم دیگه دوباره بگو.');
            return;
        }
        if ($plan['status'] === 'refuse') {
            self::clear($chatId, null, $statusId);
            $tg->reply($msg, '🙅 این یکی رو نمی‌کشم؛' . "\n" . 'یه چیز دیگه بگو 😅');
            return;
        }

        self::draw($msg, $plan['prompt'], $plan['caption'] ?? null, null, $statusId);
    }

    public static function draw(array $msg, string $prompt, ?string $caption = null, ?LiveReply $live = null, ?int $statusId = null): bool
    {
        $tg     = App::tg();
        $chatId = $msg['chat']['id'];
        $tg->action($chatId, 'upload_photo');
        if ($statusId === null && !$live?->active()) {
            $statusId = self::status($msg);
        }
        $bytes = self::generate($prompt, static fn() => $tg->action($chatId, 'upload_photo'));
        if ($bytes === null) {
            self::clear($chatId, $live, $statusId);
            $tg->reply($msg, "😕 سرویس ساخت عکس الان جواب نمی‌ده؛\nچند دقیقه دیگه امتحان کن.");
            return false;
        }
        $captions = ['🎨 بفرما، اینم عکست!', '🖼 اینم از این، امیدوارم خوشت بیاد!', '✨ تازه از زیر قلمم دراومده!'];
        $cap      = h($caption ?? $captions[array_rand($captions)]);
        $sent = $tg->sendPhotoBytes($chatId, $bytes, $cap, $msg['message_id'] ?? null);
        self::clear($chatId, $live, $statusId);
        if (!$sent) {
            $tg->reply($msg, '😕 عکس ساخته شد ولی ارسالش به مشکل خورد؛' . "\n" . 'دوباره امتحان کن.');
            return false;
        }
        return true;
    }
}
