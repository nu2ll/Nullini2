<?php

final class Whisper
{
    public const MAX_LEN = 200;
    public const AV_DIR  = '/tmp/nullini-av';

    public static function avatarUrl(int $id): ?string
    {
        $f = self::AV_DIR . '/' . $id . '.jpg';
        $u = App::publicUrl();
        if ($id <= 0 || $u === '' || !is_file($f) || filesize($f) < 100) {
            return null;
        }
        return $u . '/av?u=' . $id . '&v=' . filemtime($f);
    }

    public static function cacheAvatar(int $id): void
    {
        $f = self::AV_DIR . '/' . $id . '.jpg';
        if ($id <= 0 || (is_file($f) && time() - filemtime($f) < 86400) || Store::get('cache', 'av_none_' . $id) !== null) {
            return;
        }
        $r   = App::tg()->call('getUserProfilePhotos', ['user_id' => $id, 'limit' => 1], 8);
        $fid = $r['result']['photos'][0][0]['file_id'] ?? null;
        $img = $fid ? App::tg()->download($fid) : null;
        if ($img === null || strlen($img) < 100) {
            Store::set('cache', 'av_none_' . $id, time());
            return;
        }
        @mkdir(self::AV_DIR, 0775, true);
        @file_put_contents($f, $img);
    }

    public static function pendingKeyboard(string $token = '', bool $multi = false): array
    {
        $show = $token === '' ? 'wh:show' : 'wh:show:' . $token;
        if ($multi) {
            return self::seenKeyboard($show);
        }
        return ikb([[btn('💬 نمایش', $show)]]);
    }

    public static function seenKeyboard(string $show = 'wh:show'): array
    {
        return ikb([
            [btn('🗑 حذف', 'wh:del', 'danger'), btn('💬 نمایش', $show), btn('📊 آمار', 'wh:stats', 'success')],
            [self::switchBtn('↪️ پاسخ', 'nj ', null)],
        ]);
    }

    public static function switchBtn(string $text, string $query, ?string $style = 'primary'): array
    {
        $b = ['text' => $text, 'switch_inline_query_current_chat' => $query];
        if ($style !== null) {
            $b['style'] = $style;
        }
        return $b;
    }

    public static function emptyText(): string
    {
        return '⚠️ به نظر میرسه متنی برای نجوا وارد نکردی';
    }

    public static function emptyKeyboard(): array
    {
        return ikb([[self::switchBtn('⬇️ ارسال دوباره', 'nj ', 'danger')]]);
    }

    public static function requestArticle(array $from): array
    {
        $id = (int) $from['id'];
        return Ui::article(
            'whr_' . $id,
            'whisper',
            '📨 درخواست نجوا',
            '🆔 آیدی عددی من: ' . $id,
            '🆔 آیدی عددی من: <code>' . $id . "</code>\n\n👇 برای نجوا به من دکمه زیر رو بزن",
            ikb([[self::switchBtn('ارسال به ' . mb_substr(userName($from), 0, 24), 'nj ' . $id . ' ', null)]])
        );
    }

    public static function parse(string $rest): array
    {
        $t = '(?:@[A-Za-z0-9_]{3,32}|\d{5,15})';
        if (preg_match('/^(?:(.*?)\s+)?(' . $t . '(?:\s+' . $t . ')*)$/su', $rest, $m)) {
            return ['text' => trim((string) ($m[1] ?? '')), 'to' => preg_split('/\s+/', $m[2])];
        }
        if (preg_match('/^(' . $t . '(?:\s+' . $t . ')*)(?:\s+(.*))?$/su', $rest, $m)) {
            return ['text' => trim((string) ($m[2] ?? '')), 'to' => preg_split('/\s+/', $m[1])];
        }
        return ['text' => trim($rest), 'to' => []];
    }

    public static function recipients(array $w): array
    {
        if (!empty($w['recipients']) && is_array($w['recipients'])) {
            return array_values($w['recipients']);
        }
        if (empty($w['recipient_id']) && empty($w['recipient_username']) && empty($w['recipient_name'])) {
            return [];
        }
        return [['id' => $w['recipient_id'] ?? null, 'username' => $w['recipient_username'] ?? null, 'name' => (string) ($w['recipient_name'] ?? '')]];
    }

    public static function recent(int $uid): array
    {
        $l = Store::get('whisper_recent', (string) $uid);
        return is_array($l) ? array_values($l) : [];
    }

