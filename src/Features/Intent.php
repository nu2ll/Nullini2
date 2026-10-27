<?php

final class Intent
{
    public const NAMES = ['translate', 'download', 'wiki', 'search', 'weather', 'rates', 'font', 'qr', 'fal', 'password', 'date', 'coin', 'calc', 'nid', 'love', 'roast', 'userinfo', 'giveaway'];

    public static function prompt(): string
    {
        return 'تو به ابزارهای نالینی دسترسی داری. منظور کاربر رو از کل پیامش بفهم، نه از کلمه‌ها. اگه واقعاً یکی از این کارها رو ازت خواست، به‌جای جواب فقط یک خط بنویس که تماماً داخل دو کروشه‌ی دوبل باشه: «[[<اسم> | <ورودی>]]» و هیچ چیز دیگه‌ای قبل یا بعدش ننویس:'
            . "\n- translate | <زبان مقصد به انگلیسی، یا auto> | <متنی که باید ترجمه بشه؛ اگه منظورش پیامیه که روش ریپلای کرده خالی بذار>: ترجمه."
            . "\n- wiki | <موضوع>: خلاصه‌ی دانشنامه‌ای از ویکی‌پدیا."
            . "\n- weather | <شهر>: وضعیت فعلی یا پیش‌بینی آب‌وهوا."
            . "\n- rates: قیمت لحظه‌ای ارز، طلا، سکه یا کریپتو."
            . "\n- font | <متن>: نوشتن متن با فونت‌های فانتزی."
            . "\n- qr | <متن یا لینک>: ساخت QR کد."
            . "\n- fal: فال حافظ."
            . "\n- password: ساخت رمز قوی."
            . "\n- date: تاریخ یا ساعت الان."
            . "\n- coin: شیر یا خط."
            . "\n- giveaway: ساختن قرعه‌کشی."
            . "\n- download | <لینک کامل؛ اگه لینک تو پیامیه که روش ریپلای کرده خالی بذار>: دانلود ویدیو، عکس یا آهنگ یه لینک."
            . "\n- calc | <عبارت ریاضی با + - * / ^ و پرانتز>: حساب عددی."
            . "\n- nid | <کد ملی>: بررسی درستی کد ملی."
            . "\n- love | <نفر اول> | <نفر دوم>: درصد عشق دو نفر."
            . "\n- roast | <me یا reply یا @یوزرنیم یا آیدی عددی>: روست کردن یه آدم؛ me یعنی خود کاربر، reply یعنی صاحب پیامی که روش ریپلای کرده. فقط برای یه روست جدید؛ نظر دادن درباره‌ی روست قبلی درخواست روست نیست."
            . "\n- userinfo: اطلاعات اکانت تلگرام خود کاربر."
            . "\nاگه فقط درباره‌ی این موضوعات حرف زد یا سؤال کلی پرسید و انجام خود کار رو نخواست، خودت جواب بده و دستور «[[ ]]» ننویس.";
    }

    public static function parse(string $text): ?array
    {
        $text = trim(str_replace(['**', '`', '«', '»'], '', $text));
        if (!preg_match('/^\s*\[\[\s*(' . implode('|', self::NAMES) . ')\s*(?:[|｜:：]\s*(.*?))?\s*\]\]/isu', $text, $m)) {
            return null;
        }
        $name = strtolower($m[1]);
        $arg = (string) ($m[2] ?? '');
        if ($name !== 'translate') {
            $arg = (string) strtok($arg . "\n", "\n");
        }
        return [$name, mb_substr(mbTrim($arg, " \t\n\"'«»<>"), 0, $name === 'translate' ? 3000 : 300)];
    }

