<?php

final class Ui
{
    private const ICON_BASE = 'https://raw.githubusercontent.com/microsoft/fluentui-emoji/main/assets/';

    private const ICONS = [
        'info'     => ['Bust in silhouette', 'bust_in_silhouette'],
        'calc'     => ['Abacus', 'abacus'],
        'date'     => ['Calendar', 'calendar'],
        'rates'    => ['Currency exchange', 'currency_exchange'],
        'pass'     => ['Key', 'key'],
        'coin'     => ['Coin', 'coin'],
        'fal'      => ['Crystal ball', 'crystal_ball'],
        'rps'      => ['Rock', 'rock'],
        'ttt'      => ['Cross mark', 'cross_mark'],
        'c4'       => ['Red circle', 'red_circle'],
        'math'     => ['Input numbers', 'input_numbers'],
        'chess'    => ['Chess pawn', 'chess_pawn'],
        'whisper'  => ['Shushing face', 'shushing_face'],
        'weather'  => ['Sun behind rain cloud', 'sun_behind_rain_cloud'],
        'nid'      => ['Identification card', 'identification_card'],
        'funstat'  => ['Magnifying glass tilted left', 'magnifying_glass_tilted_left'],
        'ai'       => ['Brain', 'brain'],
        'love'     => ['Heart with arrow', 'heart_with_arrow'],
        'roast'    => ['Fire', 'fire'],
        'translate' => ['Globe with meridians', 'globe_with_meridians'],
        'qr'       => ['Framed picture', 'framed_picture'],
        'image'    => ['Artist palette', 'artist_palette'],
        'dice'     => ['Game die', 'game_die'],
        'warn'     => ['Warning', 'warning'],
        'fail'     => ['Cross mark', 'cross_mark'],
        'ok'       => ['Check mark button', 'check_mark_button'],
        'stop'     => ['Stop sign', 'stop_sign'],
        'lock'     => ['Locked with key', 'locked_with_key'],
        'robot'    => ['Robot', 'robot'],
        'search'   => ['Magnifying glass tilted right', 'magnifying_glass_tilted_right'],
        'font'     => ['Input latin letters', 'input_latin_letters'],
        'download' => ['Inbox tray', 'inbox_tray'],
        'gift'     => ['Wrapped gift', 'wrapped_gift'],
        'wiki'     => ['Books', 'books'],
        'group'    => ['Busts in silhouette', 'busts_in_silhouette'],
    ];

    public static function icon(string $key): string
    {
        [$dir, $file] = self::ICONS[$key] ?? self::ICONS['robot'];
        return self::ICON_BASE . rawurlencode($dir) . '/3D/' . $file . '_3d.png';
    }

    public static function article(string $id, string $icon, string $title, string $desc, string $text, ?array $markup = null, bool $html = true): array
    {
        $clean = preg_replace('/^[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\s]+/u', '', $title);
        $r = [
            'type'                  => 'article',
            'id'                    => mb_substr($id, 0, 64),
            'title'                 => ($clean !== null && $clean !== '') ? $clean : $title,
            'description'           => $desc,
            'thumbnail_url'         => self::icon($icon),
            'thumbnail_width'       => 256,
            'thumbnail_height'      => 256,
            'input_message_content' => [
                'message_text'         => $text,
                'parse_mode'           => $html ? 'HTML' : null,
                'link_preview_options' => ['is_disabled' => true],
            ],
        ];
        if (!$html) {
            unset($r['input_message_content']['parse_mode']);
        }
        if ($markup !== null) {
            $r['reply_markup'] = $markup;
        }
        return $r;
    }

