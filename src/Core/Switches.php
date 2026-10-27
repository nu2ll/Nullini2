<?php

/** روشن/خاموش کردن ابزارها از پنل ادمین (/tools). کلیدها با اسم Intent هم‌نامن. */
final class Switches
{
    public const TOOLS = [
        'ai'       => '🧠 هوش مصنوعی (چت)',
        'image'    => '🎨 ساخت عکس',
        'translate' => '🌐 ترجمه',
        'search'   => '🔍 سرچ گوگل',
        'wiki'     => '📖 ویکی‌پدیا',
        'weather'  => '🌦 آب‌وهوا',
        'rates'    => '💱 نرخ ارز و طلا',
        'download' => '⬇️ دانلودر',
        'whisper'  => '🤫 نجوا',
        'giveaway' => '🎁 قرعه‌کشی',
        'font'     => '✨ فونت‌ساز',
        'qr'       => '🔳 QR کد',
        'calc'     => '🧮 ماشین‌حساب',
        'love'     => '💘 تطابق عشقی',
        'roast'    => '🔥 روست',
        'nid'      => '🪪 کد ملی',
        'userinfo' => '👤 اطلاعات و استعلام کاربر',
        'fal'      => '🔮 فال حافظ',
        'password' => '🔑 پسورد ساز',
        'date'     => '📅 تاریخ و ساعت',
        'coin'     => '🪙 شیر یا خط',
        'chess'    => '♟️ شطرنج',
        'ttt'      => '⭕ دوز',
        'c4'       => '🔴 دوز سکه‌ای',
        'rps'      => '✊ سنگ کاغذ قیچی',
        'math'     => '🔢 دوئل ریاضی',
    ];

    /** دستورهای منوی تلگرام که به هر سوییچ وصلن (خاموش شد، از منو حذف می‌شه) */
    public const COMMAND_SWITCH = [
        'img' => 'image', 'image' => 'image',
        'tr'  => 'translate', 'translate' => 'translate',
        'ft'  => 'font', 'font' => 'font',
        'gw'  => 'giveaway', 'giveaway' => 'giveaway',
        'wk'  => 'wiki', 'wiki' => 'wiki',
    ];

    private const GAME_KEYS = ['chess', 'ttt', 'c4', 'rps', 'math'];

    private static ?array $off = null;

    private static function load(): array
    {
        if (self::$off !== null) {
            return self::$off;
        }
        $raw = (array) Store::get('cfg', 'tools_off', []);
        if (in_array('games', $raw, true)) {
            // سوییچ قدیمی «بازی‌ها» → هر پنج بازی خاموش
            $raw = array_merge($raw, self::GAME_KEYS);
        }
        return self::$off = array_values(array_unique(array_filter($raw, static fn($k) => isset(self::TOOLS[$k]))));
    }

    public static function on(string $key): bool
    {
        return !isset(self::TOOLS[$key]) || !in_array($key, self::load(), true);
    }

    public static function toggle(string $key): void
    {
        if (!isset(self::TOOLS[$key])) {
            return;
        }
        $off = self::load();
        $off = in_array($key, $off, true) ? array_values(array_diff($off, [$key])) : array_merge($off, [$key]);
        Store::set('cfg', 'tools_off', $off);
        self::$off = $off;
    }

    public static function setAll(bool $on): void
    {
        $off = $on ? [] : array_keys(self::TOOLS);
        Store::set('cfg', 'tools_off', $off);
        self::$off = $off;
    }

    /** منوی دستورهای تلگرام رو با وضعیت فعلی سوییچ‌ها دوباره می‌سازه */
    public static function syncCommands(): void
    {
        try {
            foreach (App::tokens() as $bi => $_) {
                $tg = App::tgFor($bi);
                $tg->call('setMyCommands', ['commands' => Ui::commands('private'), 'scope' => ['type' => 'all_private_chats']], 10);
                $tg->call('setMyCommands', ['commands' => Ui::commands('group'), 'scope' => ['type' => 'all_group_chats']], 10);
                foreach ((array) App::cfg('admins', []) as $adminId) {
                    if ((int) $adminId > 0) {
                        $tg->call('setMyCommands', ['commands' => Ui::commands('admin'), 'scope' => ['type' => 'chat', 'chat_id' => (int) $adminId]], 10);
                    }
                }
            }
        } catch (\Throwable $e) {
            logError('tools', 'syncCommands: ' . $e->getMessage());
        }
    }

    /** اگه خاموش باشه بی‌صدا false برمی‌گردونه (هیچ پیامی نمی‌ره) */
    public static function guard(string $key): bool
    {
        return self::on($key);
    }

    public static function text(): string
    {
        $off = self::load();
        return "<b>🧰 مدیریت ابزارها</b>\n\nروی هر دکمه بزنی روشن/خاموش می‌شه.\n✅ روشن · ⛔ خاموش"
            . ($off ? "\n\n⛔ خاموش: " . fa((string) count($off)) . ' از ' . fa((string) count(self::TOOLS)) : '');
    }

    public static function keyboard(): array
    {
        $rows = [];
        $row  = [];
        foreach (self::TOOLS as $k => $label) {
            $on    = self::on($k);
            $row[] = btn(($on ? '✅ ' : '⛔ ') . $label, 'tl:t:' . $k, $on ? 'success' : 'danger');
            if (count($row) === 2) {
                $rows[] = $row;
                $row    = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $rows[] = [btn('✅ روشن‌کردن همه', 'tl:all:1', null), btn('⛔ خاموش‌کردن همه', 'tl:all:0', null)];
        return ikb($rows);
    }

    public static function onCallback(array $cq, string $data): void
    {
        $tg = App::tg();
        if (!App::isAdmin((int) ($cq['from']['id'] ?? 0))) {
            $tg->alert($cq, '🙅 این پنل مخصوص ادمینه.');
            return;
        }
        $chatId = $cq['message']['chat']['id'] ?? null;
        $msgId  = $cq['message']['message_id'] ?? null;
        $p      = explode(':', $data);
        if ($p[1] === 't' && isset($p[2])) {
            self::toggle($p[2]);
        } elseif (($p[1] ?? '') === 'all') {
            self::setAll(($p[2] ?? '1') === '1');
        }
        if ($chatId !== null && $msgId !== null) {
            $tg->editMessage($chatId, $msgId, self::text(), self::keyboard());
        }
        $tg->answer($cq);
        self::syncCommands();
    }
}