    public static function run(array $msg, string $name, string $arg): bool
    {
        $tg      = App::tg();
        $chat    = $msg['chat']['id'];
        $private = ($msg['chat']['type'] ?? '') === 'private';
        if (!Switches::on($name)) {
            return true;
        }
        Users::hit('intent:' . $name);

        if ($name === 'download') {
            $link = Downloader::match($arg) ?? Downloader::find($arg . ' ' . (string) ($msg['reply_to_message']['text'] ?? $msg['reply_to_message']['caption'] ?? ''));
            if ($link === null) {
                $tg->reply($msg, "🤷 این لینک رو نمی‌شناسم؛\n" . Downloader::sitesText());
                return true;
            }
            Downloader::offer($msg, $link);
            return true;
        }
        if ($name === 'giveaway') {
            $private ? Giveaway::start($msg) : Giveaway::fromGroup($msg);
            return true;
        }
        if (in_array($name, ['translate', 'rates', 'weather', 'wiki', 'search', 'love', 'roast'], true)) {
            $tg->action($chat, 'typing');
        }

        $o = self::output($msg, $name, $arg);
        if ($o === null) {
            return false;
        }
        if (!empty($o['live']) && $o['live']->finishHtml($o['text'])) {
            return true;
        }
        $reply = ['message_id' => $msg['message_id'], 'allow_sending_without_reply' => true];
        if (!empty($o['photo']) || !empty($o['anim'])) {
            $r = $tg->call(!empty($o['photo']) ? 'sendPhoto' : 'sendAnimation', [
                'chat_id' => $chat, !empty($o['photo']) ? 'photo' : 'animation' => $o['photo'] ?? $o['anim'],
                'caption' => $o['text'], 'parse_mode' => 'HTML', 'reply_markup' => $o['kb'] ?? null, 'reply_parameters' => $reply,
            ], 30);
            return !empty($r['ok']);
        }
        $tg->reply($msg, $o['text'], array_filter([
            'reply_markup'         => $o['kb'] ?? null,
            'link_preview_options' => $o['preview'] ?? null,
        ]));
        foreach ($o['more'] ?? [] as $part) {
            $tg->reply($msg, $part);
        }
        return true;
    }