    public static function startText(array $from): string
    {
        $bot = App::botUsername();
        return "سلام " . safeName($from) . " 👋\n"
            . "من <b>نالینی</b>‌ام؛\n"
            . "یه ربات اینلاین همه‌کاره با هوش مصنوعی 😎\n\n"
            . "✨ تو <b>هر چتی</b> بنویس\n"
            . "<code>@{$bot}</code>\n"
            . "و از منو انتخاب کن،\n"
            . "یا همین‌جا مستقیم باهام حرف بزن؛\n"
            . "حتی با عکس و ویس 🎙\n\n"
            . "🚀 دکمه‌ی پایین رو بزن و ببین چه کارایی ازم برمیاد!";
    }

    public static function groupText(): string
    {
        return "سلام به همگی 👋 من <b>نالینی</b>‌ام!\n\n"
            . "🧠 هر وقت سؤالی داشتین\n"
            . "پیامتون رو با «نالینی» شروع کنین.\n\n"
            . "🎮 بازی و ابزارها هم\n"
            . "با نوشتن <code>@" . App::botUsername() . "</code> در دسترسه.\n\n"
            . "<i>💡 اگه جواب ندادم، منو ادمین گروه کنین\n"
            . "تا پیام‌هاتون به دستم برسه.</i>";
    }

    public static function hello(): string
    {
        $lines = [
            'جانم؟ 😄 بگو چی کار داری',
            'بله؟ اینجام 👋 سؤالت رو بپرس',
            'در خدمتم 😎 چی لازم داری؟',
            'صدام کردی؟ 👀 بگو ببینم',
            'حاضرم! 🫡 امرتون؟',
            'هوم؟ 🤔 گوشم با توئه',
            'سلام سلام! 🙌 چه کمکی از دستم برمیاد؟',
            'نالینی گزارش می‌ده: آماده‌ام 🚀',
            'جون دلم؟ 😁 بپرس',
            'بگو، سراپا گوشم 🎧',
            'اومدم! ⚡️ چی شده؟',
            'یه سؤال بپرس، یه جواب تحویل بگیر 😉',
        ];
        return $lines[array_rand($lines)] . "\n<i>یا از منو یکی رو انتخاب کن 👇</i>";
    }

    public static function groupKeyboard(): array
    {
        return ikb([[['text' => '🚀 منوی نالینی', 'switch_inline_query_current_chat' => '', 'style' => 'success']]]);
    }

    public static function commands(string $scope): array
    {
        $c = static fn(string $cmd, string $d) => ['command' => $cmd, 'description' => $d];
        $base = [
            $c('start', '🏠 شروع'),
            $c('help', '📘 راهنما'),
            $c('img', '🎨 ساخت عکس'),
            $c('tr', '🌐 ترجمه'),
            $c('ft', '✨ فونت‌ساز'),
            $c('gw', '🎁 قرعه‌کشی'),
            $c('wk', '📖 ویکی‌پدیا'),
        ];
        if ($scope === 'admin') {
            return array_merge($base, [
                $c('stats', '📊 آمار ربات'),
                $c('ai', '🤖 پنل هوش مصنوعی'),
                $c('quota', '🔋 سهمیه‌ی هوش مصنوعی'),
                $c('backup', '💾 بکاپ دستی دیتابیس'),
                $c('broadcast', '📣 پیام همگانی (روی پیام ریپلای کن)'),
            ]);
        }
        return $base;
    }

    public static function startKeyboard(): array
    {
        $bot = App::botUsername();
        return ikb([
            [['text' => '🚀 امتحانش کن', 'switch_inline_query_current_chat' => '', 'style' => 'success']],
            [btn('📘 راهنما', 'help:menu'), btn('ℹ️ درباره', 'about')],
            [urlBtn('➕ افزودن به گروه', "https://t.me/{$bot}?startgroup=true")],
        ]);
    }

    public static function aboutText(): string
    {
        $c = App::cfg('creator_name', 'Arown');
        $v = App::cfg('bot_version', '3.0.0');
        return "<b>ℹ️ درباره‌ی نالینی</b>\n\n"
            . "اسمم از <code>null</code> (یعنی «خالی») میاد، ولی دستم پره 😉\n"
            . "یه دستیار فارسیِ اینلاین\n"
            . "با هوش مصنوعی، ابزار و بازی؛\n"
            . "تو هر چتی، بدون این‌که عضوش باشم.\n\n"
            . "👨‍💻 سازنده: <b>" . h($c) . "</b>\n"
            . "🔖 نسخه: <code>" . h($v) . "</code>";
    }

