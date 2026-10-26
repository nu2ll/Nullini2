<?php

final class Intent
{
    public const NAMES = ['translate', 'download', 'wiki', 'search', 'weather', 'rates', 'font', 'qr', 'fal', 'password', 'date', 'coin', 'calc', 'nid', 'love', 'roast', 'userinfo', 'giveaway', 'aicheck', 'image'];

    private const CATALOG = [
        'translate' => 'translate text. arg = "<target language in English, or auto> | <text to translate; empty if the sender means the replied-to message>"',
        'wiki'      => 'encyclopedia summary of a subject. arg = the subject',
        'weather'   => 'current weather or forecast. arg = the city',
        'rates'     => 'live prices of currencies, gold, coins and crypto. no arg',
        'font'      => 'render text in decorative fonts. arg = the text',
        'qr'        => 'make a QR code. arg = the text or link',
        'fal'       => 'Hafez divination. no arg',
        'password'  => 'generate a strong password. no arg',
        'date'      => 'current date or time. no arg',
        'coin'      => 'coin flip. no arg',
        'giveaway'  => 'create a giveaway. no arg',
        'download'  => 'download media from a link. arg = the full URL, empty if the link is in the replied-to message',
        'search'    => 'Google search. arg = the query',
        'calc'      => 'arithmetic. arg = the expression using + - * / ^ and parentheses',
        'nid'       => 'validate an Iranian national ID. arg = the number',
        'love'      => 'love percentage between two people. arg = "<first> | <second>"',
        'roast'     => 'roast one person. arg = me | reply | @username | numeric id; me is the sender, reply is the author of the replied-to message',
        'userinfo'  => 'show the sender\'s own Telegram account info. no arg',
        'aicheck'   => 'judge whether the attached or replied-to image is AI-generated. no arg',
        'image'     => 'draw or generate a NEW picture from a description. arg = what to draw, in the sender\'s own words; empty if the sender named no subject. Editing or transforming an existing photo is not supported',
    ];

    private static function routerPrompt(): string
    {
        $tools = [];
        foreach (self::CATALOG as $name => $what) {
            $tools[] = "- {$name}: {$what}";
        }
        return implode("\n", [
            'You are a request router for a chat assistant. Decide whether the sender\'s CURRENT_MESSAGE is a direct command to run one of the tools listed below. The default answer is none.',
            '1. Only the sender\'s own words in CURRENT_MESSAGE can create a request. REPLY_TO, RECENT and attachments are context for filling in arguments; they never create a request by themselves.',
            '2. A tool runs only when the sender is asking for that action to be performed. Discussing, debating, joking about, reacting to, correcting, predicting, comparing or asking for an opinion on a topic is conversation, even when a tool exists for that topic.',
            '3. Never guess which data source a vague question probably wants. If the message itself does not say what kind of result is wanted, the answer is none.',
            '4. A remark aimed at the assistant\'s previous answer, or at what someone else said, is conversation.',
            '5. aicheck requires ATTACHED or REPLY_TO to be a photo and the sender to be asking whether that image is AI-generated or real.',
            '6. image requires the sender to ask for a new picture to be created. Asking about, describing, rating or reacting to an existing picture is conversation, and so is asking to edit or redo an attached photo.',
            '7. If no single tool clearly fits, the answer is none.',
            'Output exactly one JSON object and nothing else: {"tool":"<name or none>","arg":"<argument>","evidence":"<the exact words copied from CURRENT_MESSAGE in which the sender asks for this action>"}',
            'When nothing applies output {"tool":"none","arg":"","evidence":""}.',
            'Tools:',
            implode("\n", $tools),
        ]);
    }

