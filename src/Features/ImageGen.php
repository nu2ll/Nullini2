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

    public static function generate(string $prompt, callable $tick): ?string
    {
        [$prov, $model] = explode(':', AI::route(), 2) + [1 => ''];
        if (FreeAI::imageEnabled($prov)) {
            $bytes = self::viaGateway($prov, $model, $prompt, $tick);
            if ($bytes !== null) {
                return $bytes;
            }
            logWarn('image', "{$prov}: gateway image failed, falling back to pollinations");
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

    private static function viaGateway(string $prov, string $model, string $prompt, callable $tick): ?string
    {
        $payload = ['prompt' => $prompt, 'n' => 1, 'response_format' => 'b64_json'];
        if ($model !== '') {
            $payload['model'] = $model;
        }
        $raw = self::fetch(FreeAI::base($prov) . '/images/generations', (string) (FreeAI::keys($prov)[0] ?? ''), $tick, $payload);
        $j   = $raw !== null ? json_decode($raw, true) : null;
        $b64 = is_array($j) ? ($j['data'][0]['b64_json'] ?? null) : null;
        $bin = is_string($b64) ? base64_decode($b64, true) : false;
        if ($bin === false || $bin === '' || @getimagesizefromstring($bin) === false) {
            return null;
        }
        return $bin;
    }

    private static function fetch(string $url, string $key, callable $tick, ?array $json = null): ?string
    {
        $headers = $json === null ? ['Accept: image/*'] : ['Accept: application/json', 'Content-Type: application/json'];
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
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }

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

        if ($code !== 200 || !is_string($raw) || $raw === '' || ($json === null && @getimagesizefromstring($raw) === false)) {
            logWarn('image', "generation failed (HTTP {$code})");
            return null;
        }
        return $raw;
    }

    public static function handle(array $msg, string $request): void
    {
        $tg     = App::tg();
        $chatId = $msg['chat']['id'];
        $tg->action($chatId, 'upload_photo');
        Users::hit('image');

        $en = AI::googleTranslate($request, 'English');
        if ($en !== null) {
            self::draw($msg, $en);
            return;
        }
        $plan = AI::imagePrompt($request);
        if ($plan === null) {
            $tg->reply($msg, '😕 الان نتونستم درخواستت رو بفهمم؛' . "\n" . 'یه‌کم دیگه دوباره بگو.');
            return;
        }
        if ($plan['status'] === 'refuse') {
            $tg->reply($msg, '🙅 این یکی رو نمی‌کشم؛' . "\n" . 'یه چیز دیگه بگو 😅');
            return;
        }

        self::draw($msg, $plan['prompt'], $plan['caption'] ?? null);
    }

    public static function draw(array $msg, string $prompt, ?string $caption = null): bool
    {
        $tg     = App::tg();
        $chatId = $msg['chat']['id'];
        $tg->action($chatId, 'upload_photo');
        $bytes = self::generate($prompt, static fn() => $tg->action($chatId, 'upload_photo'));
        if ($bytes === null) {
            $tg->reply($msg, "😕 سرویس ساخت عکس الان جواب نمی‌ده؛\nچند دقیقه دیگه امتحان کن.");
            return false;
        }
        $captions = ['🎨 بفرما، اینم عکست!', '🖼 اینم از این، امیدوارم خوشت بیاد!', '✨ تازه از زیر قلمم دراومده!'];
        $cap      = h($caption ?? $captions[array_rand($captions)]);
        if (!$tg->sendPhotoBytes($chatId, $bytes, $cap, $msg['message_id'] ?? null)) {
            $tg->reply($msg, '😕 عکس ساخته شد ولی ارسالش به مشکل خورد؛' . "\n" . 'دوباره امتحان کن.');
            return false;
        }
        return true;
    }
}