    public static function aboutKeyboard(bool $withBack): array
    {
        $rows = [];
        $row  = [];
        if (App::cfg('creator_username')) {
            $row[] = urlBtn('👤 سازنده', 'https://t.me/' . App::cfg('creator_username'));
        }
        if (App::cfg('channel_url')) {
            $row[] = urlBtn('📢 کانال', App::cfg('channel_url'));
        }
        $rows[] = $row;
        if ($withBack) {
            $rows[] = [btn('🔙 برگشت', 'home', null)];
        }
        return ikb(array_values(array_filter($rows)));
    }

    public const GUIDES = [
        'ai'        => '🧠 هوش مصنوعی',
        'image'     => '🎨 ساخت عکس',
        'translate' => '🌐 ترجمه',
        'search'    => '🔍 سرچ گوگل',
        'font'      => '✨ فونت‌ساز',
        'download'  => '📥 دانلودر',
        'giveaway'  => '🎁 قرعه‌کشی',
        'wiki'      => '📖 ویکی‌پدیا',
        'aicheck'   => '🕵️ تشخیص عکس AI',
        'whisper'   => '🤫 نجوا',
        'weather'   => '🌦 آب‌وهوا',
        'calc'      => '🧮 محاسبه سریع',
        'qr'        => '🔳 QR کد',
        'love'      => '💘 تطابق عشقی',
        'roast'     => '🔥 روست',
        'nid'       => '🪪 کد ملی',
        'funstat'   => '🔍 استعلام کاربر',
        'games'     => '🎮 بازی‌ها',
        'group'     => '👥 در گروه',
    ];

