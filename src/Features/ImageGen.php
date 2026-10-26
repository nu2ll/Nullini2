<?php

final class ImageGen
{
    private static ?string $notice  = null;
    private static ?string $failMsg  = null;
    private static bool    $quotaOut = false;

    public static function notice(): ?string
    {
        return self::$notice;
    }

    public static function failText(): string
    {
        if (self::$failMsg !== null) {
            return self::$failMsg;
        }
        if (self::$notice !== null) {
            return '😕 ' . h(mb_substr(self::$notice, 0, 400));
        }
        if (self::$quotaOut) {
            return "😕 سهمیه‌ی ساخت عکس برای امروز تموم شده؛\nفردا دوباره امتحان کن.";
        }
        return "😕 سرویس ساخت عکس الان جواب نمی‌ده؛\nچند دقیقه دیگه امتحان کن.";
    }

    public static function fileId(string $bytes): ?string
    {
        $chat = (string) App::cfg('storage_chat', '');
        $chat = $chat !== '' ? $chat : (string) (App::cfg('admins', [])[0] ?? '');
        return $chat === '' ? null : App::tg()->photoFileId($chat, $bytes);
    }

    private static function cloudflare(string $prompt, callable $tick): ?string
    {
        $account = trim((string) App::cfg('cloudflare_account_id', ''));
        $token   = trim((string) App::cfg('cloudflare_api_token', ''));
        if ($account === '' || $token === '') {
            return null;
        }
        $tick();
        $model = (string) App::cfg('cloudflare_image_model', '@cf/black-forest-labs/flux-1-schnell');
        $steps = max(1, min(8, (int) App::cfg('cloudflare_image_steps', 4)));
        $r = Http::postJson(
            "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/{$model}",
            ['prompt' => mb_substr($prompt, 0, 2000), 'steps' => $steps],
            ['Authorization: Bearer ' . $token],
            90
        );
        $b64 = (string) ($r['data']['result']['image'] ?? '');
        if ($r['code'] === 200 && $b64 !== '') {
            $bytes = base64_decode($b64, true);
            if (is_string($bytes) && $bytes !== '' && @getimagesizefromstring($bytes) !== false) {
                return $bytes;
            }
        }
        $err  = (string) ($r['data']['errors'][0]['message'] ?? '');
        $ecode = (int) ($r['data']['errors'][0]['code'] ?? 0);
        logWarn('image', "cloudflare failed (HTTP {$r['code']}, code {$ecode}): " . mb_substr($err !== '' ? $err : (string) $r['raw'], 0, 160));
        if ($ecode === 3036 || ($r['code'] === 429 && stripos($err, 'allocation') !== false)) {
            self::$quotaOut = true;
        } elseif ($ecode === 3030 || preg_match('/nsfw|safety|inappropriate|moderat/i', $err)) {
            self::$notice  = $err;
            self::$failMsg = "😕 سرویس عکس به این درخواست گیر داد؛\nیه‌کم جمله‌ت رو عوض کن و دوباره بنویس.";
        }
        return null;
    }

    public static function generate(string $prompt, callable $tick): ?string
    {
        self::$notice   = null;
        self::$failMsg  = null;
        self::$quotaOut = false;
        return self::cloudflare($prompt, $tick);
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
            $tg->reply($msg, self::failText());
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
