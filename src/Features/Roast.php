<?php

final class Roast
{
    private const WAIT = 60;

    public static function see(array $from): void
    {
        $u = strtolower((string) ($from['username'] ?? ''));
        $id = (int) ($from['id'] ?? 0);
        if ($u === '' || $id <= 0 || !empty($from['is_bot'])) {
            return;
        }
        if ((int) Store::get('uname', $u, 0) !== $id) {
            Store::set('uname', $u, $id);
        }
    }

    public static function token(string $text): string
    {
        if (preg_match('/@([A-Za-z][A-Za-z0-9_]{3,31})\b/', $text, $m) && strcasecmp($m[1], App::botUsername()) !== 0) {
            return '@' . $m[1];
        }
        if (preg_match('/\b(\d{5,15})\b/', en($text), $m)) {
            return $m[1];
        }
        return preg_match('/^(me|self)$/i', trim($text)) ? 'me' : '';
    }

    public static function target(array $msg, string $arg): ?array
    {
        $tok   = self::token($arg);
        $reply = $msg['reply_to_message'] ?? null;
        $quote = '';
        if ($tok !== '' && $tok !== 'me') {
            $u = self::lookup($tok);
            return $u === null ? null : $u + ['quote' => ''];
        }
        if ($tok === '' && is_array($reply) && self::ours($reply)) {
            $rt = trim((string) ($reply['text'] ?? $reply['caption'] ?? ''));
            if (str_starts_with($rt, '🔥 روست') || !empty($reply['from']['is_bot'])) {
                return ['skip' => true];
            }
            return self::user($reply['from'] ?? []) + ['quote' => ''];
        }
        if ($tok === '' && is_array($reply) && !empty($reply['from']['id']) && empty($reply['sender_chat'])) {
            $quote = trim((string) ($reply['text'] ?? $reply['caption'] ?? ''));
            return self::user($reply['from']) + ['quote' => mb_substr($quote, 0, 600)];
        }
        return self::user($msg['from'] ?? []) + ['quote' => ''];
    }

    private static function ours(array $m): bool
    {
        $bot = App::botUsername();
        return $bot !== '' && (strcasecmp((string) ($m['via_bot']['username'] ?? ''), $bot) === 0
            || (!empty($m['from']['is_bot']) && strcasecmp((string) ($m['from']['username'] ?? ''), $bot) === 0));
    }

    private static function user(array $from): array
    {
        return [
            'id'       => (int) ($from['id'] ?? 0),
            'name'     => userName($from),
            'username' => (string) ($from['username'] ?? ''),
            'premium'  => !empty($from['is_premium']),
            'bot'      => !empty($from['is_bot']),
            'lang'     => (string) ($from['language_code'] ?? ''),
        ];
    }

    private static function lookup(string $tok): ?array
    {
        $id = null;
        if ($tok[0] === '@') {
            $id = (int) Store::get('uname', strtolower(substr($tok, 1)), 0);
            if ($id <= 0) {
                $row = Db::run('SELECT id FROM users WHERE LOWER(username) = ? LIMIT 1', [strtolower(substr($tok, 1))])->fetch();
                $id  = $row ? (int) $row['id'] : 0;
            }
            if ($id <= 0) {
                return ['id' => 0, 'name' => substr($tok, 1), 'username' => substr($tok, 1), 'premium' => false, 'bot' => false];
            }
        } else {
            $id = (int) $tok;
        }
        $c = self::chat($id);
        if ($c === null) {
            return $tok[0] === '@' ? ['id' => 0, 'name' => substr($tok, 1), 'username' => substr($tok, 1), 'premium' => false, 'bot' => false] : null;
        }
        return [
            'id'       => $id,
            'name'     => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: (string) $id,
            'username' => (string) ($c['username'] ?? ''),
            'premium'  => false,
            'bot'      => false,
        ];
    }

    private static function chat(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Store::remember('cache', 'rchat_' . $id, 1800, static function () use ($id) {
            $r = App::tg()->call('getChat', ['chat_id' => $id], 8);
            return !empty($r['ok']) ? $r['result'] : null;
        });
    }

    private static function photo(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $tg = App::tg();
        $r  = $tg->call('getUserProfilePhotos', ['user_id' => $id, 'limit' => 1], 8);
        $sz = $r['result']['photos'][0] ?? [];
        if (!$sz) {
            return null;
        }
        $pick = $sz[min(2, count($sz) - 1)] ?? end($sz);
        $b    = $tg->download((string) ($pick['file_id'] ?? ''));
        $info = $b ? @getimagesizefromstring($b) : false;
        return $info ? ['type' => 'media', 'mime' => $info['mime'] ?? 'image/jpeg', 'data' => base64_encode($b)] : null;
    }

    public static function busy(int $uid): bool
    {
        if ($uid <= 0 || App::isAdmin($uid)) {
            return false;
        }
        return Store::update('rl', 'roast_' . $uid, static fn($at) => (is_int($at) && time() - $at < self::WAIT) ? null : time()) === null;
    }

