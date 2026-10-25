<?php

final class Assistant
{
    private static function memKey(int $chatId, int $userId): string
    {
        return "{$chatId}_{$userId}";
    }

    public static function history(int $chatId, int $userId): array
    {
        $m = Store::get('ai_memory', self::memKey($chatId, $userId));
        return is_array($m) && ($m['day'] ?? '') === date('Y-m-d') ? ($m['messages'] ?? []) : [];
    }

    private static function remember(int $chatId, int $userId, string $user, string $assistant): void
    {
        $limit = max(2, (int) App::cfg('ai_memory_messages', 20));
        $day   = date('Y-m-d');
        Store::update('ai_memory', self::memKey($chatId, $userId), static function ($m) use ($user, $assistant, $limit, $day) {
            $msgs   = is_array($m) && ($m['day'] ?? '') === $day ? ($m['messages'] ?? []) : [];
            $msgs[] = ['role' => 'user', 'content' => mb_substr($user, 0, 3000)];
            $msgs[] = ['role' => 'assistant', 'content' => mb_substr($assistant, 0, 3000)];
            return ['at' => time(), 'day' => $day, 'messages' => array_slice($msgs, -$limit)];
        });
    }

    private static function chatInfo(int $chatId): array
    {
        return Store::remember('cache', 'gchat_' . $chatId, 3600, static function () use ($chatId) {
            $r = App::tg()->call('getChat', ['chat_id' => $chatId], 8);
            return !empty($r['ok']) ? ['title' => $r['result']['title'] ?? '', 'bio' => $r['result']['description'] ?? ''] : null;
        }) ?? ['title' => '', 'bio' => ''];
    }

    private static function bestPhoto(array $msg): ?string
    {
        $photos = $msg['photo'] ?? [];
        if (!$photos) {
            return null;
        }
        $best = end($photos);
        return $best['file_id'] ?? null;
    }

    public static function mediaPart(array $m): ?array
    {
        $tg = App::tg();
        if (isset($m['voice']) || isset($m['audio'])) {
            $a     = $m['voice'] ?? $m['audio'];
            $bytes = ($a['file_size'] ?? 0) <= 15_000_000 ? $tg->download($a['file_id']) : null;
            return $bytes ? ['type' => 'media', 'mime' => $a['mime_type'] ?? 'audio/ogg', 'data' => base64_encode($bytes)] : null;
        }
        if ($fid = self::bestPhoto($m)) {
            $bytes = $tg->download($fid);
            $info  = $bytes ? @getimagesizefromstring($bytes) : false;
            if ($info === false) {
                return null;
            }
            return ['type' => 'media', 'mime' => $info['mime'] ?? 'image/jpeg', 'data' => base64_encode($bytes)];
        }
        return null;
    }

    private static function kind(array $m): string
    {
        return (isset($m['voice']) || isset($m['audio'])) ? 'ویس' : (isset($m['photo']) ? 'عکس' : 'پیام');
    }

    private static function typing(int $chatId, string $answer): void
    {
        if (!App::cfg('ai_typing_effect', true)) {
            return;
        }
        $seconds = min(3.5, max(0.8, mb_strlen($answer) / 40));
        App::tg()->action($chatId, 'typing');
        usleep((int) ($seconds * 1_000_000));
    }

    private static function send(array $msg, string $answer): void
    {
        $tg = App::tg();
        foreach (AI::chunks($answer) as $part) {
            $r = $tg->reply($msg, AI::toHtml($part));
            if (empty($r['ok'])) {
                $tg->call('sendMessage', [
                    'chat_id'          => $msg['chat']['id'],
                    'text'             => mb_substr($part, 0, 4096),
                    'reply_parameters' => ['message_id' => $msg['message_id'], 'allow_sending_without_reply' => true],
                ]);
            }
        }
    }

    public static function handle(array $msg, string $question): void
    {
        $tg       = App::tg();
        $chatId   = (int) $msg['chat']['id'];
        $chatType = (string) $msg['chat']['type'];
        $from     = $msg['from'] ?? [];
        $userId   = (int) ($from['id'] ?? 0);
        $service  = AI::isServiceSender($from, $msg['sender_chat'] ?? null);

        $tg->action($chatId, 'typing');
        Users::hit($chatType === 'private' ? 'ai:private' : 'ai:group');

        $ctx = ['user_name' => $service ? '' : userName($from), 'service' => $service, 'chat_type' => $chatType];
        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $info              = self::chatInfo($chatId);
            $ctx['chat_title'] = $info['title'];
            $ctx['chat_bio']   = mb_substr((string) $info['bio'], 0, 300);
        }