    public static function guide(string $key): string
    {
        $b = '@' . App::botUsername();
        $c = static fn(string $s) => "<code>{$b} {$s}</code>";

        return match ($key) {
            'ai' => "<b>🧠 هوش مصنوعی</b>\n"
                . "هر سؤالی داری بپرس؛\n"
                . "از درس و کد تا مشاوره و گپ.\n\n"
                . "• اینلاین:\n" . $c('ai سلام چطوری؟') . "\n"
                . "• پیوی ربات:\n"
                . "مستقیم پیام، عکس یا ویس بفرست\n"
                . "• گروه:\n"
                . "اول یا جایی در پیامت بنویس «نالینی»،\n"
                . "منو تگ کن یا روی پیامم ریپلای کن\n\n"
                . "💡 ۲۰ پیام آخر هر چت یادم می‌مونه؛\n"
                . "هر شب ساعت ۱۲ همه‌چی از نو شروع می‌شه.",

            'image' => "<b>🎨 ساخت عکس</b>\n"
                . "لازم نیست دستور خاصی بلد باشی؛\n"
                . "عادی بگو چی می‌خوای،\n"
                . "خودم می‌فهمم و می‌کشم.\n\n"
                . "• پیوی:\n<code>یه گربه‌ی فضانورد برام بکش</code>\n"
                . "• گروه:\n<code>نالینی یه والپیپر از جنگل بارونی می‌خوام</code>\n"
                . "• یا دستور:\n<code>/img شهر آینده‌نگر در شب</code>",

            'translate' => "<b>🌐 ترجمه</b>\n"
                . "فارسی ⇄ انگلیسی خودکار،\n"
                . "با هوش مصنوعی.\n\n"
                . "• اینلاین:\n" . $c('tr سلام، حالت چطوره؟') . "\n"
                . "• پیوی یا گروه:\n"
                . "روی یه پیام ریپلای کن و بنویس\n"
                . "<code>نالینی ترجمه کن</code>",

            'search' => "<b>🔍 سرچ گوگل</b>\n"
                . "تو هر چتی سرچ کن و نتیجه رو بفرست؛\n"
                . "دکمه‌ها مستقیم به گوگل وصل می‌شن.\n\n"
                . "• " . $c('g هوای تهران فردا') . "\n"
                . "• " . $c('g best php framework') . "\n\n"
                . "💡 علاوه بر دکمه‌ی گوگل،\n"
                . "چندتا نتیجه‌ی آماده هم زیرش میاد\n"
                . "که می‌تونی مستقیم بفرستی.",

            'download' => "<b>📥 دانلودر</b>\n"
                . "لینک رو بفرست،\n"
                . "ویدیو یا آهنگش رو تحویل بگیر!\n\n"
                . "• پیوی ربات: فقط لینک رو بفرست\n"
                . "• گروه: لینک رو تنها بفرست یا ربات رو تگ کن\n"
                . "• هر چتی (حتی بدون عضویت): ربات رو تگ کن + لینک\n\n"
                . "🌐 یوتیوب، اینستاگرام، تیک‌تاک،\n"
                . "توییتر، فیسبوک، ردیت، پینترست، اسنپ‌چت،\n"
                . "ساندکلاد و اسپاتیفای\n\n"
                . "🖼 پستای عکس‌دار\n"
                . "اینستا، پینترست، توییتر و ردیت هم عکسشون رو می‌گیرم\n\n"
                . "💡 تا " . Downloader::limitText() . "؛\n"
                . "برای ویدیوی بلند\n"
                . "«کم‌حجم» یا «فقط صدا» رو بزن.",

            'giveaway' => "<b>🎁 قرعه‌کشی</b>\n"
                . "برای کانال، گروه یا هر چتی قرعه‌کشی بساز؛\n"
                . "بقیه با یه دکمه شرکت می‌کنن\n"
                . "و خودم سر وقت برنده‌ها رو انتخاب و اعلام می‌کنم 🎉\n\n"
                . "• پیوی: /gw بزن یا بگو «یه قرعه‌کشی بساز»\n"
                . "• گروه: ادمین /gw بزنه\n"
                . "• اینلاین تو هر چتی:\n" . $c('gw 2 1h آیفون') . "\n"
                . "(۲ برنده، ۱ ساعت دیگه، جایزه آیفون)\n\n"
                . "💡 تو کانال باید ادمین باشم؛\n"
                . "اون‌جا فقط اعضا می‌تونن شرکت کنن.",

            'font' => "<b>✨ فونت‌ساز</b>\n"
                . "اسم یا متنت رو\n"
                . "با کلی فونت و استایل خوشگل بنویس؛\n"
                . "هم انگلیسی، هم فارسی.\n\n"
                . "• " . $c('ft Arown') . "\n"
                . "• " . $c('ft نالینی') . "\n"
                . "• پیوی ربات:\n<code>فونت Arown</code>\n\n"
                . "💡 هر نتیجه یه دکمه‌ی «📋 کپی» داره.",

            'wiki' => "<b>📖 ویکی‌پدیا</b>\n"
                . "خلاصه‌ی هر موضوعی\n"
                . "از ویکی‌پدیای فارسی و انگلیسی.\n\n"
                . "• " . $c('wk تخت جمشید') . "\n"
                . "• " . $c('wk Albert Einstein') . "\n"
                . "• پیوی یا گروه:\n"
                . "<code>نالینی ویکی‌پدیا درباره‌ی حافظ چی میگه</code>\n"
                . "• دستور:\n<code>/wk حافظ</code>",

            'whisper' => "<b>🤫 نجوا</b>\n"
                . "پیام مخفی وسط هر چتی\n"
                . "که فقط گیرنده می‌تونه بخونه.\n\n"
                . "• " . $c('nj متن پیام @username') . "\n"
                . "• " . $c('nj متن پیام 12345678') . "\n\n"
                . "💡 گیرنده تگ می‌شه تا بفهمه نجوا براش اومده.\n"
                . "حداکثر " . fa(Whisper::MAX_LEN) . " کاراکتر؛\n"
                . "فرستنده می‌تونه پاکش کنه و ببینه کیا فضولی کردن 🕵️",

            'weather' => "<b>🌦 آب‌وهوا</b>\n"
                . "وضعیت هوا و پیش‌بینی دو روز آینده‌ی هر شهری.\n\n"
                . "• " . $c('hv tehran') . "\n"
                . "• " . $c('hv رشت') . "\n\n"
                . "💡 فارسی یا انگلیسی فرقی نداره؛\n"
                . "مستقیم بنویس.",

            'calc' => "<b>🧮 محاسبه سریع</b>\n"
                . "یه عبارت ریاضی بنویس،\n"
                . "جوابش رو همون لحظه می‌بینی.\n\n"
                . "• " . $c('(12+8)*3^2') . "\n\n"
                . "💡 ماشین‌حساب دکمه‌ای هم\n"
                . "تو منوی اصلی هست.",

            'qr' => "<b>🔳 QR کد</b>\n"
                . "هر متن یا لینکی رو به QR تبدیل کن.\n\n"
                . "• " . $c('qr https://t.me/nullini'),

            'love' => "<b>💘 تطابق عشقی</b>\n"
                . "درصد عشق بین دو نفر (کاملاً شانسی و فان 😄).\n\n"
                . "• " . $c('love @ali @sara') . "\n"
                . "• " . $c('love @ali 12345678'),

            'roast' => "<b>🔥 روست</b>\n"
                . "با عکس پروفایل، اسم، بیو، یوزرنیم و حرفاش،\n"
                . "حسابی می‌شورمش 😈\n\n"
                . "• خودت: <code>نالینی منو روست کن</code>\n"
                . "• یه نفر دیگه: <code>نالینی اینو روست کن</code>\n"
                . "• با آیدی: <code>نالینی @username رو روست کن</code>\n"
                . "• تو هر چتی:\n" . $c('roast') . "\n" . $c('roast @username') . "\n\n"
                . "💡 هر جوری بگی می‌فهمم 😉\n"
                . "⏱ هر نفر دقیقه‌ای یه بار؛ فقط شوخیه، ناراحت نشی 😄",

            'nid' => "<b>🪪 کد ملی</b>\n"
                . "بررسی سالم بودن ساختار کد ملی\n"
                . "(رقم کنترل).\n\n"
                . "• " . $c('meli 0012345678') . "\n\n"
                . "⚠️ فقط ساختار رو چک می‌کنه، نه ثبت‌احوال رو.",

            'funstat' => "<b>🔍 استعلام کاربر</b>\n"
                . "آمار فعالیت یه کاربر تلگرام\n"
                . "با آیدی عددیش.\n\n"
                . "• " . $c('id 12345678'),

            'games' => "<b>🎮 بازی‌ها</b>\n"
                . "همه از منوی اصلی در دسترسن؛\n"
                . "<code>{$b}</code>\n"
                . "با ربات یا با دوستات:\n\n"
                . "♟️ شطرنج (۳ سطح سختی)\n"
                . "⭕ دوز · 🔴 دوز سکه‌ای\n"
                . "✊ سنگ‌کاغذقیچی · 🔢 دوئل ریاضی",

            'group' => "<b>👥 نالینی در گروه</b>\n"
                . "منو به گروهت اضافه کن و:\n\n"
                . "• اول یا جایی از پیامت «نالینی» رو بنویس\n"
                . "• منو تگ کن تا جواب بدم\n"
                . "• روی پیام‌های من ریپلای کن\n"
                . "• پست‌های کانال متصل رو خودکار جواب می‌دم\n\n"
                . "💡 اگه جواب ندادم،\n"
                . "منو ادمین گروه کن تا پیام‌ها به دستم برسه.",
            'aicheck' => "<b>🕵️ تشخیص عکس هوش مصنوعی</b>\n"
                . "بفهم یه عکس واقعیه یا با هوش مصنوعی ساخته شده.\n\n"
                . "• عکس رو بفرست و زیرش بنویس:\n<code>/isai</code>\n"
                . "• یا روی یه عکس ریپلای کن و بنویس:\n<code>/isai</code>\n"
                . "• یا عادی بپرس:\n<code>نالینی این عکس AI هست؟</code>\n\n"
                . "💡 تلگرام متادیتای عکس‌ها رو پاک می‌کنه؛\n"
                . "برای بررسی دقیق‌تر عکس رو به‌صورت «فایل»\n"
                . "(Send as File) بفرست.\n\n"
                . "⚠️ جواب فقط یه برآورده و ۱۰۰٪ قطعی نیست.",

            default => "<b>📘 راهنما</b>",
        };
    }

