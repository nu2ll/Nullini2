<?php

final class GuestHandler
{
    public static function handle(array $msg): void
    {
        $qid = (string) ($msg['guest_query_id'] ?? '');
        if ($qid === '') {
            return;
        }
        $from = $msg['guest_bot_caller_user'] ?? $msg['from'] ?? [];
        $text = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        $bot  = App::botUsername();
        $q    = mbTrim(preg_replace('/@' . preg_quote($bot, '/') . '\b/iu', '', $text) ?? $text, " \t\n،,:");
        $q    = mbTrim(preg_replace('/^نالینی(?=[\s،,:!؟?.]|$)/u', '', $q) ?? $q, " \t\n،,:");
        Users::hit('guest');

        $reply    = $msg['reply_to_message'] ?? null;
        $rt       = $reply ? trim((string) ($reply['text'] ?? $reply['caption'] ?? '')) : '';
        $hasMedia = isset($msg['photo']) || isset($msg['voice']) || isset($msg['audio'])
            || ($reply && (isset($reply['photo']) || isset($reply['voice']) || isset($reply['audio'])));

        if ($q === '' && $rt === '' && !$hasMedia) {
            self::send($qid, Ui::hello(), Ui::groupKeyboard());
            return;
        }

        $link = $q !== '' ? Downloader::match($q) : ($rt !== '' ? Downloader::match($rt) : null);
        if ($link !== null) {
            $imid = self::send($qid, '🔎 دارم لینک ' . $link['name'] . ' رو بررسی می‌کنم...');
            if ($imid !== null) {
                Users::hit('guest:dl');
                Downloader::guest($from, $link, $imid);
            }
            return;
        }

        $imid = self::send($qid, '🧠 نالینی داره فکر می‌کنه...');

        $parts = [];
        $notes = [];
        if ($reply) {
            if ($m = Assistant::mediaPart($reply)) {
                $parts[] = $m;
                $notes[] = '[کاربر روی یه پیام چندرسانه‌ای ریپلای کرده که پیوست شده' . ($rt !== '' ? "؛ متنش: {$rt}" : '') . ']';
            } elseif ($rt !== '') {
                $notes[] = "[پیامی که کاربر روش ریپلای کرده]: {$rt}";
            } elseif (!empty($reply['from'])) {
                $notes[] = '[کاربر روی یه پیام ریپلای کرده که متن نداره]';
            }
        }
        if (isset($msg['photo']) || isset($msg['voice']) || isset($msg['audio'])) {
            if ($m = Assistant::mediaPart($msg)) {
                $parts[] = $m;
                $notes[] = isset($msg['photo']) ? '[کاربر عکس فرستاده؛ ببینش و جواب بده]' : '[کاربر ویس فرستاده؛ گوش بده و جواب بده]';
            }
        }
        $body    = trim(implode("\n", $notes) . ($q !== '' ? "\n\n{$q}" : ''));
        $content = $parts ? array_merge($parts, [['type' => 'text', 'text' => $body ?: 'سلام']]) : ($body ?: 'سلام');

        $ctx  = ['user_name' => userName($from), 'chat_type' => 'guest'];
        $live = $imid !== null && App::cfg('ai_stream', true) ? new LiveReply($msg, $imid, '', false) : null;
        $opt  = ['reaction' => false, 'max_tokens' => 1500] + ($live ? ['on_text' => $live->feed(...)] : []);
        $res  = AI::reply($content, $ctx, [], $opt);

        if (!empty($res['action'])) {
            [$act, $arg] = $res['action'];
            Users::hit('intent:' . $act);
            if ($act === 'download' && ($dl = Downloader::find($arg . ' ' . $rt)) !== null && $imid !== null) {
                Downloader::guest($from, $dl, $imid);
                return;
            }
            $o = Intent::output(['from' => $from] + $msg, $act, $arg, true, $imid);
            if ($o !== null) {
                self::show($imid, $qid, $o);
                return;
            }
            $res = AI::reply($content, ['no_action' => true] + $ctx, [], $opt);
        }

        if ($res['answer'] === null && empty($res['image']) && $parts) {
            logWarn('guest', 'reply with media failed, retrying text-only');
            $note = '[کاربر به یه عکس یا ویس اشاره کرده ولی تو به محتواش دسترسی نداری؛ اگه سؤالش به اون بستگی داره، مؤدبانه بگو تو این چت فایل رو نمی‌بینی و بهتره تو پیوی برات بفرسته]';
            $res  = AI::reply(trim($note . ($rt !== '' ? "\n[متن پیام ریپلای‌شده]: {$rt}" : '') . ($q !== '' ? "\n\n{$q}" : '')), $ctx, [], $opt);
        }

        if (!empty($res['image'])) {
            self::image($imid, $qid, (string) $res['image']);
            return;
        }

        $answer = $res['answer'] ?? AI::FAIL_TEXT;
        $html   = AI::toHtml(AI::fit($answer));
        self::edit($imid, $qid, $html, $answer);
    }

