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
            'آماده‌ی خدمتم 🫡 بفرمایید',
            'بله بله؟ 😃 چی شده؟',
            'من اینجام، تو بگو 🙌',
            'جانم عزیز؟ 😍 چی می‌خوای؟',
            'هوی! 👋 صدام کردی؟',
            'بفرما، منتظرم ⏳',
            'سلام! 😄 بگو ببینم چه خبره',
            'نالینی در دسترسه ✅ بپرس',
        ];
        return $lines[array_rand($lines)] . "\n<i>یا از منو یکی رو انتخاب کن 👇</i>";
    }

    private const DICE_LINES = [
        '🎲' => [
            'one' => [
                'یه تک اومد 😂 بدشانسی محضه',
                'ای وای ۱! 🥲 تاس باهات قهره',
                'تک؟ 😬 فکر کنم تاس امروز خوابه',
                'نمی‌خوای بدونی چی اومد... یه ۱ 😅',
                'یک اومد 💀 دفعه‌ی بعد جبران می‌کنی',
            ],
            'low' => [
                'عدد کم اومد 😕 ولی ناامید نشو',
                'تاس امروز دست و دل‌باز نیست 😅',
                'نه بد، نه خوب 🫤 یه بار دیگه؟',
                'عدد کمیه 🤏 دوباره بنداز',
                'شانس امروز یه کم کمه 😬 ولی ادامه بده',
            ],
            'high' => [
                'عدد خوبیه 😎 داری شانس میاری',
                'بد نیست! 👌 یه ذره مونده به ۶',
                'نزدیک شش بود 😏 ولی همین هم خوبه',
                'عدد بالایی اومد 🔥 دمت گرم',
                'تاس بهت لبخند زد 😁 عدد خوبیه',
            ],
            'six' => [
                'شش اومد! 🔥 به‌به شانس',
                '۶ شد 😎 تاس‌باز حرفه‌ای رسید',
                'جک‌پات! شش آورد 🎉',
                'تاس بهت ارادت داره 🙌 شش اومد',
                '۶ شد 😏 دلم گواهی خوب می‌داد',
            ],
        ],
        '🎯' => [
            'miss' => [
                'خطا رفت 😂 اصلاً به تخته نخورد',
                'تیر پرید تو هوا 🙈 هدف سالم موند',
                'نخورد به تخته 😅 دیوار شاکی شد',
                'دارتت گم شد 😬 چشمات رو باز کن',
                'هدف اون‌ور بود قهرمان 😅',
                'یه خطای تمیز 💀 دفعه‌ی بعد دقیق‌تر',
            ],
            'hit' => [
                'خورد به تخته 👌 ولی وسط نیست',
                'نزدیک وسط نشست 😏 یه کم دیگه',
                'بد نزدی، دارت نشست 🎯',
                'به هدف خورد ولی نه وسطش 🤏',
                'دستت درست کار می‌کنه 😎 یه ذره مونده تا وسط',
                'یه دارت معمولی؛ نه خطا، نه عالی 😌',
                'تو حلقه‌ها نشست 🎯 بد نبود',
                'نشست رو تخته 👍 ولی وسط رو خالی گذاشتی',
            ],
            'top' => [
                'وسط هدف! 🎯 دمت گرم',
                'دقیقاً وسط نشست 🔥 تیرانداز حرفه‌ای',
                'شش! 🎯 همون وسطِ وسط',
                'جون! 😍 دقیق زدی به هدف',
                'تو ارباب دارتی؟ 😎 وسط نشست',
                'هدف‌گیری عالی! 🏆 اینو بگیر',
            ],
        ],
        '🏀' => [
            'miss' => [
                'توپ نرفت تو حلقه 😅 خورد به لبه',
                'خورد به حلقه و برگشت 😬',
                'پرتابت کم‌دقت بود 🙈 نرفت تو',
                'حلقه جا خالی داد؛ نرفت 😂',
                'ای بابا! 🏀 نشد',
                'توپ دور حلقه چرخید و بیرون افتاد 😮‍💨',
                'یه پرتاب خیلی نزدیک بود... ولی نشد 🫠',
                'امتیاز نداشت 😕 دفعه‌ی بعد',
                'حلقه امروز نمی‌خواد توپ بگیره 🙅',
                'نخورد به سبد 😅 یه بار دیگه بزن',
            ],
            'win' => [
                'رفت تو! 🏀 سه‌امتیازی',
                'سوییش تمیز 🔥 رفت تو حلقه',
                'امتیاز! 😎 توپ نشست تو سبد',
                'پرتاب عالی بود 👏 رفت تو',
                'اینم از امتیاز 🏆 دمت گرم',
                'توپ بی‌رحمانه رفت تو حلقه 😏',
                'شوت حرفه‌ای! 🔥 امتیاز مال توئه',
                'مستقیم تو سبد نشست 🙌',
                'ستاره‌ی NBA رسید 😎 رفت تو',
                'حلقه رو ترکوندی 💥 امتیاز',
            ],
        ],
        '⚽' => [
            'miss' => [
                'دروازه‌بان گرفت 🧤 گل نشد',
                'توپ رفت بیرون 😬 اوه اوه',
                'خورد به تیرک و برگشت 😭',
                'گل نشد 😂 دروازه‌بان خندید',
                'شوتت بالای دروازه رفت 🙈',
                'پنالتی از دست رفت 😮‍💨',
                'توپ کج رفت ⚽ ببخشید ولی گل نشد',
                'دروازه‌بان آماده بود 🧤 نشد',
            ],
            'win' => [
                'گل! ⚽ دروازه ترکید',
                'گل شد 🔥 دمت گرم',
                'گوشه‌ی دروازه نشست 😎 گل',
                'دروازه‌بان رو گیج کردی 😏 گل شد',
                'تماشاگرا از جا پریدن 🙌 گل',
                'یه گل تمیز و بی‌رحم 🥅',
                'رفت تو تور! ⚽ مبارکه',
                'پنالتی‌زن حرفه‌ای رسید 🏆 گل شد',
                'گل گل گل! 🎉',
                'دروازه‌بان فقط نگاه کرد 😂 گل شد',
                'مستقیم رفت تو دروازه ⚽🔥',
                'رونالدو هم بهت غبطه می‌خوره 😎 گل',
            ],
        ],
        '🎳' => [
            'miss' => [
                'توپ افتاد تو ناودونی 😂 هیچی نیفتاد',
                'ای وای ناودونی! 😭 هیچ پینی نیفتاد',
                'توپ راه رو گم کرد 🙈 صفر پین',
                'خطا شد 💀 پین‌ها راحت خوابیدن',
                'توپ از کنار پین‌ها رد شد 😅',
                'یه صفر تمیز 😬',
            ],
            'hit' => [
                'چندتا پین افتاد 🎳 ولی همه نه',
                'نزدیک بود استرایک بشه 😏 چندتا مونده',
                'بد نیست 👌 چندتا پین ایستاده موند',
                'نصف پین‌ها نفله شدن 😄 بقیه جون سالم به در بردن',
                'توپ خوب رفت ولی کامل نبود 🎳',
                'یه ضربه‌ی معمولی؛ نه ناودونی، نه استرایک 😌',
                'پین‌ها لرزیدن ولی همه نیفتادن 😬',
                'نصفه‌نیمه زدی 🤏 دوباره امتحان کن',
            ],
            'top' => [
                'استرایک! 🎳 همه‌ی پین‌ها افتادن',
                'همه رو خوابوندی 😎 استرایک',
                'پین‌ها قتل‌عام شدن 💥 استرایک!',
                'یه استرایک تمیز 🔥 دمت گرم',
                'بولینگ‌باز حرفه‌ای رسید 🏆 همه افتادن',
                'یه ضربه، ده پین 😏 استرایک',
            ],
        ],
        '🎰' => [
            'jackpot' => [
                'جک‌پات! 🎰 سه تا ۷ اومد',
                '۷ ۷ ۷ 😱 ترکوندی',
                'سه تا هفت؟! 🤑 برو لاتاری بخر',
                'دستگاه رو بردی 🎉 جک‌پات',
                'باورم نمی‌شه 🙌 سه تا هفت اومد',
            ],
            'triple' => [
                'سه تا مثل هم اومد 🎉 بردی',
                'قرقره‌ها هماهنگ شدن 😎 برنده‌ای',
                'سه تا یکی! 🍀 شانس بهت رو کرد',
                'برد خوردی 🔥 دمت گرم',
                'سه تا یکسان؛ اسلات تسلیم شد 🏆',
            ],
            'pair' => [
                'دوتا مثل هم اومد 😏 یه ذره مونده',
                'نزدیک بود جک‌پات بشه 😭 دو تا یکی',
                'یه قرقره خراب کرد 😅 دو تا مثل هم',
                'خیلی نزدیک بود 🤏 دوباره بچرخون',
                'دو تا اومد، سومی نیومد 😬',
            ],
            'lose' => [
                'هیچ‌کدوم مثل هم نبودن 😂 باختی',
                'اسلات امروز پولت رو می‌خوره 😅 نبردی',
                'قرقره‌ها هر کدوم یه طرف 🙈 باخت',
                'بدشانسی 💀 هیچی نیومد',
                'نشد که نشد 🎰 دوباره بچرخون',
            ],
        ],
    ];

    private static function diceBucket(string $emoji, int $value): string
    {
        switch ($emoji) {
            case '🎯':
                return $value <= 1 ? 'miss' : ($value >= 6 ? 'top' : 'hit');
            case '🏀':
                return $value >= 4 ? 'win' : 'miss';
            case '⚽':
                return $value >= 3 ? 'win' : 'miss';
            case '🎳':
                return $value >= 6 ? 'top' : ($value <= 1 ? 'miss' : 'hit');
            case '🎰':
                if ($value === 64) {
                    return 'jackpot';
                }
                $l = ($value - 1) % 4;
                $m = intdiv($value - 1, 4) % 4;
                $r = intdiv($value - 1, 16);
                return $l === $m && $m === $r ? 'triple' : ($l === $m || $m === $r || $l === $r ? 'pair' : 'lose');
            default:
                return $value <= 1 ? 'one' : ($value <= 3 ? 'low' : ($value <= 5 ? 'high' : 'six'));
        }
    }

    public static function diceHello(string $emoji, int $value): string
    {
        $emoji = str_replace("\u{FE0F}", '', $emoji);
        if (!isset(self::DICE_LINES[$emoji])) {
            $emoji = '🎲';
        }
        $lines = self::DICE_LINES[$emoji][self::diceBucket($emoji, $value)];
        return $lines[array_rand($lines)];
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
        ];
        foreach ([['img', '🎨 ساخت عکس'], ['tr', '🌐 ترجمه'], ['ft', '✨ فونت‌ساز'], ['gw', '🎁 قرعه‌کشی'], ['wk', '📖 ویکی‌پدیا']] as [$cmd, $desc]) {
            if (Switches::on(Switches::COMMAND_SWITCH[$cmd])) {
                $base[] = $c($cmd, $desc);
            }
        }
        if ($scope === 'admin') {
            return array_merge($base, [
                $c('stats', '📊 آمار ربات'),
                $c('ai', '🤖 پنل هوش مصنوعی'),
                $c('quota', '🔋 سهمیه‌ی هوش مصنوعی'),
                $c('backup', '💾 بکاپ دستی دیتابیس'),
                $c('broadcast', '📣 پیام همگانی (روی پیام ریپلای کن)'),
                $c('tools', '🧰 روشن/خاموش کردن ابزارها'),
                $c('ban', '⛔ بن کاربر (ریپلای یا آیدی)'),
                $c('unban', '✅ آنبن کاربر (ریپلای یا آیدی)'),
                $c('banned', '📋 لیست بن‌شده‌ها'),
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
            default => "<b>📘 راهنما</b>",
        };
    }

    private const HELP_LAYOUT = [
        1 => [['ai', 'image'], ['translate', 'search'], ['whisper'], ['font', 'download'], ['giveaway', 'wiki']],
        2 => [['weather', 'calc'], ['qr', 'love'], ['group'], ['roast', 'nid'], ['funstat', 'games']],
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
            [$try],
            [btn('🔙 راهنما', self::helpPage($key) === 2 ? 'help:p2' : 'help:menu', null)],
        ]);
    }

    public static function helpText(int $page = 1): string
    {
        return "<b>📘 راهنمای نالینی</b>\n<i>صفحه‌ی " . fa((string) $page) . " از ۲</i>\n\nکدوم بخش رو می‌خوای یاد بگیری؟ 👇";
    }
}