    private const HELP_LAYOUT = [
        1 => [['ai', 'image'], ['translate', 'search'], ['whisper'], ['font', 'download'], ['giveaway', 'wiki']],
        2 => [['weather', 'calc'], ['qr', 'love'], ['group'], ['roast', 'nid'], ['funstat', 'games'], ['aicheck']],
    ];

    public static function helpPage(string $key): int
    {
        foreach (self::HELP_LAYOUT[2] as $row) {
            if (in_array($key, $row, true)) {
                return 2;
            }
        }
        return 1;
    }

    public static function navButton(bool $next, string $data): array
    {
        return $next ? btn('➡️ صفحه‌ی بعد', $data, 'primary') : btn('صفحه‌ی قبل ⬅️', $data, 'primary');
    }

    public static function helpMenu(bool $private = true, int $page = 1): array
    {
        $page = $page === 2 ? 2 : 1;
        $rows = [];
        foreach (self::HELP_LAYOUT[$page] as $keys) {
            $row = [];
            foreach ($keys as $key) {
                if (isset(self::GUIDES[$key])) {
                    $row[] = btn(self::GUIDES[$key], "help:{$key}", null);
                }
            }
            $rows[] = $row;
        }
        $rows[] = [self::navButton($page === 1, $page === 1 ? 'help:p2' : 'help:p1')];
        $rows[] = [btn('🔙 برگشت', 'home', null)];
        return ikb($rows);
    }

