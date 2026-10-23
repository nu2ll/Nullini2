<?php

final class Whisper
{
    public const MAX_LEN = 200;

    public static function pendingKeyboard(string $token = ''): array
    {
        return ikb([[btn('👁 نمایش نجوا', $token === '' ? 'wh:show' : 'wh:show:' . $token)]]);
    }

    public static function seenKeyboard(): array
    {
        return ikb([[btn('👁 نمایش', 'wh:show'), btn('📊 آمار', 'wh:stats', 'success'), btn('🗑 حذف', 'wh:del', 'danger')]]);
    }

    public static function deletedKeyboard(): array
    {
        return ikb([[btn('📊 آمار', 'wh:stats', 'success')]]);
    }

    public static function label(array $w): string
    {
        return self::mention($w);
    }

    public static function mention(array $w): string
    {
        return userTag(!empty($w['recipient_id']) ? (int) $w['recipient_id'] : null, (string) ($w['recipient_name'] ?? ''), $w['recipient_username'] ?? null);
    }

    public static function pendingText(array $w): string
    {
        return '🤫 یه نجوا برات اومده، ' . self::mention($w) . "!\n<i>فقط خودت می‌تونی بخونیش 👇</i>";
    }

    public static function draft(array $from, string $text, array $recipient): string
    {
        $token = bin2hex(random_bytes(8));
        Store::set('whisper_pending', $token, [
            'sender_id'          => (int) $from['id'],
            'sender_name'        => userName($from),
            'recipient_id'       => $recipient['id'],
            'recipient_username' => $recipient['username'],
            'recipient_name'     => $recipient['name'],
            'text'               => $text,
        ]);
        return $token;
    }

    public static function bind(string $token, string $imid): void
    {
        $p = Store::get('whisper_pending', $token);
        if (!is_array($p)) {
            return;
        }
        Store::set('whisper', $imid, $p + ['seen' => 0, 'revealed' => false, 'snoopers' => [], 'deleted' => false]);
        Store::delete('whisper_pending', $token);
    }

    private static function isRecipient(array $w, array $from): bool
    {
        if (!empty($w['recipient_id']) && (int) $from['id'] === (int) $w['recipient_id']) {
            return true;
        }
        return !empty($w['recipient_username']) && !empty($from['username'])
            && strcasecmp($from['username'], $w['recipient_username']) === 0;
    }

    public static function onCallback(array $cq, string $data, string $imid): void
    {
        $tg   = App::tg();
        $from = $cq['from'];
        $w    = Store::get('whisper', $imid);
        if (preg_match('/^wh:show:([a-f0-9]{16})$/', $data, $m)) {
            $data = 'wh:show';
            if (!is_array($w)) {
                self::bind($m[1], $imid);
                $w = Store::get('whisper', $imid);
            }
        }
        if (!is_array($w)) {
            $tg->alert($cq, '⌛️ این نجوا منقضی شده.');
            return;
        }

        $isSender    = (int) $from['id'] === (int) $w['sender_id'];
        $isRecipient = self::isRecipient($w, $from);

        switch ($data) {
            case 'wh:show':
                if (!empty($w['deleted'])) {
                    $tg->alert($cq, '🗑 فرستنده پشیمون شد و پاکش کرد.');
                    return;
                }
                if (!$isSender && !$isRecipient) {
                    Store::update('whisper', $imid, static function ($s) use ($from) {
                        if (!is_array($s)) {
                            return null;
                        }
                        $k                   = (string) $from['id'];
                        $s['snoopers'][$k] = ['name' => userName($from), 'count' => (int) ($s['snoopers'][$k]['count'] ?? 0) + 1];
                        return $s;
                    });
                    $tg->alert($cq, '🙅 این نجوا مال تو نیست؛' . "\n" . 'فضولیت ثبت شد 😏');
                    return;
                }

                $wt = mb_substr((string) $w['text'], 0, self::MAX_LEN);
                $tg->alert($cq, mb_strlen($wt) <= self::MAX_LEN - 3 ? '🤫 ' . $wt : $wt);

                if ($isRecipient) {
                    $wasRevealed = !empty($w['revealed']);
                    Store::update('whisper', $imid, static function ($s) {
                        if (!is_array($s)) {
                            return null;
                        }
                        $s['seen']     = (int) ($s['seen'] ?? 0) + 1;
                        $s['revealed'] = true;
                        return $s;
                    });
                    if (!$wasRevealed) {
                        $tg->editInline($imid, '✅ نجوا رو ' . self::label($w) . ' خوند.', self::seenKeyboard());
                    }
                }
                return;

            case 'wh:del':
                if (!$isSender) {
                    $tg->alert($cq, '🙅 این دکمه مال فرستنده‌ست، نه تو.');
                    return;
                }
                if (!empty($w['deleted'])) {
                    $tg->answer($cq, '🗑 این یکی رو قبلاً پاک کردی');
                    return;
                }
                Store::update('whisper', $imid, static fn($s) => is_array($s) ? ['deleted' => true, 'text' => ''] + $s : null);
                $tg->call('editMessageReplyMarkup', ['inline_message_id' => $imid, 'reply_markup' => self::deletedKeyboard()]);
                $tg->answer($cq, '🗑 پاک شد؛ دیگه کسی نمی‌تونه بخونه');
                return;

            case 'wh:stats':
                if (!$isSender && !$isRecipient) {
                    $tg->alert($cq, '🙈 آمار رو فقط فرستنده و گیرنده می‌بینن.');
                    return;
                }
                $snoop = empty($w['snoopers']) ? 'هیچ‌کس 😇' : implode('، ', array_map(
                    static fn($s) => $s['name'] . ' (' . fa($s['count']) . ')',
                    array_slice(array_values($w['snoopers']), 0, 8)
                ));
                $msg = $isSender
                    ? '👁 ' . fa((int) ($w['seen'] ?? 0)) . " بار خونده شده\n\n🕵️ فضول‌ها: {$snoop}"
                    : "🕵️ فضول‌ها: {$snoop}";
                $tg->alert($cq, $msg);
                return;
        }
        $tg->answer($cq);
    }
}
