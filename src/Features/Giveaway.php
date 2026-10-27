<?php

final class Giveaway
{
    private const DURATIONS = [1800 => '۳۰ دقیقه', 3600 => '۱ ساعت', 21600 => '۶ ساعت', 86400 => '۱ روز', 259200 => '۳ روز', 604800 => '۷ روز'];
    private const MEDALS    = ['🥇', '🥈', '🥉'];
    private const MAX_WIN   = 50;

    private static function botId(): int
    {
        return (int) strstr(App::tg()->token(), ':', true);
    }

    private static function rights(array $on): array
    {
        $all = ['is_anonymous', 'can_manage_chat', 'can_delete_messages', 'can_manage_video_chats', 'can_restrict_members', 'can_promote_members',
            'can_change_info', 'can_invite_users', 'can_post_stories', 'can_edit_stories', 'can_delete_stories', 'can_post_messages',
            'can_edit_messages', 'can_pin_messages', 'can_manage_topics'];
        $r = [];
        foreach ($all as $k) {
            $r[$k] = in_array($k, $on, true);
        }
        return $r;
    }

    private static function targetKeyboard(): array
    {
        $post = self::rights(['can_post_messages', 'can_edit_messages']);
        return [
            'keyboard' => [
                [['text' => '📢 انتخاب کانال', 'request_chat' => ['request_id' => 1, 'chat_is_channel' => true, 'user_administrator_rights' => $post, 'bot_administrator_rights' => $post, 'request_title' => true, 'request_username' => true]]],
                [['text' => '👥 انتخاب گروه', 'request_chat' => ['request_id' => 2, 'chat_is_channel' => false, 'user_administrator_rights' => self::rights([]), 'bot_is_member' => true, 'request_title' => true, 'request_username' => true]]],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
        ];
    }

    public static function start(array $msg, string $arg = ''): void
    {
        $tg   = App::tg();
        $uid  = (int) $msg['from']['id'];
        $chat = $msg['chat']['id'];
        if (preg_match('/^gw_(-?\d+)$/', $arg, $m)) {
            $t = self::checkTarget($uid, (int) $m[1]);
            if (isset($t['error'])) {
                $tg->send($chat, $t['error']);
                return;
            }
            self::askPrize($chat, $uid, $t);
            return;
        }
        $text = "🎁 <b>ساخت قرعه‌کشی</b>\n\n"
            . "🎯 قرعه‌کشی کجا برگزار بشه؟\n"
            . "از دکمه‌های پایین\nکانال یا گروهت رو انتخاب کن 👇\n\n"
            . "<i>💡 یا یه پیام از کانال فوروارد کن،\nیا آیدیش رو بفرست (مثل @mychannel)</i>";
        $a = $tg->send($chat, $text, ['reply_markup' => self::cancelKeyboard()]);
        $k = $tg->send($chat, '👇', ['reply_markup' => self::targetKeyboard()]);
        Store::set('gw_wiz', (string) $uid, ['step' => 'target', 'g' => [], 'm' => (int) ($a['result']['message_id'] ?? 0), 'k' => (int) ($k['result']['message_id'] ?? 0)]);
    }

    private static function cancelKeyboard(): array
    {
        return ikb([[btn('❌ لغو', 'gw:x', null)]]);
    }

    private static function dropKeyboard(int|string $chat, mixed $w): void
    {
        if (!is_array($w) || ($w['step'] ?? '') !== 'target') {
            return;
        }
        $tg = App::tg();
        $r  = $tg->send($chat, '👌', ['reply_markup' => ['remove_keyboard' => true]]);
        if (!empty($r['result']['message_id'])) {
            $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => (int) $r['result']['message_id']], 8);
        }
        if (!empty($w['k'])) {
            $tg->call('deleteMessage', ['chat_id' => $chat, 'message_id' => (int) $w['k']], 8);
        }
    }

    private static function put(int|string $chat, int $mid, string $text, ?array $kb = null): int
    {
        $tg = App::tg();
        if ($mid) {
            $r = $tg->editMessage($chat, $mid, $text, $kb);
            if (!empty($r['ok']) || stripos((string) ($r['description'] ?? ''), 'not modified') !== false) {
                return $mid;
            }
        }
        $r = $tg->send($chat, $text, $kb !== null ? ['reply_markup' => $kb] : []);
        return (int) ($r['result']['message_id'] ?? 0);
    }

    public static function parse(string $q): array
    {
        $q   = mbTrim($q);
        $out = AI::chat([
            ['role' => 'system', 'content' => 'Read the user\'s giveaway request (any language or phrasing) and reply with JSON only: {"prize": "<the prize exactly as the user wrote it, without the winner count or the time>", "winners": <number of winners, 1 if not said>, "minutes": <minutes until the draw, 0 if not said>}'],
            ['role' => 'user', 'content' => $q],
        ], ['tier' => 'lite', 'temperature' => 0, 'thinking' => null, 'deadline' => microtime(true) + 12]);
        $j     = $out !== null && preg_match('/\{.*\}/s', $out, $m) ? json_decode($m[0], true) : null;
        $prize = is_array($j) ? mbTrim((string) ($j['prize'] ?? '')) : '';
        $win   = is_array($j) ? (int) ($j['winners'] ?? 1) : 1;
        $min   = is_array($j) ? (int) ($j['minutes'] ?? 0) : 0;
        return [
            'prize'   => mb_substr($prize !== '' ? $prize : $q, 0, 200),
            'winners' => max(1, min(self::MAX_WIN, $win)),
            'dur'     => $min > 0 ? max(300, min(30 * 86400, $min * 60)) : 0,
        ];
    }

    public static function inlineResult(array $from, string $q): ?array
    {
        $q = mbTrim($q);
        if ($q === '') {
            return null;
        }
        $uid = (int) $from['id'];
        $id  = substr(md5($uid . '|' . $q . '|' . intdiv(time(), 600)), 0, 10);
        Store::set('gw_draft', $id, ['q' => mb_substr($q, 0, 300), 'owner' => $uid]);
        return Ui::article('gwi_' . $id, 'gift', '🎁 قرعه‌کشی: ' . mb_substr($q, 0, 60), 'بزن تا بسازمش',
            "<b>🎁 قرعه‌کشی</b>\n\n⏳ دارم آماده‌اش می‌کنم...", ikb([[btn('⏳ صبر کن...', 'noop', null)]]));
    }

    private static function inlineKeyboard(array $g, string $ask = ''): array
    {
        $id = $g['id'];
        if ($ask !== '') {
            return self::managerKeyboard($g, $ask);
        }
        if ($g['status'] === 'live') {
            return ikb([
                [btn('🎉 شرکت در قرعه‌کشی', 'gw:j:' . $id, 'success')],
                [btn('🏁 قرعه بکش', "gw:d:{$id}", null), btn('❌ لغو', "gw:c:{$id}", null)],
            ]);
        }
        if ($g['status'] === 'done' && (int) ($g['total'] ?? 0) > 0) {
            return ikb([[btn('🔁 دوباره قرعه بکش', "gw:r:{$id}", null)]]);
        }
        return ikb([]);
    }

    public static function fromDraft(string $id, string $imid, array $from): ?array
    {
        $d = Store::get('gw_draft', $id);
        if (!is_array($d) || (int) $d['owner'] !== (int) ($from['id'] ?? 0)) {
            return null;
        }
        if (!isset($d['prize'])) {
            $d = self::parse((string) ($d['q'] ?? '')) + ['owner' => (int) $d['owner']];
            Store::set('gw_draft', $id, $d);
        }
        return Store::update('gw', $id, static function ($g) use ($d, $id, $imid) {
            if (is_array($g)) {
                return null;
            }
            $ends = $d['dur'] ? time() + (int) $d['dur'] : 0;
            if ($ends) {
                Store::set('gw_due', $id, $ends);
            }
            return [
                'id' => $id, 'owner' => (int) $d['owner'], 'chat' => 0, 'imid' => $imid, 'title' => 'چت', 'type' => 'inline', 'username' => '',
                'prize' => $d['prize'], 'winners' => (int) $d['winners'], 'ends' => $ends, 'status' => 'live', 'created' => time(),
                'msg' => 0, 'mgr' => [0, 0], 'won' => [], 'total' => 0, 'bot' => App::botIndex(),
            ];
        }) ?? Store::get('gw', $id);
    }

    public static function onChosen(string $id, string $imid, array $from): void
    {
        if (self::fromDraft($id, $imid, $from) !== null) {
            Users::hit('gw:inline');
            $g = Store::get('gw', $id);
            if (is_array($g)) {
                self::refreshPublic($g);
            }
        }
    }

    private static function refreshPublic(array $g): void
    {
        if (!empty($g['imid'])) {
            App::tg()->editInline($g['imid'], self::publicText($g, self::count($g['id'])), self::inlineKeyboard($g));
        } else {
            App::tg()->editMessage($g['chat'], (int) $g['msg'], self::publicText($g, self::count($g['id'])), self::joinKeyboard($g['id']));
        }
    }

    private static function editPublic(array $g, string $text, ?array $kb = null): void
    {
        if (!empty($g['imid'])) {
            App::tg()->editInline($g['imid'], $text, $kb);
        } else {
            App::tg()->editMessage($g['chat'], (int) $g['msg'], $text, $kb);
        }
    }

    public static function fromGroup(array $msg): void
    {
        $tg = App::tg();
        if (!empty($msg['sender_chat'])) {
            $tg->reply($msg, "🕶 ناشناس پیام دادی و نمی‌شناسمت!\nبیا پیوی ربات و /giveaway بزن،\nبعد این گروه رو انتخاب کن.");
            return;
        }
        $st = self::status($msg['chat']['id'], (int) $msg['from']['id']);
        if (!in_array($st, ['creator', 'administrator'], true)) {
            $tg->reply($msg, '🙅 فقط ادمین‌های گروه می‌تونن قرعه‌کشی بسازن.');
            return;
        }
        $bot = App::botUsername();
        $tg->reply($msg, "🎁 برای تنظیم قرعه‌کشی بیا پیوی 👇", [
            'reply_markup' => ikb([[urlBtn('⚙️ تنظیم قرعه‌کشی', "https://t.me/{$bot}?start=gw_" . $msg['chat']['id'], 'success')]]),
        ]);
    }

    public static function clear(int $uid): void
    {
        Store::delete('gw_wiz', (string) $uid);
    }

    public static function active(int $uid): bool
    {
        return is_array(Store::get('gw_wiz', (string) $uid));
    }

    public static function cancel(array $msg): void
    {
        $tg   = App::tg();
        $uid  = (int) $msg['from']['id'];
        $chat = $msg['chat']['id'];
        $w    = Store::get('gw_wiz', (string) $uid);
        $mid  = is_array($w) ? (int) ($w['m'] ?? 0) : 0;
        self::clear($uid);
        $text = '👌 ساخت قرعه‌کشی لغو شد.';
        if (!$mid) {
            $tg->send($chat, $text, ['reply_markup' => ['remove_keyboard' => true]]);
            return;
        }
        self::dropKeyboard($chat, $w);
        self::put($chat, $mid, $text);
    }

    public static function input(array $msg): bool
    {
        $tg   = App::tg();
        $uid  = (int) $msg['from']['id'];
        $chat = $msg['chat']['id'];
        $w    = Store::get('gw_wiz', (string) $uid);
        if (!is_array($w)) {
            return false;
        }
        $text = mbTrim((string) ($msg['text'] ?? ''));
        if ($text === 'لغو') {
            self::cancel($msg);
            return true;
        }

        if ($w['step'] === 'target') {
            $target = null;
            $fwd    = $msg['forward_origin']['chat'] ?? $msg['forward_from_chat'] ?? null;
            if (isset($msg['chat_shared']['chat_id'])) {
                $target = (int) $msg['chat_shared']['chat_id'];
            } elseif (is_array($fwd) && isset($fwd['id'])) {
                $target = (int) $fwd['id'];
            } elseif (preg_match('#(?:t\.me/|^@?)([A-Za-z]\w{3,31})$#', $text, $m)) {
                $target = '@' . $m[1];
            } elseif (preg_match('/^-\d{6,}$/', en($text))) {
                $target = (int) en($text);
            }
            if ($target === null) {
                self::put($chat, (int) ($w['m'] ?? 0), "👆 از دکمه‌ها کانال یا گروهت رو انتخاب کن،\nیا آیدیش رو بفرست.", self::cancelKeyboard());
                return true;
            }
            $t = self::checkTarget($uid, $target);
            if (isset($t['error'])) {
                self::put($chat, (int) ($w['m'] ?? 0), $t['error'], self::cancelKeyboard());
                return true;
            }
            self::askPrize($chat, $uid, $t);
            return true;
        }

        if ($w['step'] === 'prize') {
            if ($text === '' || $text[0] === '/') {
                self::put($chat, (int) ($w['m'] ?? 0), '✍️ جایزه رو به صورت متن بنویس.');
                return true;
            }
            if (mb_strlen($text) > 200) {
                self::put($chat, (int) ($w['m'] ?? 0), '✂️ یه‌کم کوتاه‌ترش کن (حداکثر ۲۰۰ کاراکتر).');
                return true;
            }
            $w['g']['prize'] = $text;
            $w['step']       = 'winners';
            $r = $tg->send($chat, '🏆 جایزه: <b>' . h($text) . "</b>\n\n👑 <b>چند نفر برنده بشن؟</b>\nاز دکمه‌ها انتخاب کن یا یه عدد (تا " . fa(self::MAX_WIN) . ') بفرست.', ['reply_markup' => self::winnersKeyboard()]);
            $w['m'] = (int) ($r['result']['message_id'] ?? 0);
            Store::set('gw_wiz', (string) $uid, $w);
            return true;
        }

        if ($w['step'] === 'winners') {
            $n = (int) en($text);
            if (!preg_match('/^\d{1,3}$/', en($text)) || $n < 1 || $n > self::MAX_WIN) {
                self::put($chat, (int) ($w['m'] ?? 0), '🔢 یه عدد بین ۱ تا ' . fa(self::MAX_WIN) . ' بفرست یا از دکمه‌ها انتخاب کن.', self::winnersKeyboard());
                return true;
            }
            self::setWinners($uid, $chat, (int) ($w['m'] ?? 0), $n);
            return true;
        }
        return false;
    }

    private static function checkTarget(int $uid, int|string $target): array
    {
        $tg = App::tg();
        $c  = $tg->call('getChat', ['chat_id' => $target], 10);
        if (empty($c['ok'])) {
            return ['error' => "🤷 پیداش نکردم.\nمطمئن شو منو اون‌جا اضافه کردی\n(تو کانال باید ادمین باشم)."];
        }
        $c    = $c['result'];
        $type = (string) ($c['type'] ?? '');
        if (!in_array($type, ['channel', 'group', 'supergroup'], true)) {
            return ['error' => '📍 فقط کانال یا گروه رو می‌شه انتخاب کرد.'];
        }
        $b  = $tg->call('getChatMember', ['chat_id' => $c['id'], 'user_id' => self::botId()], 10);
        $bs = (string) ($b['result']['status'] ?? '');
        if ($type === 'channel' && ($bs !== 'administrator' || empty($b['result']['can_post_messages']))) {
            return ['error' => "📢 اول منو تو کانال ادمین کن\n(با دسترسی ارسال و ویرایش پیام)،\nبعد دوباره انتخابش کن."];
        }
        if ($type !== 'channel' && !in_array($bs, ['member', 'administrator'], true)) {
            return ['error' => "👥 اول منو به گروه اضافه کن،\nبعد دوباره انتخابش کن."];
        }
        if (!in_array(self::status($c['id'], $uid), ['creator', 'administrator'], true)) {
            return ['error' => '🙅 فقط ادمین‌های اون‌جا می‌تونن قرعه‌کشی بسازن.'];
        }
        return ['id' => (int) $c['id'], 'title' => mb_substr((string) ($c['title'] ?? ''), 0, 100), 'type' => $type, 'username' => (string) ($c['username'] ?? '')];
    }

    private static function status(int|string $chat, int $uid): string
    {
        $r = App::tg()->call('getChatMember', ['chat_id' => $chat, 'user_id' => $uid], 10);
        $s = (string) ($r['result']['status'] ?? '');
        if ($s === 'restricted') {
            return !empty($r['result']['is_member']) ? 'member' : 'left';
        }
        return $s;
    }

    private static function members(int|string $chat, array $uids): array
    {
        $res = App::tg()->multi('getChatMember', array_combine($uids, array_map(static fn($u) => ['chat_id' => $chat, 'user_id' => $u], $uids)), 10);
        $out = [];
        foreach ($uids as $u) {
            $r = $res[$u] ?? null;
            if (!is_array($r) || (empty($r['ok']) && (int) ($r['error_code'] ?? 0) === 429)) {
                $out[$u] = self::isMember($chat, $u);
                continue;
            }
            $st      = (string) ($r['result']['status'] ?? '');
            $out[$u] = in_array($st, ['creator', 'administrator', 'member'], true) || ($st === 'restricted' && !empty($r['result']['is_member']));
        }
        return $out;
    }

    private static function isMember(int|string $chat, int $uid): bool
    {
        return in_array(self::status($chat, $uid), ['creator', 'administrator', 'member'], true);
    }

    private static function askPrize(int|string $chat, int $uid, array $t): void
    {
        $old = Store::get('gw_wiz', (string) $uid);
        if (is_array($old)) {
            foreach (['m', 'k'] as $f) {
                if (!empty($old[$f])) {
                    App::tg()->call('deleteMessage', ['chat_id' => $chat, 'message_id' => (int) $old[$f]], 8);
                }
            }
        }
        $r = App::tg()->send($chat, ($t['type'] === 'channel' ? '📢' : '👥') . ' مقصد: <b>' . h($t['title']) . "</b>\n\n"
            . "🏆 <b>جایزه چیه؟</b>\n"
            . "بنویسش؛\nمثلاً: <code>۳ ماه تلگرام پریمیوم</code>", ['reply_markup' => ['remove_keyboard' => true]]);
        Store::set('gw_wiz', (string) $uid, ['step' => 'prize', 'g' => ['chat' => $t], 'm' => (int) ($r['result']['message_id'] ?? 0)]);
    }

    private static function winnersKeyboard(): array
    {
        return ikb([
            array_map(static fn($n) => btn(fa($n), 'gw:w:' . $n, null), [1, 2, 3, 5, 10]),
            [btn('❌ لغو', 'gw:x', null)],
        ]);
    }

    private static function timeKeyboard(): array
    {
        $rows = [];
        $row  = [];
        foreach (self::DURATIONS as $s => $label) {
            $row[] = btn('⏱ ' . $label, 'gw:t:' . $s, null);
            if (count($row) === 3) {
                $rows[] = $row;
                $row    = [];
            }
        }
        $rows[] = [btn('✋ خودم دستی قرعه می‌کشم', 'gw:t:0', null)];
        $rows[] = [btn('❌ لغو', 'gw:x', null)];
        return ikb($rows);
    }

    private static function setWinners(int $uid, int|string $chat, int $mid, int $n): void
    {
        $w = Store::get('gw_wiz', (string) $uid);
        if (!is_array($w) || !in_array($w['step'], ['winners', 'time'], true)) {
            return;
        }
        $w['g']['winners'] = $n;
        $w['step']         = 'time';
        $text = '🏆 جایزه: <b>' . h($w['g']['prize']) . "</b>\n👑 برنده: <b>" . fa($n) . " نفر</b>\n\n⏳ <b>قرعه کی کشیده بشه؟</b>";
        $w['m'] = self::put($chat, $mid, $text, self::timeKeyboard());
        Store::set('gw_wiz', (string) $uid, $w);
    }

    private static function when(int $ends): string
    {
        return $ends ? Jalali::numericDate($ends) . ' ساعت ' . Jalali::time($ends) : 'با اعلام برگزارکننده';
    }

    public static function publicText(array $g, int $count): string
    {
        return "🎁 <b>قرعه‌کشی!</b>\n\n"
            . '🏆 جایزه: <b>' . h($g['prize']) . "</b>\n"
            . '👑 تعداد برنده: <b>' . fa($g['winners']) . "</b> نفر\n"
            . '⏳ پایان: <b>' . self::when((int) $g['ends']) . "</b>\n"
            . '👥 شرکت‌کننده‌ها: <b>' . fa(number_format($count)) . "</b> نفر\n\n"
            . 'برای شرکت، دکمه‌ی زیر رو بزن 👇';
    }

    private static function joinKeyboard(string $id): array
    {
        return ikb([[btn('🎉 شرکت در قرعه‌کشی', 'gw:j:' . $id, 'success')]]);
    }

    private static function mention(array $p): string
    {
        $name = mbTrim((string) ($p['name'] ?? ''));
        return '<a href="tg://user?id=' . (int) $p['uid'] . '">' . h($name !== '' ? mb_substr($name, 0, 40) : 'کاربر') . '</a>';
    }

    private static function winnerList(array $won): string
    {
        $out = [];
        foreach (array_values($won) as $i => $p) {
            $out[] = (self::MEDALS[$i] ?? fa($i + 1) . '.') . ' ' . self::mention($p);
        }
        return implode("\n", $out);
    }

    private static function resultText(array $g, bool $again): string
    {
        $head = $again ? "🔁 <b>قرعه دوباره کشیده شد!</b>\n\n" : "🎉 <b>قرعه‌کشی تموم شد!</b>\n\n";
        $body = '🏆 جایزه: <b>' . h($g['prize']) . "</b>\n"
            . '👥 شرکت‌کننده‌ها: <b>' . fa(number_format((int) $g['total'])) . "</b> نفر\n\n";
        if (!$g['won']) {
            return $head . $body . '😕 کسی شرکت نکرد، برنده‌ای نداریم.';
        }
        return $head . $body . "👑 <b>برنده‌ها:</b>\n" . self::winnerList($g['won']) . "\n\nمبارک باشه 🎊";
    }

    private static function link(array $g): ?string
    {
        if (empty($g['msg'])) {
            return null;
        }
        if (!empty($g['username'])) {
            return 'https://t.me/' . $g['username'] . '/' . $g['msg'];
        }
        $id = (string) $g['chat'];
        return str_starts_with($id, '-100') ? 'https://t.me/c/' . substr($id, 4) . '/' . $g['msg'] : null;
    }

    private static function managerText(array $g): string
    {
        $where = ($g['type'] === 'channel' ? '📢 ' : '👥 ') . h($g['title']);
        if ($g['status'] === 'cancel') {
            return "❌ <b>قرعه‌کشی لغو شد</b>\n\n{$where}\n🏆 " . h($g['prize']);
        }
        if ($g['status'] === 'done') {
            return "🏁 <b>قرعه‌کشی انجام شد</b>\n\n{$where}\n🏆 " . h($g['prize']) . "\n"
                . '👥 شرکت‌کننده‌ها: ' . fa(number_format((int) $g['total'])) . "\n\n"
                . ($g['won'] ? "👑 <b>برنده‌ها:</b>\n" . self::winnerList($g['won']) : '😕 کسی شرکت نکرد.');
        }
        return "✅ <b>قرعه‌کشی فعاله</b>\n\n{$where}\n🏆 " . h($g['prize']) . "\n"
            . '👑 ' . fa($g['winners']) . ' برنده · ⏳ ' . self::when((int) $g['ends']) . "\n"
            . '👥 شرکت‌کننده‌ها: <b>' . fa(number_format(self::count($g['id']))) . '</b> نفر';
    }

    private static function managerKeyboard(array $g, string $ask = ''): array
    {
        $id   = $g['id'];
        $rows = [];
        if ($ask !== '') {
            $q = ['d' => '✅ آره، قرعه بکش', 'c' => '✅ آره، لغوش کن', 'r' => '✅ آره، دوباره بکش'][$ask];
            $rows[] = [btn($q, "gw:{$ask}{$ask}:{$id}", 'danger'), btn('🔙 نه', "gw:s:{$id}", null)];
            return ikb($rows);
        }
        if ($g['status'] === 'live') {
            $rows[] = [btn('🔄 آمار', "gw:s:{$id}", null), btn('🏁 الان قرعه بکش', "gw:d:{$id}", 'success')];
            $rows[] = [btn('❌ لغو قرعه‌کشی', "gw:c:{$id}", null)];
        } elseif ($g['status'] === 'done' && (int) $g['total'] > 0) {
            $rows[] = [btn('🔁 دوباره قرعه بکش', "gw:r:{$id}", null)];
        }
        if (($l = self::link($g)) !== null && $g['status'] !== 'cancel') {
            $rows[] = [urlBtn('🔗 دیدن پیام قرعه‌کشی', $l, null)];
        }
        return ikb($rows);
    }

    public static function count(string $id): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM gw_entries WHERE gid = ?', [$id]);
    }

    public static function onCallback(array $cq, string $data): void
    {
        $tg   = App::tg();
        $uid  = (int) $cq['from']['id'];
        $chat = $cq['message']['chat']['id'] ?? null;
        $mid  = (int) ($cq['message']['message_id'] ?? 0);

        if ($data === 'gw:new') {
            $tg->answer($cq);
            if ($chat !== null) {
                self::start(['from' => $cq['from'], 'chat' => ['id' => $uid]]);
            }
            return;
        }
        if ($data === 'gw:x') {
            $w = Store::get('gw_wiz', (string) $uid);
            self::clear($uid);
            if ($chat !== null) {
                self::dropKeyboard($chat, $w);
                $tg->editMessage($chat, $mid, '👌 ساخت قرعه‌کشی لغو شد.');
            }
            $tg->answer($cq);
            return;
        }
        if (preg_match('/^gw:w:(\d{1,2})$/', $data, $m)) {
            self::setWinners($uid, $chat, $mid, max(1, min(self::MAX_WIN, (int) $m[1])));
            $tg->answer($cq);
            return;
        }
        if (preg_match('/^gw:t:(\d+)$/', $data, $m)) {
            $w = Store::get('gw_wiz', (string) $uid);
            if (!is_array($w) || $w['step'] !== 'time') {
                $tg->alert($cq, '⌛️ این مرحله منقضی شده؛' . "\n" . 'دوباره /giveaway بزن.');
                return;
            }
            $s = (int) $m[1];
            $w['g']['dur'] = isset(self::DURATIONS[$s]) ? $s : 0;
            $w['step']     = 'confirm';
            Store::set('gw_wiz', (string) $uid, $w);
            $g = $w['g'] + ['ends' => $w['g']['dur'] ? time() + $w['g']['dur'] : 0];
            $tg->editMessage($chat, $mid, "👀 <b>پیش‌نمایش:</b>\n\n" . self::publicText($g, 0) . "\n\n" . divider() . "\n"
                . 'منتشرش کنم تو «<b>' . h($g['chat']['title']) . '</b>»؟', ikb([
                    [btn('✅ انتشار', 'gw:go', 'success'), btn('❌ لغو', 'gw:x', null)],
                ]));
            $tg->answer($cq);
            return;
        }
        if ($data === 'gw:go') {
            self::publish($cq, $uid, $chat, $mid);
            return;
        }
        if (!preg_match('/^gw:(j|s|d|dd|c|cc|r|rr):([a-f0-9]{10})$/', $data, $m)) {
            $tg->answer($cq);
            return;
        }
        [, $act, $id] = $m;
        $imid = $cq['inline_message_id'] ?? null;
        $g    = Store::get('gw', $id);
        if (!is_array($g) && $imid) {
            $d = Store::get('gw_draft', $id);
            $g = is_array($d) ? self::fromDraft($id, $imid, ['id' => $d['owner']]) : null;
        }
        if (!is_array($g)) {
            $tg->alert($cq, '🤷 این قرعه‌کشی دیگه وجود نداره.');
            return;
        }
        if ($act === 'j') {
            self::join($cq, $g);
            return;
        }
        if ($uid !== (int) $g['owner'] && !App::isAdmin($uid)) {
            $tg->alert($cq, "🔒 فقط سازنده‌ی قرعه‌کشی\nبه این دکمه دسترسی داره.");
            return;
        }
        if (in_array($act, ['d', 'c', 'r'], true)) {
            $ok = ($act === 'r') ? $g['status'] === 'done' : $g['status'] === 'live';
            if ($ok && $imid) {
                $tg->call('editMessageReplyMarkup', ['inline_message_id' => $imid, 'reply_markup' => self::managerKeyboard($g, $act)]);
            } elseif ($ok) {
                $tg->call('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $mid, 'reply_markup' => self::managerKeyboard($g, $act)]);
            }
            $tg->answer($cq);
            return;
        }
        if ($act === 'dd') {
            $tg->answer($cq, '🎲 دارم قرعه می‌کشم...');
            self::draw($id, false);
            return;
        }
        if ($act === 'rr') {
            $tg->answer($cq, '🎲 دارم دوباره قرعه می‌کشم...');
            self::draw($id, true);
            return;
        }
        if ($act === 'cc') {
            $g = Store::update('gw', $id, static fn($x) => (is_array($x) && $x['status'] === 'live') ? ['status' => 'cancel'] + $x : null);
            if ($g !== null) {
                Store::delete('gw_due', $id);
                Store::delete('gw_dirty', $id);
                self::editPublic($g, "❌ <b>این قرعه‌کشی لغو شد.</b>\n\n🏆 " . h($g['prize']));
                $tg->answer($cq, '👌 لغو شد');
                if ($imid) {
                    return;
                }
            } else {
                $tg->answer($cq);
                $g = Store::get('gw', $id);
            }
        } else {
            $tg->answer($cq, $imid ? null : '🔄 به‌روز شد');
        }
        if ($imid) {
            if ($g['status'] === 'live') {
                self::refreshPublic($g);
            } else {
                $tg->call('editMessageReplyMarkup', ['inline_message_id' => $imid, 'reply_markup' => self::inlineKeyboard($g)]);
            }
            return;
        }
        $tg->editMessage($chat, $mid, self::managerText($g), self::managerKeyboard($g));
    }

    private static function publish(array $cq, int $uid, int|string $chat, int $mid): void
    {
        $tg = App::tg();
        $w  = Store::get('gw_wiz', (string) $uid);
        if (!is_array($w) || $w['step'] !== 'confirm') {
            $tg->alert($cq, '⌛️ منقضی شده؛' . "\n" . 'دوباره /giveaway بزن.');
            return;
        }
        $t  = $w['g']['chat'];
        $id = bin2hex(random_bytes(5));
        $g  = [
            'id'       => $id,
            'owner'    => $uid,
            'chat'     => $t['id'],
            'title'    => $t['title'],
            'type'     => $t['type'],
            'username' => $t['username'],
            'prize'    => $w['g']['prize'],
            'winners'  => (int) $w['g']['winners'],
            'ends'     => $w['g']['dur'] ? time() + (int) $w['g']['dur'] : 0,
            'status'   => 'live',
            'created'  => time(),
            'msg'      => 0,
            'mgr'      => [$chat, $mid],
            'won'      => [],
            'total'    => 0,
            'bot'      => App::botIndex(),
        ];
        $r = $tg->send($t['id'], self::publicText($g, 0), ['reply_markup' => self::joinKeyboard($id)]);
        if (empty($r['ok'])) {
            $tg->alert($cq, '❌ نتونستم تو «' . $t['title'] . '» پیام بفرستم؛' . "\n" . 'دسترسی‌هام رو چک کن.');
            return;
        }
        $g['msg'] = (int) $r['result']['message_id'];
        Store::set('gw', $id, $g);
        if ($g['ends']) {
            Store::set('gw_due', $id, $g['ends']);
        }
        self::clear($uid);
        Users::hit('gw:create');
        $tg->answer($cq, '🎉 منتشر شد!');
        $tg->editMessage($chat, $mid, self::managerText($g), self::managerKeyboard($g));
    }

    private static function join(array $cq, array $g): void
    {
        $tg   = App::tg();
        $from = $cq['from'];
        $uid  = (int) $from['id'];
        if ($g['status'] !== 'live') {
            $tg->alert($cq, '⌛️ این قرعه‌کشی تموم شده.');
            return;
        }
        if ($g['type'] !== 'inline' && !self::isMember($g['chat'], $uid)) {
            $tg->alert($cq, $g['type'] === 'channel' ? '📢 اول عضو کانال شو، بعد دکمه رو بزن!' : '👥 اول عضو گروه شو، بعد دکمه رو بزن!');
            return;
        }
        $new = Db::run('INSERT INTO gw_entries (gid, uid, name, at) VALUES (?, ?, ?, ?) ON CONFLICT (gid, uid) DO NOTHING',
            [$g['id'], $uid, mb_substr(userName($from), 0, 64), time()])->rowCount() > 0;
        if (!$new) {
            $tg->alert($cq, '👌 قبلاً ثبت‌نام کردی؛' . "\n" . 'منتظر نتیجه باش!');
            return;
        }
        Store::set('gw_dirty', $g['id'], time());
        Users::hit('gw:join');
        $tg->alert($cq, "✅ ثبت‌نام شدی!\nنفر " . fa(number_format(self::count($g['id']))) . "ام هستی، موفق باشی 🍀");
    }

    private static function rows(string $ns): array
    {
        $out = [];
        foreach (Db::run('SELECT k, v FROM kv WHERE ns = ?', [$ns])->fetchAll() as $r) {
            $out[(string) $r['k']] = (int) json_decode((string) $r['v'], true);
        }
        return $out;
    }

    public static function pending(): bool
    {
        if (self::rows('gw_dirty')) {
            return true;
        }
        foreach (self::rows('gw_due') as $at) {
            if ($at <= time()) {
                return true;
            }
        }
        return false;
    }

    public static function tick(): void
    {
        foreach (self::rows('gw_due') as $id => $at) {
            if ($at <= time()) {
                self::draw($id, false);
            }
        }
        foreach (array_keys(self::rows('gw_dirty')) as $id) {
            Store::delete('gw_dirty', $id);
            $g = Store::get('gw', $id);
            if (is_array($g) && $g['status'] === 'live') {
                App::useBot((int) ($g['bot'] ?? 0));
                self::refreshPublic($g);
            }
        }
        App::useBot(0);
    }

    private static function shuffle(array $a): array
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    }

    public static function draw(string $id, bool $again): bool
    {
        $g  = Store::update('gw', $id, static fn($x) => (is_array($x) && ($x['status'] === 'live' || ($again && $x['status'] === 'done'))) ? ['status' => 'drawing'] + $x : null);
        if ($g === null) {
            return false;
        }
        App::useBot((int) ($g['bot'] ?? 0));
        $tg = App::tg();
        Store::delete('gw_due', $id);
        Store::delete('gw_dirty', $id);
        $won = [];
        try {
            $all  = Db::run('SELECT uid, name FROM gw_entries WHERE gid = ?', [$id])->fetchAll();
            $prev = $again ? array_flip(array_map(static fn($p) => (int) $p['uid'], (array) $g['won'])) : [];
            $pool = array_values(array_filter($all, static fn($p) => !isset($prev[(int) $p['uid']])));
            if (!$pool) {
                $pool = $all;
            }
            $won  = [];
            $need = (int) $g['winners'];
            $left = self::shuffle($pool);
            while (count($won) < $need && $left) {
                $batch = array_splice($left, 0, $g['type'] === 'inline' ? $need : min(25, max(5, ($need - count($won)) * 3)));
                $ok    = $g['type'] === 'inline' ? null : self::members($g['chat'], array_map(static fn($p) => (int) $p['uid'], $batch));
                foreach ($batch as $p) {
                    if (count($won) >= $need) {
                        break;
                    }
                    if ($ok === null || !empty($ok[(int) $p['uid']])) {
                        $won[] = ['uid' => (int) $p['uid'], 'name' => (string) $p['name']];
                    }
                }
            }
            $g['won']    = $won;
            $g['total']  = count($all);
            $g['drawn']  = time();
        } finally {
            $g['status'] = 'done';
            Store::set('gw', $id, $g);
        }

        $inline = !empty($g['imid']);
        self::editPublic($g, self::resultText($g, $again), $inline ? self::inlineKeyboard($g) : null);
        if ($won && !$inline) {
            $tg->send($g['chat'], "🎊 <b>برنده‌های قرعه‌کشی «" . h($g['prize']) . "»</b>\n\n" . self::winnerList($won) . "\n\nتبریک! 🥳", [
                'reply_parameters' => ['message_id' => (int) $g['msg'], 'allow_sending_without_reply' => true],
            ]);
        }
        if ($won) {
            foreach ($won as $p) {
                $tg->call('sendMessage', [
                    'chat_id'    => $p['uid'],
                    'text'       => "🎉 <b>تبریک!</b>\n\nتو قرعه‌کشی «" . h($g['prize']) . '»' . ($inline ? '' : ' تو <b>' . h($g['title']) . '</b>') . " برنده شدی 🥳\nمنتظر پیام برگزارکننده باش.",
                    'parse_mode' => 'HTML',
                ], 10);
            }
        }
        [$mc, $mm] = $g['mgr'] + [0, 0];
        if ($mc && $mm) {
            $tg->editMessage($mc, (int) $mm, self::managerText($g), self::managerKeyboard($g));
        } elseif ($inline) {
            $tg->call('sendMessage', ['chat_id' => (int) $g['owner'], 'text' => self::resultText($g, $again), 'parse_mode' => 'HTML'], 10);
        }
        logInfo('gw', "{$id} drawn: " . count($won) . '/' . $g['total']);
        return true;
    }

    public static function janitor(): void
    {
        Db::run('DELETE FROM gw_entries WHERE at < ?', [time() - 120 * 86400]);
    }
}
