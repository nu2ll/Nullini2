<?php

final class InlineHandler
{
    private const ROUTES = [
        'ai'      => ['ai', 'nullini', 'هوش', 'نالینی'],
        'tr'      => ['tr', 'tarjome', 'translate', 'ترجمه'],
        'whisper' => ['nj', 'najva', 'نجوا'],
        'weather' => ['hv', 'havaye', 'hava', 'weather', 'آب و هوای', 'آب و هوا', 'آب‌وهوای', 'آب‌وهوا', 'هوای', 'هوا'],
        'nid'     => ['codemeli', 'codmeli', 'meli', 'کدملی', 'کد‌ملی'],
        'funstat' => ['id', 'آیدی'],
        'love'    => ['love', 'عشق'],
        'roast'   => ['roast', 'روست', 'بشور'],
        'qr'      => ['qr', 'کیوآر'],
        'search'  => ['g', 'google', 'search', 'گوگل', 'سرچ', 'جستجو'],
        'font'    => ['ft', 'font', 'fonts', 'فونت'],
        'wiki'    => ['wk', 'wiki', 'ویکی‌پدیا', 'ویکیپدیا', 'ویکی'],
        'gw'      => ['gw', 'giveaway', 'قرعه‌کشی', 'قرعه کشی', 'قرعه'],
    ];

    private static array $iq = [];

    private static function send(array $params): void
    {
        App::tg()->call('answerInlineQuery', $params);
    }

    public static function handle(array $iq): void
    {
        self::$iq = $iq;
        $from     = $iq['from'];
        $query    = trim((string) ($iq['query'] ?? ''));

        Users::touch($from);
        if (Membership::missing((int) $from['id'])) {
            self::send([
                'inline_query_id' => $iq['id'],
                'results'         => [],
                'cache_time'      => 0,
                'is_personal'     => true,
                'button'          => ['text' => '🔒 اول عضو کانال نالینی شو', 'start_parameter' => 'join'],
            ]);
            return;
        }

        foreach (self::ROUTES as $route => $words) {
            foreach ($words as $w) {
                if (preg_match('/^' . preg_quote($w, '/') . '(?:\s+|$)(.*)$/isu', $query, $m)) {
                    $rest = trim($m[1]);
                    match ($route) {
                        'ai'      => self::ai($rest),
                        'tr'      => self::translate($rest),
                        'whisper' => self::whisper($rest),
                        'weather' => self::weather($rest),
                        'nid'     => self::nid($rest),
                        'funstat' => self::funstat($rest),
                        'love'    => self::love($rest),
                        'roast'   => self::roast($rest),
                        'qr'      => self::qr($rest),
                        'search'  => self::search($rest),
                        'font'    => self::font($rest),
                        'gw'      => self::giveaway($rest),
                        'wiki'    => self::wiki($rest),
                    };
                    return;
                }
            }
        }

        if (Calculator::looksLikeExpression($query)) {
            self::quickCalc($query);
            return;
        }
        self::menu($query);
    }

    private static function answer(array $results, int $cache = 0, bool $personal = true): void
    {
        self::send([
            'inline_query_id' => self::$iq['id'],
            'results'         => $results,
            'cache_time'      => $cache,
            'is_personal'     => $personal,
        ]);
    }

    private static function guide(string $key, string $icon, string $title, string $desc): void
    {
        self::answer([Ui::article("help_{$key}", $icon, $title, $desc, Ui::guide($key), Ui::tryKeyboard($key))]);
    }

    private static function fail(string $id, string $title, string $desc, string $text): void
    {
        self::answer([Ui::article($id, 'warn', $title, $desc, $text)]);
    }

    private static function deferred(string $kind, string $q): void
    {
        self::answer([self::deferredArticle($kind, $q)]);
    }

