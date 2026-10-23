<?php

final class UserInfo
{
    private const POINTS = [
        [2768409, '2013-11-01'], [7679610, '2013-12-31'], [11538514, '2014-02-01'],
        [44634663, '2014-05-06'], [54845238, '2014-09-21'], [63263518, '2014-10-28'],
        [101260938, '2015-03-06'], [111220210, '2015-04-21'], [143445125, '2015-12-01'],
        [148670295, '2016-01-08'], [300000000, '2017-01-01'], [500000000, '2018-03-01'],
        [1000000000, '2020-08-01'], [2000000000, '2021-06-01'], [5182799035, '2022-10-15'],
        [6000000000, '2023-07-01'], [7000000000, '2024-06-01'], [8000000000, '2025-04-01'],
    ];

    public static function createdAt(int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }
        $ts  = static fn(string $d): int => (int) strtotime($d . ' 00:00:00 UTC');
        $pts = self::POINTS;
        if ($id <= $pts[0][0]) {
            return $ts($pts[0][1]);
        }
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            if ($id <= $pts[$i][0]) {
                [$a, $da] = $pts[$i - 1];
                [$b, $db] = $pts[$i];
                $t0 = $ts($da);
                return (int) round($t0 + ($ts($db) - $t0) * (($id - $a) / ($b - $a)));
            }
        }
        return min($ts($pts[count($pts) - 1][1]), time());
    }

    public static function ageText(int $created): string
    {
        $months = max(0, (int) floor((time() - $created) / (30.4375 * 86400)));
        $y = intdiv($months, 12);
        $m = $months % 12;
        if ($y && $m) {
            return fa("{$y} سال و {$m} ماه");
        }
        if ($y) {
            return fa("{$y} سال");
        }
        return $m ? fa("{$m} ماه") : 'کمتر از یک ماه';
    }

    public static function dcFromFileId(string $fileId): ?int
    {
        $bin = base64_decode(strtr($fileId, '-_', '+/') . str_repeat('=', (4 - strlen($fileId) % 4) % 4), true);
        if ($bin === false) {
            return null;
        }
        $out = '';
        for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
            if ($bin[$i] === "\x00" && $i + 1 < $n) {
                $out .= str_repeat("\x00", ord($bin[++$i]));
            } else {
                $out .= $bin[$i];
            }
        }
        if (strlen($out) < 8) {
            return null;
        }
        $dc = unpack('V', substr($out, 4, 4))[1] ?? 0;
        return ($dc >= 1 && $dc <= 5) ? (int) $dc : null;
    }

    public static function probe(int $id): array
    {
        return Store::remember('cache', 'probe_' . $id, 600, static fn() => self::probeLive($id));
    }

    private static function probeLive(int $id): array
    {
        $tg = App::tg();
        $r  = ['photos' => null, 'dc' => null, 'level' => null, 'stars' => null, 'bio' => null];

        $ph = $tg->call('getUserProfilePhotos', ['user_id' => $id, 'limit' => 1], 8);
        if (isset($ph['result']['total_count'])) {
            $r['photos'] = (int) $ph['result']['total_count'];
            if ($fid = $ph['result']['photos'][0][0]['file_id'] ?? null) {
                $r['dc'] = self::dcFromFileId($fid);
            }
        }

        $chat = $tg->call('getChat', ['chat_id' => $id], 8);
        if (!empty($chat['ok'])) {
            $c          = $chat['result'];
            $r['level'] = isset($c['rating']['level']) ? (int) $c['rating']['level'] : null;
            $r['stars'] = (int) ($c['paid_message_star_count'] ?? 0);
            $r['bio']   = $c['bio'] ?? null;
            if (!$r['photos'] && ($pid = $c['photo']['big_file_id'] ?? null)) {
                $r['photos'] = 1;
                $r['dc']   ??= self::dcFromFileId($pid);
            }
        }
        return $r;
    }

    public static function card(array $from, ?string $chatType): array
    {
        $id    = (int) $from['id'];
        $items = [['🆔 آیدی عددی', (string) $id]];

        if ($created = self::createdAt($id)) {
            $items[] = ['⏳ عمر اکانت', '≈ ' . self::ageText($created)];
            $items[] = ['🗓 ساخت حدودی', Jalali::numericDate($created)];
        }
        $items[] = ['👤 نام', userName($from)];
        if (!empty($from['username'])) {
            $items[] = ['🔗 یوزرنیم', '@' . $from['username']];
        }
        if (!empty($from['language_code'])) {
            $items[] = ['🗣 زبان', (string) $from['language_code']];
        }
        $items[] = ['💎 پریمیوم', !empty($from['is_premium']) ? 'بله ✨' : 'خیر'];

        $p = self::probe($id);
        if ($p['dc'] !== null) {
            $items[] = ['🌐 دیتاسنتر', 'DC' . $p['dc']];
        }
        if ($p['photos'] !== null) {
            $items[] = ['🖼 عکس پروفایل', $p['photos'] > 0 ? fa($p['photos']) . ' عکس' : 'نداره یا مخفیه'];
        }
        if ($p['stars']) {
            $items[] = ['💸 پیام پولی', fa($p['stars']) . ' ⭐'];
        }
        if ($p['level'] !== null) {
            $items[] = ['🏆 سطح اکانت', fa($p['level'])];
        }
        if ($chatType) {
            $types   = ['sender' => 'چت با خودت', 'private' => 'پیوی', 'group' => 'گروه', 'supergroup' => 'سوپرگروه', 'channel' => 'کانال'];
            $items[] = ['💬 این چت', $types[$chatType] ?? $chatType];
        }

        $rows = [];
        foreach ($items as [$label, $value]) {
            $short  = mb_strlen($value) > 26 ? mb_substr($value, 0, 25) . '…' : $value;
            $rows[] = [
                btn($label, 'noop', null),
                ['text' => $short, 'copy_text' => ['text' => mb_substr($value, 0, 256)], 'style' => 'primary'],
            ];
        }
        $text = "<b>👤 کارت اطلاعات " . safeName($from) . "</b>\n\nروی هر مقدار بزنی کپی می‌شه 👇";
        return [$text, ikb($rows)];
    }

    private static function local(string|int $chatId): ?array
    {
        try {
            if (is_int($chatId)) {
                return Db::row('SELECT id, name, username FROM users WHERE id = ?', [$chatId]);
            }
            $u   = strtolower(substr($chatId, 1));
            $uid = (int) Store::get('uname', $u, 0);
            $row = $uid > 0 ? Db::row('SELECT id, name, username FROM users WHERE id = ?', [$uid]) : null;
            $row ??= Db::row('SELECT id, name, username FROM users WHERE LOWER(username) = ? LIMIT 1', [$u]);
            return $row ?? ($uid > 0 ? ['id' => $uid, 'name' => '', 'username' => $u] : null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function resolve(string $token): array
    {
        $chatId = null;
        if (preg_match('/^@([A-Za-z0-9_]{3,32})$/', $token, $m)) {
            $chatId = '@' . $m[1];
        } elseif (preg_match('/^\d{5,15}$/', $token)) {
            $chatId = (int) $token;
        }
        if ($chatId === null) {
            return ['id' => null, 'username' => null, 'name' => $token];
        }

        $row    = self::local($chatId);
        $target = $row['id'] ?? $chatId;

        $info = Store::remember('cache', 'chat_' . mb_strtolower((string) $target), 3600, static function () use ($target) {
            $r = App::tg()->call('getChat', ['chat_id' => $target], 8);
            return !empty($r['ok']) ? $r['result'] : null;
        });

        if (is_array($info) && isset($info['id'])) {
            $name = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? ''));
            return [
                'id'       => (int) $info['id'],
                'username' => $info['username'] ?? ($row['username'] ?? null) ?: (is_string($chatId) ? substr($chatId, 1) : null),
                'name'     => $name !== '' ? $name : ($info['title'] ?? (string) $chatId),
            ];
        }
        if ($row !== null) {
            return [
                'id'       => (int) $row['id'],
                'username' => ($row['username'] ?? '') !== '' ? $row['username'] : (is_string($chatId) ? substr($chatId, 1) : null),
                'name'     => ($row['name'] ?? '') !== '' ? $row['name'] : (is_string($chatId) ? substr($chatId, 1) : (string) $chatId),
            ];
        }
        return [
            'id'       => is_int($chatId) ? $chatId : null,
            'username' => is_string($chatId) ? substr($chatId, 1) : null,
            'name'     => is_string($chatId) ? substr($chatId, 1) : (string) $chatId,
        ];
    }

    public static function funstat(int $id): ?array
    {
        $url = (string) App::cfg('funstat_url', '');
        if ($url === '' || $id <= 0) {
            return null;
        }
        return Store::remember('cache', 'fs_' . $id, 3600, static function () use ($url, $id) {
            $d = Http::getJson(rtrim($url, '/?') . '/?q=' . $id, [], 15);
            return (is_array($d) && isset($d['id']) && !isset($d['error'])) ? $d : null;
        });
    }

    private static function isoToJalali(?string $iso): string
    {
        $ts = $iso ? strtotime($iso) : false;
        return $ts ? Jalali::numericDate($ts) : 'نامشخص';
    }

    public static function funstatText(array $d): string
    {
        $p    = $d['profile'] ?? [];
        $s    = $d['stats'] ?? [];
        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        $n    = static fn($v) => fa(number_format((int) ($v ?? 0)));

        return "<b>🔍 استعلام کاربر</b>\n\n"
            . '🆔 آیدی: <code>' . (int) $d['id'] . "</code>\n"
            . '👤 نام: ' . ($name !== '' ? userTag((int) $d['id'], $name) : 'نامشخص') . "\n"
            . '📶 وضعیت: ' . (!empty($p['is_active']) ? '✅ فعال' : '💤 غیرفعال') . (!empty($p['is_bot']) ? ' · 🤖 ربات' : '') . "\n\n"
            . "<b>📊 آمار فعالیت</b>\n"
            . '📅 از ' . self::isoToJalali($p['first_msg_date'] ?? null) . ' تا ' . self::isoToJalali($p['last_msg_date'] ?? null) . "\n"
            . '💬 پیام‌ها: ' . $n($s['total_msg_count'] ?? 0) . ' (تو گروه‌ها: ' . $n($s['msg_in_groups_count'] ?? 0) . ")\n"
            . '👥 گروه‌ها: ' . $n($s['total_groups'] ?? 0) . ' · ادمین در ' . $n($s['adm_in_groups'] ?? 0) . "\n"
            . '🔁 تغییر یوزرنیم: ' . $n($s['usernames_count'] ?? 0) . ' · تغییر اسم: ' . $n($s['names_count'] ?? 0);
    }

    public static function funstatShort(array $d): string
    {
        $name = $d['profile']['first_name'] ?? 'نامشخص';
        return $name . ' · ' . fa(number_format((int) ($d['stats']['total_msg_count'] ?? 0))) . ' پیام';
    }
}