    private const TRY_PREFIX = ['ai' => 'ai ', 'translate' => 'tr ', 'search' => 'g ', 'font' => 'ft ', 'whisper' => 'nj ', 'weather' => 'hv ', 'qr' => 'qr ', 'love' => 'love ', 'roast' => 'roast ',
        'nid' => 'meli ', 'funstat' => 'id ', 'giveaway' => 'gw ', 'wiki' => 'wk ', 'calc' => '2+2'];

    private static function tryButton(string $key): array
    {
        return ['text' => '💬 تو چت امتحانش کن', 'switch_inline_query_current_chat' => self::TRY_PREFIX[$key] ?? '', 'style' => 'primary'];
    }

    public static function tryKeyboard(string $key): array
    {
        return ikb([[self::tryButton($key)]]);
    }

    public static function guideKeyboard(string $key, bool $private): array
    {
        $try = self::tryButton($key);
        if ($private && $key === 'giveaway') {
            $try = btn('🎁 بساز', 'gw:new', 'primary');
        }
        return ikb([
            $key === 'aicheck' ? [] : [$try],
            [btn('🔙 راهنما', self::helpPage($key) === 2 ? 'help:p2' : 'help:menu', null)],
        ]);
    }

    public static function helpText(int $page = 1): string
    {
        return "<b>📘 راهنمای نالینی</b>\n<i>صفحه‌ی " . fa((string) $page) . " از ۲</i>\n\nکدوم بخش رو می‌خوای یاد بگیری؟ 👇";
    }
}