    private static function deferredArticle(string $kind, string $q): array
    {
        $token = substr(md5($kind . $q . self::$iq['from']['id'] . microtime()), 0, 12);
        Store::set('inline_ai', $token, ['kind' => $kind, 'q' => mb_substr($q, 0, 256), 'name' => userName(self::$iq['from']), 'from' => self::$iq['from']]);

        $isAi  = $kind === 'ai';
        $head  = $isAi ? '❓ <b>' . h(mb_substr($q, 0, 256)) . '</b>' : '📝 ' . h(mb_substr($q, 0, 256));
        $wait  = $isAi ? '🧠 نالینی داره فکر می‌کنه...' : '🌐 در حال ترجمه...';
        return Ui::article(
            "{$kind}q_{$token}",
            $isAi ? 'ai' : 'translate',
            $isAi ? '🧠 بپرس از نالینی' : '🌐 ترجمه کن',
            mb_substr($q, 0, 80),
            "{$head}\n\n⏳ {$wait}",
            ikb([[btn('⏳ صبر کن...', "{$kind}q:{$token}", null)]])
        );
    }

    private static function wiki(string $q): void
    {
        if ($q === '') {
            self::guide('wiki', 'wiki', '📖 ویکی‌پدیا', 'بعد از wk موضوعت رو بنویس');
            return;
        }
        $list = Wiki::search($q, 8);
        if (!$list && Wiki::lang($q) === 'fa') {
            $list = Wiki::search($q, 6, 'en');
        }
        if (!$list) {
            self::fail('wk_none', '📖 چیزی پیدا نشد', 'یه جور دیگه بنویس', '📖 تو ویکی‌پدیا چیزی درباره‌ی «' . h(mb_substr($q, 0, 80)) . '» پیدا نکردم.');
            return;
        }
        self::answer(array_map(static fn($p) => Wiki::inline('wk_' . substr(md5($p['url']), 0, 16), $p), $list), 600, false);
    }

    private static function giveaway(string $q): void
    {
        $r = $q === '' ? null : Giveaway::inlineResult(self::$iq['from'], $q);
        if ($r === null) {
            self::guide('giveaway', 'gift', '🎁 قرعه‌کشی', 'gw [تعداد برنده] [زمان] [جایزه]');
            return;
        }
        self::answer([$r]);
    }

    public static function resolveDeferred(string $token, string $imid): void
    {
        $job = Store::update('inline_ai', $token, static fn($j) => (is_array($j) && empty($j['done'])) ? ['done' => true] + $j : null);
        if ($job === null) {
            return;
        }
        $tg = App::tg();
        if ($job['kind'] === 'ai' && AI::busy((int) ($job['from']['id'] ?? 0))) {
            $tg->editInline($imid, AI::BUSY_TEXT, null, false);
            return;
        }
        if ($job['kind'] === 'ai' && is_array($job['from'] ?? null) && self::smart((string) $job['q'], (array) $job['from'], $imid)) {
            return;
        }
        $text = self::deferredText((string) $job['kind'], (string) $job['q'], (string) ($job['name'] ?? ''), $imid);
        if (empty($tg->editInline($imid, $text)['ok'])) {
            $tg->editInline($imid, strip_tags($text), null, false);
        }
    }

    private static function smart(string $q, array $from, string $imid): bool
    {
        if (($link = Downloader::match($q)) !== null) {
            Downloader::guest($from, $link, $imid);
            return true;
        }
        $ctx  = ['user_name' => userName($from), 'chat_type' => 'guest'];
        $live = App::cfg('ai_stream', true) ? new LiveReply(['chat' => ['id' => 0, 'type' => 'inline'], 'message_id' => 0], $imid, self::aiHead($q), false) : null;
        $opt  = ['reaction' => false] + ($live ? ['on_text' => $live->feed(...)] : []);
        $res  = AI::reply($q, $ctx, [], $opt);
        if (!empty($res['action'])) {
            [$act, $arg] = $res['action'];
            Users::hit('intent:' . $act);
            if ($act === 'download' && ($dl = Downloader::find($arg)) !== null) {
                Downloader::guest($from, $dl, $imid);
                return true;
            }
            $o = Intent::output(['from' => $from, 'chat' => ['type' => 'inline'], 'message_id' => 0], $act, $arg, true, $imid);
            if ($o !== null) {
                GuestHandler::show($imid, '', $o);
                return true;
            }
            $res = AI::reply($q, ['no_action' => true] + $ctx, [], $opt);
        }
        if (!empty($res['image'])) {
            GuestHandler::image($imid, null, (string) $res['image']);
            return true;
        }
        if ($res['answer'] === null) {
            return false;
        }
        $text = self::aiHead($q) . AI::toHtml(AI::fit($res['answer'], self::aiHead($q)));
        if (empty(App::tg()->editInline($imid, $text)['ok'])) {
            App::tg()->editInline($imid, strip_tags($text), null, false);
        }
        return true;
    }