    public static function output(array $msg, string $name, string $arg, bool $guest = false, ?string $imid = null): ?array
    {
        $bot   = App::botUsername();
        $reply = (string) ($msg['reply_to_message']['text'] ?? $msg['reply_to_message']['caption'] ?? '');

        switch ($name) {
            case 'translate':
                [$to, $text] = array_map('trim', explode('|', $arg, 2)) + [1 => ''];
                if ($text === '' && $reply === '' && !preg_match('/^[A-Za-z ]{0,20}$/', $to)) {
                    [$to, $text] = ['auto', $to];
                }
                $source = $text !== '' ? $text : trim($reply);
                if ($source === '') {
                    return ['text' => Ui::guide('translate')];
                }
                $target = preg_match('/^[A-Za-z ]{2,20}$/', $to) && strtolower($to) !== 'auto' ? ucfirst(strtolower($to)) : 'auto';
                $out    = AI::translate($source, $target);
                return ['text' => $out ? "🌐 <b>ترجمه:</b>\n\n" . h($out) : AI::FAIL_TEXT];

            case 'wiki':
                if ($arg === '') {
                    return null;
                }
                $p = Wiki::first($arg);
                if ($p === null) {
                    return ['text' => '📖 تو ویکی‌پدیا چیزی درباره‌ی «' . h(mb_substr($arg, 0, 80)) . '» پیدا نکردم.'];
                }
                Users::hit('wiki');
                return ['text' => Wiki::text($p), 'kb' => Wiki::keyboard($p), 'preview' => ['url' => $p['url'], 'prefer_small_media' => true]];

            case 'weather':
                $d = $arg !== '' ? Weather::fetch($arg) : null;
                if ($d === null) {
                    return ['text' => $arg === '' ? '🌦 اسم شهر رو هم بگو؛' . "\n" . 'مثلاً «هوای تهران چطوره؟»' : '❌ جایی به اسم «' . h($arg) . '» پیدا نکردم.'];
                }
                return ['text' => Weather::format($d)];

            case 'rates':
                return ['text' => Currency::message()];

            case 'font':
                return $arg === '' ? null : ['text' => Fonts::listText($arg)];

            case 'qr':
                return $arg === '' ? null : ['photo' => Tools::qrUrl($arg, 512), 'text' => '🔳 <code>' . h(mb_substr($arg, 0, 1000)) . '</code>'];

            case 'fal':
                return ['anim' => Tools::hafezUrl(), 'text' => Tools::hafezCaption()];

            case 'password':
                return ['text' => Tools::passwordText(16)];

            case 'date':
                return ['text' => Tools::dateText()];

            case 'coin':
                return ['text' => Tools::coinResult()];

            case 'calc':
                if ($arg === '' || !Calculator::looksLikeExpression($arg)) {
                    return null;
                }
                $res = Calculator::evaluate($arg);
                return ['text' => '🧮 <code>' . h(Calculator::normalize($arg)) . '</code>' . "\n= <b>" . h($res) . '</b>'];

            case 'nid':
                $code = Tools::normalizeNid($arg);
                return $code === null ? ['text' => '🪪 یه کد ملی ۱۰ رقمی بفرست تا چکش کنم.'] : ['text' => Tools::nidText($code)];

            case 'search':
                if (mb_strlen($arg) < 2) {
                    return null;
                }
                $list  = Search::results($arg, 10);
                $lines = [];
                foreach ($list as $i => $r) {
                    $lines[] = fa((string) ($i + 1)) . '. <a href="' . h($r['url']) . '">' . h(mb_substr($r['title'], 0, 90)) . '</a>'
                        . ($r['snippet'] !== '' ? "\n<i>" . h(mb_substr($r['snippet'], 0, 160)) . '</i>' : '');
                }
                Users::hit('search');
                return ['text' => '🔍 <b>' . h(mb_substr($arg, 0, 120)) . "</b>\n\n" . ($lines ? implode("\n\n", $lines) : 'نتیجه‌ی آماده‌ای پیدا نکردم؛ از دکمه‌ها برو تو گوگل 👇'), 'kb' => Search::keyboard($arg)];

            case 'love':
                [$a, $b] = array_map('trim', explode('|', $arg, 2)) + [1 => ''];
                if ($a === '' || $b === '') {
                    return ['text' => "💘 اسم دو نفر رو بگو؛\nمثلاً «درصد عشق علی و سارا چنده؟»"];
                }
                $ra = UserInfo::resolve($a);
                $rb = UserInfo::resolve($b);
                $r  = Love::build(mb_substr($ra['name'], 0, Love::NAME_MAX), mb_substr($rb['name'], 0, Love::NAME_MAX), $ra, $rb);
                return ['text' => $r['text'], 'kb' => $r['keyboard']];

            case 'roast':
                $t = Roast::target($msg, $arg);
                if (!empty($t['skip'])) {
                    return null;
                }
                if ($t !== null && Roast::busy((int) ($msg['from']['id'] ?? 0))) {
                    return ['text' => "یه‌کم دیگه بیا 😅\n⏳ هر دقیقه فقط یه روست!"];
                }
                if ($t === null) {
                    return ['text' => "🤷 این آدم رو پیدا نکردم؛\nآیدی عددی یا یوزرنیمش رو درست بنویس."];
                }
                $live = App::cfg('ai_stream', true) ? new LiveReply($msg, $imid, Roast::head($t), false) : null;
                return ['text' => Roast::text($t, $live), 'live' => $live];

            case 'userinfo':
                [$text, $kb] = UserInfo::card($msg['from'], $guest ? null : ($msg['chat']['type'] ?? null));
                return ['text' => $text, 'kb' => $kb];

            case 'giveaway':
                return $guest ? ['text' => "🎁 قرعه‌کشی رو تو پیوی من بساز 👇", 'kb' => ikb([[urlBtn('🎁 ساخت قرعه‌کشی', "https://t.me/{$bot}?start=giveaway", 'success')]])] : null;
        }
        return null;
    }
}
