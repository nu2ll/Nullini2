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
        $hasMedia = Assistant::hasMedia($msg) || ($reply && Assistant::hasMedia($reply));

        if ($q === '' && $rt === '' && !$hasMedia) {
            self::send($qid, Ui::hello(), Ui::groupKeyboard());
            return;
        }

        // A replied-to link is context, not by itself a request to download it.
        $link = $q !== '' ? Downloader::match($q) : null;
        if ($link !== null) {
            $imid = self::send($qid, '🔎 دارم لینک ' . $link['name'] . ' رو بررسی می‌کنم...');
            if ($imid !== null) {
                Users::hit('guest:dl');
                Downloader::guest($from, $link, $imid);
            }
            return;
        }

        $imid = self::send($qid, '🧠 نالینی داره فکر می‌کنه...');

        $routeCtx = Assistant::routeContext($msg);
        if (($act = Intent::decide($q, $routeCtx)) !== null && self::dispatchTool($msg, $from, $act, $imid, $qid, $q)) {
            return;
        }

        $parts = [];
        $notes = [];
        if ($reply) {
            if ($m = Assistant::mediaPart($reply)) {
                $parts[] = $m;
                $notes[] = '[کاربر روی یه ' . Assistant::kind($reply) . ' ریپلای کرده که پیوست شده' . (Assistant::isFrame($reply) ? '؛ فقط عکسِ فریم اولش پیوسته' : '') . ($rt !== '' ? "؛ متنش: {$rt}" : '') . ']';
            } elseif ($info = Assistant::fileInfo($reply)) {
                $notes[] = "[کاربر روی یه فایل ریپلای کرده؛ {$info}" . ($rt !== '' ? "؛ متنش: {$rt}" : '') . ']';
            } elseif ($data = Assistant::dataInfo($reply)) {
                $notes[] = "[کاربر روی یه {$data[0]} ریپلای کرده؛ {$data[1]}]";
            } elseif ($rt !== '') {
                $notes[] = "[پیامی که کاربر روش ریپلای کرده]: {$rt}";
            } elseif (!empty($reply['from'])) {
                $notes[] = '[کاربر روی یه پیام ریپلای کرده که متن نداره]';
            }
        }
        if ($reply && $notes) {
            $notes[0] = Assistant::withAlbum($notes[0], $reply);
        }
        $n1 = count($notes);
        if (Assistant::hasMedia($msg)) {
            if ($m = Assistant::mediaPart($msg)) {
                $parts[] = $m;
                $notes[] = isset($msg['voice']) ? '[کاربر ویس فرستاده؛ گوش بده و جواب بده]' : (isset($msg['photo']) ? '[کاربر عکس فرستاده؛ ببینش و جواب بده]' : '[کاربر ' . Assistant::kind($msg) . ' فرستاده؛ ' . (Assistant::isFrame($msg) ? 'فقط عکسِ فریم اولش پیوست شده' : 'عکسش پیوست شده') . '، ببینش و جواب بده]');
            } elseif ($info = Assistant::fileInfo($msg)) {
                $notes[] = "[کاربر یه فایل فرستاده؛ {$info}]";
            } elseif ($data = Assistant::dataInfo($msg)) {
                $notes[] = "[کاربر یه {$data[0]} فرستاده؛ {$data[1]}]";
            }
            if (count($notes) > $n1) {
                $notes[$n1] = Assistant::withAlbum($notes[$n1], $msg);
            }
        }
        $body    = trim(implode("\n", $notes) . ($q !== '' ? "\n\n{$q}" : ''));
        $content = $parts ? array_merge($parts, [['type' => 'text', 'text' => $body ?: 'سلام']]) : ($body ?: 'سلام');

        $ctx  = ['user_name' => userName($from), 'chat_type' => 'guest'];
        if (!AI::routerOn() && !AI::isServiceSender($from, $msg['sender_chat'] ?? null)) {
            $ctx['embedded_tools'] = true;
            $ctx['tool_current_message'] = $q;
            $ctx['tool_context'] = $routeCtx;
        }
        $searchQuestion = $q;
        if ($rt !== '') {
            $searchQuestion = "[پیام مورد ریپلای؛ فقط زمینه، نه مدرک]\n" . mb_substr($rt, 0, 1500) . "\n[سؤال فعلی]\n" . $q;
        }
        if ($q !== '' && !AI::isServiceSender($from, $msg['sender_chat'] ?? null) && ($plan = WebSearch::plan($searchQuestion, [])) !== null) {
            $web = WebSearch::answer($searchQuestion, $ctx, [], $plan, WebSearch::retrieve($plan));
            $formatted = WebSearch::format($web, 3900);
            self::edit($imid, $qid, $formatted['html'], $formatted['plain']);
            return;
        }
        $live = $imid !== null && App::cfg('ai_stream', true) && empty($ctx['embedded_tools']) ? new LiveReply($msg, $imid, '', false) : null;
        $opt  = ['reaction' => false] + ($live ? ['on_text' => $live->feed(...)] : []);
        $res  = AI::reply($content, $ctx, [], $opt);

        if ($res['answer'] === null && empty($res['tool']) && empty($res['image']) && $parts) {
            logWarn('guest', 'reply with media failed, retrying text-only');
            $note = '[کاربر به یه عکس یا ویس اشاره کرده ولی تو به محتواش دسترسی نداری؛ اگه سؤالش به اون بستگی داره، مؤدبانه بگو تو این چت فایل رو نمی‌بینی و بهتره تو پیوی برات بفرسته]';
            $res  = AI::reply(trim($note . ($rt !== '' ? "\n[متن پیام ریپلای‌شده]: {$rt}" : '') . ($q !== '' ? "\n\n{$q}" : '')), $ctx, [], $opt);
        }

        if (!empty($res['tool']) && self::dispatchTool($msg, $from, $res['tool'], $imid, $qid, $q)) {
            return;
        }
        if (!empty($res['image'])) {
            self::image($imid, $qid, $res['image']);
            return;
        }

        $answer = $res['answer'] ?? AI::FAIL_TEXT;
        $html   = AI::toHtml(AI::fit($answer));
        self::edit($imid, $qid, $html, $answer);
    }

    /** Shared execution path for both preliminary and main-response tool decisions. */
    public static function dispatchTool(array $msg, array $from, array $act, ?string $imid, string $qid = '', string $q = ''): bool
    {
        [$name, $arg] = $act;
        Users::hit('intent:' . $name);
        if ($name === 'image' && $imid !== null) {
            self::image($imid, $qid !== '' ? $qid : null, ImageGen::englishPrompt($arg !== '' ? $arg : $q));
            return true;
        }
        $rt = (string) ($msg['reply_to_message']['text'] ?? $msg['reply_to_message']['caption'] ?? '');
        if ($name === 'download' && ($dl = Downloader::find($arg . ' ' . $rt)) !== null && $imid !== null) {
            Downloader::guest($from, $dl, $imid);
            return true;
        }
        $o = Intent::output(['from' => $from] + $msg, $name, $arg, true, $imid);
        if ($o === null) {
            return false;
        }
        self::show($imid, $qid, $o);
        return true;
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