    public static function group(int $uid): array
    {
        $g = Store::get('whisper_group', (string) $uid);
        return is_array($g) && count($g) > 1 ? array_values($g) : [];
    }

    private static function same(array $a, array $b): bool
    {
        if (!empty($a['id']) && !empty($b['id'])) {
            return (int) $a['id'] === (int) $b['id'];
        }
        return !empty($a['username']) && !empty($b['username']) && strcasecmp($a['username'], $b['username']) === 0;
    }

    public static function addRecent(int $uid, array $r): void
    {
        if ((int) ($r['id'] ?? 0) === $uid || (empty($r['id']) && empty($r['username']))) {
            return;
        }
        $r = ['id' => $r['id'] ?? null, 'username' => $r['username'] ?? null, 'name' => (string) ($r['name'] ?? '')];
        Store::update('whisper_recent', (string) $uid, static function ($l) use ($r) {
            $l = is_array($l) ? $l : [];
            $l = array_values(array_filter($l, static fn($x) => !self::same($x, $r)));
            array_unshift($l, $r);
            return array_slice($l, 0, 8);
        });
        if (!empty($r['id'])) {
            self::cacheAvatar((int) $r['id']);
        }
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
        return implode('، ', array_map(
            static fn($r) => userTag(!empty($r['id']) ? (int) $r['id'] : null, (string) ($r['name'] ?? ''), $r['username'] ?? null),
            self::recipients($w)
        ));
    }

    public static function pendingText(array $w): string
    {
        $n = count(self::recipients($w));
        if ($n > 1) {
            return '📫 یک نجوای چندنفره برای ' . fa($n) . " نفر ارسال شد\n" . self::mention($w);
        }
        return '📫 یک نجوا برای ' . self::mention($w) . ' ارسال شد';
    }

    public static function seenText(array $w): string
    {
        $rs = self::recipients($w);
        if (count($rs) <= 1) {
            return '📭 نجوای ' . self::mention($w) . ' مشاهده شد 🔹';
        }
        $readers = (array) ($w['readers'] ?? []);
        $names   = [];
        foreach ($readers as $id => $name) {
            $names[] = userTag((int) $id, (string) $name);
        }
        return '📭 نجوای ' . implode('، ', $names) . ' مشاهده شد (' . fa(count($readers)) . ' از ' . fa(count($rs)) . ') 🔹';
    }

    public static function draft(array $from, string $text, array $recipients): string
    {
        $token = bin2hex(random_bytes(8));
        $first = $recipients[0] ?? ['id' => null, 'username' => null, 'name' => ''];
        Store::set('whisper_pending', $token, [
            'sender_id'          => (int) $from['id'],
            'sender_name'        => userName($from),
            'recipient_id'       => $first['id'],
            'recipient_username' => $first['username'],
            'recipient_name'     => $first['name'],
            'recipients'         => array_values($recipients),
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
        $rs = self::recipients($p);
        foreach ($rs as $r) {
            self::addRecent((int) $p['sender_id'], $r);
        }
        if (count($rs) > 1) {
            Store::set('whisper_group', (string) $p['sender_id'], $rs);
        }
    }

    private static function isRecipient(array $w, array $from): bool
    {
        foreach (self::recipients($w) as $r) {
            if (!empty($r['id']) && (int) $from['id'] === (int) $r['id']) {
                return true;
            }
            if (!empty($r['username']) && !empty($from['username']) && strcasecmp($from['username'], $r['username']) === 0) {
                return true;
            }
        }
        return false;
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
                    $k   = (string) $from['id'];
                    $new = !isset(((array) ($w['readers'] ?? []))[$k]);
                    $upd = Store::update('whisper', $imid, static function ($s) use ($from, $k) {
                        if (!is_array($s)) {
                            return null;
                        }
                        $s['seen']        = (int) ($s['seen'] ?? 0) + 1;
                        $s['revealed']    = true;
                        $s['readers'][$k] = userName($from);
                        return $s;
                    });
                    if ($new && is_array($upd)) {
                        $tg->editInline($imid, self::seenText($upd), self::seenKeyboard());
                        self::addRecent((int) $from['id'], ['id' => $w['sender_id'], 'username' => null, 'name' => (string) ($w['sender_name'] ?? '')]);
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