    private static function aiHead(string $q): string
    {
        return '❓ <b>' . h(mb_substr($q, 0, 256)) . "</b>\n\n🧠 ";
    }

    private static function deferredText(string $kind, string $q, string $name, string $imid): string
    {
        if ($kind === 'ai') {
            $live   = App::cfg('ai_stream', true) ? new LiveReply(['chat' => ['id' => 0, 'type' => 'inline'], 'message_id' => 0], $imid, self::aiHead($q), false) : null;
            $res    = AI::reply($q, ['user_name' => $name, 'chat_type' => 'inline'], [], ['reaction' => false] + ($live ? ['on_text' => $live->feed(...)] : []));
            $answer = $res['answer'] ?? AI::FAIL_TEXT;
            $text   = self::aiHead($q) . AI::toHtml(AI::fit($answer, self::aiHead($q)));
        } else {
            $out  = AI::translateFast($q);
            $text = h(mb_substr($q, 0, 256)) . "\n\n🌐 " . ($out !== null ? h($out) : AI::FAIL_TEXT);
        }
        return $text;
    }

    private static function ai(string $q): void
    {
        if ($q === '') {
            self::guide('ai', 'ai', '🧠 هوش مصنوعی', 'بعد از ai سؤالت رو بنویس');
            return;
        }
        self::deferred('ai', $q);
    }

    private static function translate(string $q): void
    {
        if ($q === '') {
            self::guide('translate', 'translate', '🌐 ترجمه', 'بعد از tr متنت رو بنویس');
            return;
        }
        self::deferred('tr', $q);
    }

    private static function whisper(string $rest): void
    {
        if (!preg_match('/^(.+?)\s+(@[A-Za-z0-9_]{3,32}|\d{5,15})$/su', $rest, $m)) {
            self::guide('whisper', 'whisper', '🤫 نجوا', 'nj [متن] [@یوزرنیم] [آیدی]');
            return;
        }
        $text = trim($m[1]);
        $len  = mb_strlen($text);
        if ($len > Whisper::MAX_LEN) {
            self::fail('wh_long', '✋ متن نجوا طولانیه', 'حداکثر ' . fa(Whisper::MAX_LEN) . ' کاراکتر (الان ' . fa($len) . ')',
                '✋ متن نجوا حداکثر <b>' . fa(Whisper::MAX_LEN) . '</b> کاراکتر می‌تونه باشه؛' . "\n" . 'یه‌کم کوتاهش کن.');
            return;
        }
        $to    = UserInfo::resolve($m[2]);
        $token = Whisper::draft(self::$iq['from'], $text, $to);

        self::answer([Ui::article(
            "wh_{$token}",
            'whisper',
            '🤫 نجوا برای ' . (!$to['id'] && $to['username'] ? '@' . $to['username'] : $to['name']),
            '🔒 ' . mb_substr($text, 0, 50),
            Whisper::pendingText(['recipient_username' => $to['username'], 'recipient_name' => $to['name'], 'recipient_id' => $to['id']]),
            Whisper::pendingKeyboard($token)
        )]);
    }

    private static function weather(string $city): void
    {
        if ($city === '') {
            self::guide('weather', 'weather', '🌦 آب‌وهوا', 'بعد از hv اسم شهر رو بنویس');
            return;
        }
        $len = mb_strlen($city);
        if ($len < 2 || $len > 40) {
            self::fail('wt_len', '⚠️ اسم شهر نامعتبره', 'بین ۲ تا ۴۰ کاراکتر بنویس', '⚠️ اسم مکان باید بین ۲ تا ۴۰ کاراکتر باشه.');
            return;
        }
        $data = Weather::fetch($city);
        if ($data === null) {
            self::fail('wt_fail', '❌ پیدا نشد: ' . $city, 'اسم شهر رو دقیق‌تر بنویس', "❌ جایی به اسم «" . h($city) . "» پیدا نکردم.\n💡 اسم شهر رو دقیق‌تر بنویس.");
            return;
        }
        $hash = substr(md5(mb_strtolower($city)), 0, 16);
        Store::set('weather_pending', $hash, $city);
        $loc = $data['location'];
        self::answer([Ui::article(
            "wt_{$hash}",
            'weather',
            '🌦 ' . $loc['name'] . ' · ' . round((float) $data['current']['temp_c']) . '°',
            ($data['current']['condition']['text'] ?? '') . ' · ' . ($loc['country'] ?? ''),
            Weather::format($data),
            Weather::keyboard($hash)
        )], 60, false);
    }