    public static function image(?string $imid, ?string $qid, string $prompt): void
    {
        self::edit($imid, null, LiveReply::DRAWING);
        $bytes = ImageGen::generate($prompt, static function () {});
        if ($bytes === null) {
            self::edit($imid, $qid, ImageGen::failText());
            return;
        }
        if ($imid === null) {
            return;
        }
        // عکس موقتاً به چت ذخیره فرستاده و بلافاصله پاک می‌شه؛ فقط file_id می‌مونه
        $media = ImageGen::fileId($bytes);
        if ($media === null) {
            self::edit($imid, $qid, "😕 سرویس ساخت عکس الان جواب نمی‌ده؛\nچند دقیقه دیگه امتحان کن.");
            return;
        }
        $tg = App::tg();
        $r  = $tg->call('editMessageMedia', [
            'inline_message_id' => $imid,
            'media'             => ['type' => 'photo', 'media' => $media, 'caption' => '🎨 بفرما، اینم عکست!', 'parse_mode' => 'HTML'],
        ], 60);
        if (empty($r['ok'])) {
            self::edit($imid, $qid, '😕 عکس ساخته شد ولی ارسالش به مشکل خورد؛' . "\n" . 'دوباره امتحان کن.');
        }
    }

    public static function show(?string $imid, string $qid, array $o): void
    {
        $tg = App::tg();
        if ($imid === null) {
            self::send($qid, $o['text'], $o['kb'] ?? null);
            return;
        }
        if (!empty($o['photo']) || !empty($o['anim'])) {
            $r = $tg->call('editMessageMedia', [
                'inline_message_id' => $imid,
                'media'             => ['type' => !empty($o['photo']) ? 'photo' : 'animation', 'media' => $o['photo'] ?? $o['anim'], 'caption' => $o['text'], 'parse_mode' => 'HTML'],
                'reply_markup'      => $o['kb'] ?? null,
            ], 60);
            if (!empty($r['ok'])) {
                return;
            }
        }
        $r = $tg->call('editMessageText', [
            'inline_message_id'    => $imid,
            'text'                 => $o['text'],
            'parse_mode'           => 'HTML',
            'reply_markup'         => $o['kb'] ?? null,
            'link_preview_options' => $o['preview'] ?? ['is_disabled' => true],
        ]);
        if (empty($r['ok'])) {
            $tg->editInline($imid, $o['text'], $o['kb'] ?? null);
        }
    }

    private static function send(string $qid, string $html, ?array $markup = null): ?string
    {
        $result = [
            'type'                  => 'article',
            'id'                    => substr(md5($qid . microtime()), 0, 16),
            'title'                 => 'نالینی',
            'input_message_content' => ['message_text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]],
        ];
        if ($markup !== null) {
            $result['reply_markup'] = $markup;
        }
        $r = App::tg()->call('answerGuestQuery', ['guest_query_id' => $qid, 'result' => $result], 15);
        return !empty($r['ok']) ? ($r['result']['inline_message_id'] ?? null) : null;
    }

    private static function edit(?string $imid, ?string $qid, string $html, ?string $plain = null): void
    {
        $tg = App::tg();
        if ($imid === null) {
            if ($qid !== null) {
                self::send($qid, $html);
            }
            return;
        }
        if (empty($tg->editInline($imid, $html)['ok']) && $plain !== null) {
            $tg->editInline($imid, mb_substr($plain, 0, 4096), null, false);
        }
    }
}