        $parts = [];
        $notes = [];
        $log   = $question;

        $reply = $msg['reply_to_message'] ?? null;
        if ($reply) {
            $rt = trim((string) ($reply['text'] ?? $reply['caption'] ?? ''));
            if ($media = self::mediaPart($reply)) {
                $parts[] = $media;
                $notes[] = '[کاربر روی یه ' . self::kind($reply) . ' ریپلای کرده که پیوست شده' . ($rt !== '' ? "؛ متنش: {$rt}" : '') . ']';
            } elseif ($rt !== '') {
                $notes[] = "[پیامی که کاربر روش ریپلای کرده]: {$rt}";
            } elseif (!empty($reply['from'])) {
                $notes[] = '[کاربر روی یه پیام ریپلای کرده که متن نداره]';
            }
        }

        if (isset($msg['voice']) || isset($msg['audio']) || isset($msg['photo'])) {
            $media = self::mediaPart($msg);
            if (!$media) {
                $tg->reply($msg, '😕 نتونستم فایلت رو دانلود کنم؛' . "\n" . 'دوباره بفرست.');
                return;
            }
            $parts[] = $media;
            $isVoice = isset($msg['voice']) || isset($msg['audio']);
            $notes[] = $isVoice
                ? '[کاربر ویس فرستاده؛ گوش بده و دقیقاً به حرفش جواب بده، انگار متنی پرسیده]'
                : '[کاربر عکس فرستاده؛ ببینش و جواب بده]';
            $log = '[' . self::kind($msg) . '] ' . $question;
        }

        $text = trim(implode("\n", $notes) . ($question !== '' ? "\n\n{$question}" : ''));
        if ($text === '') {
            $text = 'سلام';
        }
        $content = $parts ? array_merge($parts, [['type' => 'text', 'text' => $text]]) : $text;
        if ($notes && $reply) {
            $log = $notes[0] . "\n" . $log;
        }

        $history = $service ? [] : self::history($chatId, $userId);
        $live    = App::cfg('ai_stream', true) ? new LiveReply($msg) : null;
        $opt     = ['max_len' => PHP_INT_MAX] + ($live ? ['on_text' => $live->feed(...)] : []);
        $res     = AI::reply($content, $ctx, $history, $opt);

        if ($res['reaction'] !== null) {
            $tg->react($chatId, (int) $msg['message_id'], $res['reaction']);
        }

        if (!empty($res['action'])) {
            $live?->discard();
            [$act, $arg] = $res['action'];
            if (Intent::run($msg, $act, $arg)) {
                if (!$service) {
                    self::remember($chatId, $userId, $log, '[' . $act . ($arg !== '' ? ': ' . $arg : '') . ' رو براش انجام دادم]');
                }
                return;
            }
            $res = AI::reply($content, ['no_action' => true] + $ctx, $history, $opt);
        }

        if (!empty($res['image'])) {
            Users::hit('image');
            if (ImageGen::draw($msg, $res['image'], null, $live) && !$service) {
                self::remember($chatId, $userId, $log, '[یه عکس براش ساختم: ' . mb_substr($res['image'], 0, 200) . ']');
            }
            return;
        }
        $answer = $res['answer'] ?? AI::FAIL_TEXT;

        if (!$live || !$live->finish($answer)) {
            self::typing($chatId, $answer);
            self::send($msg, $answer);
        }

        if ($res['answer'] !== null && !$service) {
            self::remember($chatId, $userId, $log, $res['answer']);
        }
    }

    public static function translate(array $msg, string $text, string $target = 'auto'): void
    {
        $tg     = App::tg();
        $source = trim($text);
        if ($source === '' && isset($msg['reply_to_message'])) {
            $source = trim((string) ($msg['reply_to_message']['text'] ?? $msg['reply_to_message']['caption'] ?? ''));
        }
        if ($source === '') {
            $tg->reply($msg, Ui::guide('translate'));
            return;
        }
        $tg->action($msg['chat']['id'], 'typing');
        $out = AI::translateFast($source, $target !== '' ? $target : 'auto');
        $tg->reply($msg, $out ? "🌐 <b>ترجمه:</b>\n\n" . h($out) : AI::FAIL_TEXT);
    }
}