    private static function nid(string $raw): void
    {
        if ($raw === '') {
            self::guide('nid', 'nid', '🪪 کد ملی', 'بعد از meli کد ملی رو بنویس');
            return;
        }
        $code = Tools::normalizeNid($raw);
        if ($code === null) {
            self::fail('nid_bad', '⚠️ فرمت کد ملی اشتباهه', 'باید ۸ تا ۱۰ رقم باشه', '⚠️ کد ملی باید فقط عدد و ۸ تا ۱۰ رقم باشه.');
            return;
        }
        $ok = Tools::isValidNid($code);
        self::answer([Ui::article("nid_{$code}", $ok ? 'ok' : 'fail', $ok ? '✅ کد ملی سالمه' : '❌ کد ملی نامعتبره', Tools::nidDisplay($code), Tools::nidText($code))], 300, false);
    }

    private static function funstat(string $raw): void
    {
        $raw = en($raw);
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 15) {
            self::guide('funstat', 'funstat', '🔍 استعلام کاربر', 'بعد از id آیدی عددی رو بنویس');
            return;
        }
        $data = UserInfo::funstat((int) $raw);
        if ($data === null) {
            self::fail('fs_fail', '❌ کاربری پیدا نشد', 'آیدی ' . $raw, "❌ اطلاعاتی برای آیدی <code>{$raw}</code> پیدا نشد.");
            return;
        }
        self::answer([Ui::article("fs_{$raw}", 'funstat', '🔍 ' . UserInfo::funstatShort($data), 'آیدی ' . $raw, UserInfo::funstatText($data))], 60, false);
    }

    private static function love(string $rest): void
    {
        $parts = $rest === '' ? [] : preg_split('/\s+/u', $rest);
        $tok   = '/^(@[A-Za-z0-9_]{3,32}|\d{5,15})$/';
        if (count($parts) !== 2 || !preg_match($tok, $parts[0]) || !preg_match($tok, $parts[1])) {
            self::guide('love', 'love', '💘 تطابق عشقی', 'love [یوزرنیم/آیدی] [یوزرنیم/آیدی۲]');
            return;
        }
        $r = Love::pending($parts[0], $parts[1]);
        self::answer([Ui::article(
            'lv_' . $r['token'],
            'love',
            "💘 {$r['a']} و {$r['b']}",
            '❤️ بزن تا درصد عشقشون رو ببینی',
            $r['text'],
            $r['keyboard']
        )]);
    }

    private static function roast(string $rest): void
    {
        $tok = Roast::token($rest);
        if ($rest !== '' && $tok === '') {
            self::guide('roast', 'roast', '🔥 روست', 'roast [@یوزرنیم] [آیدی]');
            return;
        }
        $r = Roast::pending(self::$iq['from'], $tok === 'me' ? '' : $tok);
        self::answer([Ui::article('rs_' . $r['token'], 'roast', $r['title'], '🔥 بزن تا بشورمش', $r['text'], $r['kb'])]);
    }

    private static function qr(string $text): void
    {
        if ($text === '') {
            self::guide('qr', 'qr', '🔳 QR کد', 'بعد از qr متن یا لینک رو بنویس');
            return;
        }
        $text = mb_substr($text, 0, 1000);
        self::answer([[
            'type'          => 'photo',
            'id'            => 'qr_' . substr(md5($text), 0, 16),
            'photo_url'     => Tools::qrUrl($text, 512),
            'thumbnail_url' => Tools::qrUrl($text, 128),
            'photo_width'   => 512,
            'photo_height'  => 512,
            'title'         => '🔳 QR کد',
            'description'   => mb_substr($text, 0, 60),
            'caption'       => '🔳 <code>' . h(mb_substr($text, 0, 1000)) . '</code>',
            'parse_mode'    => 'HTML',
        ]], 300, false);
    }

    private static function search(string $q): void
    {
        if ($q === '') {
            self::guide('search', 'search', '🔍 سرچ گوگل', 'بعد از g هر چی می‌خوای بنویس');
            return;
        }
        $q     = mb_substr($q, 0, 200);
        $items = [Ui::article(
            'gs_' . substr(md5($q), 0, 16),
            'search',
            '🔍 سرچ گوگل: ' . $q,
            'دکمه‌ها مستقیم به گوگل وصل می‌شن',
            "🔍 <b>" . h($q) . "</b>\n\n👇 نتایج رو همین‌جا تو تلگرام ببین",
            Search::keyboard($q)
        )];
        foreach (Search::results($q, 10) as $i => $r) {
            $a                  = Ui::article('sr_' . substr(md5($r['url']), 0, 16), 'search', $r['title'], $r['snippet'] !== '' ? $r['snippet'] : $r['url'], Search::resultText($r), ikb([[urlBtn('🌐 باز کردن', $r['url'], null)]]));
            $a['thumbnail_url'] = Search::favicon($r['url']);
            $a['url']           = $r['url'];
            $a['hide_url']      = true;
            $items[]            = $a;
        }
        $params = [
            'inline_query_id' => self::$iq['id'],
            'results'         => $items,
            'cache_time'      => 300,
            'is_personal'     => false,
        ];
        self::send($params);
    }

    private static function font(string $text): void
    {
        if ($text === '') {
            self::guide('font', 'font', '✨ فونت‌ساز', 'بعد از ft اسم یا متنت رو بنویس');
            return;
        }
        $items = [];
        foreach (array_slice(Fonts::all($text), 0, 50) as [$name, $styled]) {
            $items[] = Ui::article(
                'ft_' . substr(md5($styled), 0, 20),
                'font',
                mb_substr($styled, 0, 120),
                $name,
                h($styled),
                ikb([[['text' => '📋 کپی', 'copy_text' => ['text' => mb_substr($styled, 0, 256)]]]])
            );
        }
        if (!$items) {
            self::guide('font', 'font', '✨ فونت‌ساز', 'بعد از ft اسم یا متنت رو بنویس');
            return;
        }
        self::answer($items, 300, false);
    }

    private static function quickCalc(string $query): void
    {
        $expr   = Calculator::normalize($query);
        $result = Calculator::evaluate($expr);
        if ($result === Calculator::ERROR) {
            self::menu($query);
            return;
        }
        $pretty = strtr($expr, ['*' => '×', '/' => '÷']);
        self::answer([Ui::article(
            'qc_' . substr(md5($expr), 0, 16),
            'calc',
            "🧮 = {$result}",
            $pretty,
            "🧮 <code>" . h($pretty) . "</code>\n= <b>" . h($result) . '</b>'
        )], 300, false);
    }

    private static function menu(string $filter): void
    {
        $from = self::$iq['from'];
        [$infoText, $infoKb] = UserInfo::card($from, self::$iq['chat_type'] ?? null);

        $items = [
            Ui::article('info', 'info', '👤 اطلاعات من', 'آیدی، عمر اکانت، DC و...', $infoText, $infoKb),
            Ui::article('chess', 'chess', '♟️ شطرنج', 'با ربات در ۳ سطح سختی، یا با دوستت', Chess::intro(), Chess::modeKeyboard()),
            Ui::article('calc', 'calc', '🧮 ماشین‌حساب', 'با پرانتز و توان', Calculator::render('0'), Calculator::keyboard()),
            Ui::article('rates', 'rates', '💱 نرخ ارز و طلا', 'دلار، یورو، سکه، تتر، بیت‌کوین...', "<b>💱 نرخ لحظه‌ای بازار</b>\n\nبرای دیدن قیمت‌ها دکمه‌ی زیر رو بزن 👇", Currency::pendingKeyboard()),
            Ui::article('date', 'date', '📅 تاریخ و ساعت', 'شمسی و میلادی، به وقت ایران', Tools::dateText()),
            Ui::article('pass', 'pass', '🔑 پسورد ساز', 'یه رمز قوی و غیرقابل حدس', Tools::passwordText(16), Tools::passwordKeyboard()),
            Ui::article('ttt', 'ttt', '⭕ دوز', 'دوز کلاسیک، با ربات یا دوستت', TicTacToe::intro(), TicTacToe::modeKeyboard()),
            Ui::article('c4', 'c4', '🔴 دوز سکه‌ای', '۴ تا پشت سر هم، با ربات یا دوستت', ConnectFour::intro(), ConnectFour::modeKeyboard()),
            Ui::article('rps', 'rps', '✊ سنگ کاغذ قیچی', 'بهترین از ۳ دور', Rps::intro(), Rps::modeKeyboard()),
            Ui::article('math', 'math', '🔢 دوئل ریاضی', 'مسابقه‌ی سرعت حساب، چندنفره', MathDuel::intro(), MathDuel::modeKeyboard()),
            Ui::article('coin', 'coin', '🪙 سکه', 'شیر یا خط؟', "<b>🪙 سکه</b>\n\nآماده‌ی پرتاب! 👇", Tools::coinKeyboard()),
            Ui::article('fal', 'fal', '🔮 فال حافظ', 'یه فال از دیوان حافظ', Tools::hafezCaption(), Tools::hafezKeyboard()),
            Ui::article('help_group', 'group', '👥 در گروه', 'نالینی رو به گروهت اضافه کن', Ui::guide('group'), Ui::tryKeyboard('group')),
            Ui::article('help_image', 'image', '🎨 ساخت عکس', 'فقط بگو چی بکشم؛ تو پیوی یا گروه', Ui::guide('image'), Ui::tryKeyboard('image')),
            Ui::article('help_whisper', 'whisper', '🤫 نجوا', 'nj [متن] [@یوزرنیم] [آیدی]', Ui::guide('whisper'), Ui::tryKeyboard('whisper')),
            Ui::article('help_giveaway', 'gift', '🎁 قرعه‌کشی', 'gw [تعداد] [زمان] [جایزه]', Ui::guide('giveaway'), Ui::tryKeyboard('giveaway')),
            Ui::article('help_wiki', 'wiki', '📖 ویکی‌پدیا', 'wk [موضوع]', Ui::guide('wiki'), Ui::tryKeyboard('wiki')),
            Ui::article('help_ai', 'ai', '🧠 هوش مصنوعی', 'ai [سؤالت]', Ui::guide('ai'), Ui::tryKeyboard('ai')),
            Ui::article('help_translate', 'translate', '🌐 ترجمه', 'tr [متن]', Ui::guide('translate'), Ui::tryKeyboard('translate')),
            Ui::article('help_search', 'search', '🔍 سرچ گوگل', 'g [هر چی بخوای]', Ui::guide('search'), Ui::tryKeyboard('search')),
            Ui::article('help_font', 'font', '✨ فونت‌ساز', 'ft [اسم یا متن]', Ui::guide('font'), Ui::tryKeyboard('font')),
            Ui::article('help_weather', 'weather', '🌦 آب‌وهوا', 'hv [شهر]', Ui::guide('weather'), Ui::tryKeyboard('weather')),
            Ui::article('help_qr', 'qr', '🔳 QR کد', 'qr [متن یا لینک]', Ui::guide('qr'), Ui::tryKeyboard('qr')),
            Ui::article('help_roast', 'roast', '🔥 روست', 'roast [@یوزرنیم] [آیدی]', Ui::guide('roast'), Ui::tryKeyboard('roast')),
            Ui::article('help_love', 'love', '💘 تطابق عشقی', 'love [یوزرنیم/آیدی] [یوزرنیم/آیدی۲]', Ui::guide('love'), Ui::tryKeyboard('love')),
            Ui::article('help_nid', 'nid', '🪪 کد ملی', 'meli [کد ملی]', Ui::guide('nid'), Ui::tryKeyboard('nid')),
            Ui::article('help_funstat', 'funstat', '🔍 استعلام کاربر', 'id [آیدی عددی]', Ui::guide('funstat'), Ui::tryKeyboard('funstat')),
        ];

        if ($filter !== '') {
            $hits = array_values(array_filter($items, static fn($r) => mb_stripos($r['title'] . ' ' . $r['description'], $filter) !== false));
            if ($hits) {
                $items = $hits;
            }
            if (mb_strlen($filter) >= 2) {
                array_unshift($items, self::deferredArticle('ai', $filter));
            }
        }

        self::send([
            'inline_query_id' => self::$iq['id'],
            'results'         => $items,
            'cache_time'      => 0,
            'is_personal'     => true,
        ]);
    }
}
