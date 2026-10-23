<?php

final class Tools
{
    private const SETS = [
        'U' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'L' => 'abcdefghijkmnopqrstuvwxyz',
        'D' => '23456789',
        'S' => '!@#$%^&*-_=+?',
    ];

    public static function password(int $length): string
    {
        $length = max(6, min($length, 64));
        $all    = implode('', self::SETS);
        $chars  = [];
        foreach (self::SETS as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    public static function passwordText(int $length): string
    {
        return "<b>🔑 پسورد ساز</b>\n\n<code>" . h(self::password($length)) . "</code>\n\n"
            . '📏 طول: ' . fa($length) . ' · 💪 قدرت: خیلی قوی' . "\n<i>روی پسورد بزن تا کپی بشه</i>";
    }

    public static function passwordKeyboard(): array
    {
        $row = [];
        foreach ([8, 12, 16, 24, 32] as $n) {
            $row[] = btn(fa($n), "pass:{$n}");
        }
        return ikb([$row]);
    }

    public static function coinKeyboard(): array
    {
        return ikb([[btn('🪙 بنداز!', 'coin:flip', 'success')]]);
    }

    public static function coinResult(): string
    {
        $r = random_int(0, 1) ? '🦁 <b>شیر</b>' : '➖ <b>خط</b>';
        return "<b>🪙 سکه</b>\n\nسکه چرخید و چرخید و... {$r} اومد!";
    }

    public static function hafezUrl(): string
    {
        return 'https://api.codebazan.ir/fal/hafez/' . random_int(1, 149) . '.gif';
    }

    public static function hafezCaption(): string
    {
        return '🔮 <b>فال حافظ شما</b>' . "\n<i>نیت کن، بعد بخون...</i>";
    }

    public static function hafezKeyboard(): array
    {
        return ikb([[btn('🔄 یه فال دیگه', 'fal:refresh')]]);
    }

    public static function showHafez(string $imid): ?array
    {
        return App::tg()->call('editMessageMedia', [
            'inline_message_id' => $imid,
            'media'             => ['type' => 'photo', 'media' => self::hafezUrl(), 'caption' => self::hafezCaption(), 'parse_mode' => 'HTML'],
            'reply_markup'      => self::hafezKeyboard(),
        ]);
    }

    public static function normalizeNid(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', en($raw)) ?? '';
        if ($digits === '' || strlen($digits) > 10 || strlen($digits) < 8) {
            return null;
        }
        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    public static function isValidNid(string $code): bool
    {
        if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $rem   = $sum % 11;
        $check = (int) $code[9];
        return $rem < 2 ? $check === $rem : $check === 11 - $rem;
    }

    public static function nidDisplay(string $code): string
    {
        return "\u{2066}" . substr($code, 0, 3) . '-' . substr($code, 3, 6) . '-' . $code[9] . "\u{2069}";
    }

    public static function nidText(string $code): string
    {
        $ok = self::isValidNid($code);
        return "<b>🪪 بررسی کد ملی</b>\n\n"
            . 'کد: <code>' . self::nidDisplay($code) . "</code>\n"
            . ($ok ? '✅ ساختار این کد <b>درسته</b>.' : '❌ ساختار این کد <b>اشتباهه</b>.') . "\n\n"
            . "<i>⚠️ فقط رقم کنترل بررسی شده،\nنه ثبت واقعی در ثبت‌احوال.</i>";
    }

    public static function qrUrl(string $text, int $size = 512): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'size' => "{$size}x{$size}", 'margin' => 12, 'format' => 'jpg', 'data' => $text,
        ]);
    }

    public static function dateText(): string
    {
        $now = time();
        return "<b>📅 تاریخ و ساعت</b> (به وقت ایران)\n\n"
            . '🗓 شمسی: ' . Jalali::fullDate($now) . "\n"
            . '🔢 عددی: ' . Jalali::numericDate($now) . "\n"
            . '🌍 میلادی: ' . date('l, j F Y', $now) . "\n"
            . '🕒 ساعت: <b>' . Jalali::time($now, true) . '</b>';
    }
}