    public static function text(array $t): string
    {
        $id    = (int) $t['id'];
        $c     = $id > 0 ? self::chat($id) : null;
        $p     = $id > 0 ? UserInfo::probe($id) : ['photos' => null, 'dc' => null, 'level' => null, 'stars' => null];
        $pic   = self::photo($id);
        $age   = $id > 0 ? UserInfo::createdAt($id) : null;
        $bio   = trim((string) ($c['bio'] ?? ''));
        $birth = $c['birthdate'] ?? null;
        $names = array_values(array_diff((array) ($c['active_usernames'] ?? []), [$t['username']]));
        $msg   = trim((string) ($t['quote'] ?? ''));

        $l   = [];
        $l[] = 'این کاربر تلگرام رو روست کن؛ بی‌فیلتر، بی‌رحم، به فارسی.';
        $l[] = '';
        $l[] = 'اسم: ' . $t['name'];
        $l[] = 'یوزرنیم: ' . ($t['username'] !== '' ? '@' . $t['username'] : 'نداره');
        if ($names) {
            $l[] = 'یوزرنیم‌های دیگه: @' . implode('، @', $names);
        }
        $l[] = 'بیو: ' . ($bio !== '' ? $bio : ($c !== null ? 'خالی' : 'معلوم نیست'));
        if ($age) {
            $l[] = 'عمر اکانت: حدود ' . UserInfo::ageText($age) . ' (ساخت حدود ' . Jalali::numericDate($age) . ')';
        }
        if (is_array($birth) && !empty($birth['day'])) {
            $l[] = 'تولد: ' . (int) $birth['day'] . '/' . (int) $birth['month'] . (!empty($birth['year']) ? '/' . (int) $birth['year'] : '');
        }
        if (!empty($c['personal_chat']['title'])) {
            $l[] = 'کانال شخصی تو پروفایلش: ' . $c['personal_chat']['title'];
        }
        if ($p['photos'] !== null) {
            $l[] = 'تعداد عکس پروفایل: ' . (int) $p['photos'];
        }
        if (!empty($t['premium'])) {
            $l[] = 'تلگرام پریمیوم داره';
        }
        if (!empty($p['stars'])) {
            $l[] = 'برای پیام دادن بهش باید ' . (int) $p['stars'] . ' استار پول داد';
        }
        if ($p['level'] !== null) {
            $l[] = 'سطح اکانت: ' . (int) $p['level'];
        }
        if (!empty($p['dc'])) {
            $l[] = 'دیتاسنتر: DC' . (int) $p['dc'];
        }
        if (($t['lang'] ?? '') !== '') {
            $l[] = 'زبان تلگرامش: ' . $t['lang'];
        }
        if (!empty($t['bot'])) {
            $l[] = 'این یه رباته';
        }
        $l[] = $pic ? 'این عکس، عکس پروفایل تلگرامشه.' : 'عکس پروفایل نداره یا مخفیش کرده.';
        if ($msg !== '') {
            $l[] = 'پیامش:';
            $l[] = $msg;
        }
        $prompt = implode("\n", $l);

        $sys = 'تو «نالینی» هستی. فقط متن روست رو بنویس، بدون مقدمه و بدون ** یا #.';
        $out = AI::chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => array_values(array_filter([$pic, ['type' => 'text', 'text' => $prompt]]))]],
            ['tier' => 'smart', 'temperature' => 1.0, 'max_tokens' => 2000, 'deadline' => microtime(true) + 60, 'section' => 'roast']);
        if ($out === null) {
            $out = AI::chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => str_replace('این عکس، عکس پروفایل تلگرامشه.', 'عکس پروفایل داره.', $prompt)]],
                ['tier' => 'lite', 'temperature' => 1.0, 'max_tokens' => 2000, 'thinking' => null, 'section' => 'roast']);
        }
        if ($out === null) {
            return AI::FAIL_TEXT;
        }
        Users::hit('roast');
        $who  = userTag((int) $t['id'], mb_substr((string) $t['name'], 0, 40), (string) ($t['username'] ?? '') ?: null);
        $body = AI::clean($out);
        if (mb_strlen($body) > 3800) {
            $body = mb_substr($body, 0, 3800) . '…';
        }
        return '🔥 <b>روست ' . $who . "</b>\n\n" . h($body);
    }

    public static function pending(array $from, string $tok): array
    {
        $token = bin2hex(random_bytes(6));
        Store::set('roast', $token, ['from' => $from, 'tok' => $tok]);
        $r   = $tok === '' ? null : UserInfo::resolve($tok);
        $who = $r === null ? bidi('خودت') : userTag($r['id'], $r['name'], $r['username']);
        return [
            'token' => $token,
            'title' => $tok === '' ? '🔥 خودمو روست کن' : '🔥 روست ' . ($r['id'] ? $r['name'] : $tok),
            'text'  => '🔥 <b>روست ' . $who . "</b>\n\n⏳ دارم سوژه رو آنالیز می‌کنم...",
            'kb'    => ikb([[btn('⏳ صبر کن...', 'noop', null)]]),
        ];
    }

    public static function resolve(string $token, string $imid): void
    {
        $p = Store::get('roast', $token);
        if (!is_array($p)) {
            return;
        }
        Store::delete('roast', $token);
        $t = $p['tok'] === '' ? self::user((array) $p['from']) + ['quote' => ''] : self::lookup((string) $p['tok']);
        App::tg()->editInline($imid, $t === null ? '🤷 این آدم رو پیدا نکردم؛' . "\nآیدی عددی یا یوزرنیمش رو درست بنویس." : self::text($t + ['quote' => '']));
    }
}