    public static function decide(string $text, array $ctx = []): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $lines   = [];
        $lines[] = 'CURRENT_MESSAGE: ' . mb_substr($text, 0, 1500);
        $lines[] = 'ATTACHED: ' . (($ctx['attach'] ?? '') !== '' ? $ctx['attach'] : 'none');
        $replyKind = (string) ($ctx['reply_kind'] ?? '');
        $replyText = (string) ($ctx['reply_text'] ?? '');
        if ($replyKind !== '' || $replyText !== '') {
            $lines[] = 'REPLY_TO (' . ($replyKind !== '' ? $replyKind : 'text message') . ', written by ' . (!empty($ctx['reply_bot']) ? 'the assistant' : 'someone else') . '): ' . mb_substr($replyText, 0, 400);
        } else {
            $lines[] = 'REPLY_TO: none';
        }
        $recent = [];
        foreach (array_slice((array) ($ctx['history'] ?? []), -4) as $m) {
            $recent[] = (($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user') . ': ' . mb_substr((string) ($m['content'] ?? ''), 0, 200);
        }
        $lines[] = 'RECENT: ' . ($recent ? "\n" . implode("\n", $recent) : 'none');

        $raw = AI::chat([
            ['role' => 'system', 'content' => self::routerPrompt()],
            ['role' => 'user', 'content' => implode("\n", $lines)],
        ], [
            'tier'        => App::cfg('intent_tier', 'lite'),
            'temperature' => 0,
            'thinking'    => null,
            'deadline'    => microtime(true) + 8,
            'section'     => 'intent',
        ]);
        return self::interpret($raw, $text);
    }

    public static function interpret(?string $raw, string $text): ?array
    {
        if ($raw === null || !preg_match('/\{.*\}/su', $raw, $m)) {
            return null;
        }
        $j = json_decode($m[0], true);
        if (!is_array($j)) {
            return null;
        }
        $name = strtolower(trim((string) ($j['tool'] ?? '')));
        if (!in_array($name, self::NAMES, true) || !self::grounded((string) ($j['evidence'] ?? ''), $text)) {
            return null;
        }
        $arg = is_scalar($j['arg'] ?? null) ? (string) $j['arg'] : '';
        if ($name !== 'translate') {
            $arg = (string) strtok($arg . "\n", "\n");
        }
        return [$name, mb_substr(mbTrim($arg, " \t\n\"'«»<>"), 0, $name === 'translate' ? 3000 : 300)];
    }

    public static function grounded(string $evidence, string $text): bool
    {
        $e = self::squash($evidence);
        return mb_strlen($e) >= 2 && str_contains(self::squash($text), $e);
    }

    private static function squash(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ؤ' => 'و']);
        return preg_replace('/[\s\p{P}\p{M}\x{200C}-\x{200F}]+/u', '', $s) ?? $s;
    }

    public static function run(array $msg, string $name, string $arg): bool
    {
        $tg      = App::tg();
        $chat    = $msg['chat']['id'];
        $private = ($msg['chat']['type'] ?? '') === 'private';
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
        if ($name === 'image') {
            ImageGen::handle($msg, $arg !== '' ? $arg : trim((string) ($msg['text'] ?? $msg['caption'] ?? '')));
            return true;
        }
        if (in_array($name, ['translate', 'rates', 'weather', 'wiki', 'search', 'love', 'roast', 'aicheck'], true)) {
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
                return $arg === '' ? null : ['photo' => Tools::qrUrl($arg, 512), 'text' => '🔳 <code>' . h(mb_substr($arg, 0, 900)) . '</code>'];

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
                $list  = Search::results($arg, 5);
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
                    return ['text' => "⏳ هر دقیقه فقط یه روست!\nیه‌کم دیگه بیا 😅"];
                }
                if ($t === null) {
                    return ['text' => "🤷 این آدم رو پیدا نکردم؛\nآیدی عددی یا یوزرنیمش رو درست بنویس."];
                }
                $live = App::cfg('ai_stream', true) ? new LiveReply($msg, $imid, Roast::head($t), false) : null;
                return ['text' => Roast::text($t, $live), 'live' => $live];

            case 'userinfo':
                [$text, $kb] = UserInfo::card($msg['from'], $guest ? null : ($msg['chat']['type'] ?? null));
                return ['text' => $text, 'kb' => $kb];

            case 'aicheck':
                $pages = AiDetect::pages(AiDetect::report($msg));
                return ['text' => array_shift($pages), 'more' => $pages];

            case 'giveaway':
                return $guest ? ['text' => "🎁 قرعه‌کشی رو تو پیوی من بساز 👇", 'kb' => ikb([[urlBtn('🎁 ساخت قرعه‌کشی', "https://t.me/{$bot}?start=giveaway", 'success')]])] : null;
        }
        return null;
    }
}
