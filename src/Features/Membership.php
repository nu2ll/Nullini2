<?php

final class Membership
{
    private const CACHE_OK = 600;

    public static function missing(int $userId): array
    {
        $channels = (array) App::cfg('required_channels', []);
        if (!$channels || $userId <= 0) {
            return [];
        }
        $cached = Store::get('join', (string) $userId);
        if (is_array($cached) && (time() - (int) ($cached['at'] ?? 0)) < self::CACHE_OK) {
            return [];
        }

        $missing = [];
        foreach ($channels as $ch) {
            $r      = App::tg()->call('getChatMember', ['chat_id' => $ch['chat_id'], 'user_id' => $userId], 8);
            $status = $r['result']['status'] ?? '';
            if (!in_array($status, ['member', 'administrator', 'creator', 'restricted'], true)) {
                $missing[] = $ch;
            }
        }
        if (!$missing) {
            Store::set('join', (string) $userId, ['at' => time()]);
        }
        return $missing;
    }

    public static function keyboard(array $missing): array
    {
        $rows = [];
        foreach ($missing as $ch) {
            $rows[] = [urlBtn('📢 ' . $ch['title'], $ch['url'], 'danger')];
        }
        $rows[] = [btn('✅ عضو شدم', 'joincheck', 'success')];
        return ikb($rows);
    }

    public static function text(): string
    {
        return "🔒 <b>یه قدم تا شروع!</b>\n\nبرای استفاده از نالینی\nاول عضو کانال زیر شو، بعد «✅ عضو شدم» رو بزن 👇";
    }
}
