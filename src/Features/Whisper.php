<?php

final class Whisper
{
    public const MAX_LEN = 200;

    private static function key(): string
    {
        return hash('sha256', 'nullini-whisper|' . (string) env('WHISPER_KEY', App::tokens()[0] ?? ''), true);
    }

    private static function seal(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($text, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? $text : 'enc:' . base64_encode($iv . $tag . $ct);
    }

    private static function open(string $text): string
    {
        if (!str_starts_with($text, 'enc:')) {
            return $text;
        }
        $raw = base64_decode(substr($text, 4), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }

    public static function avSig(int $id, int $v): string
    {
        return substr(hash_hmac('sha256', $id . '|' . $v, (string) (App::tokens()[0] ?? '')), 0, 16);
    }

    public static function avatarBytes(int $id): ?string
    {
        $r = Store::get('whisper_av', (string) $id);
        $b = is_array($r) && !empty($r['b']) ? base64_decode((string) $r['b'], true) : false;
        return $b !== false && strlen($b) >= 100 ? $b : null;
    }

    public static function avatarUrl(int $id): ?string
    {
        $u = App::ngrokUrl();
        if ($id <= 0 || $u === '') {
            return null;
        }
        $r = Store::get('whisper_av', (string) $id);
        if (!is_array($r) || empty($r['b'])) {
            return null;
        }
        $v = (int) ($r['at'] ?? 0);
        return $u . '/av?u=' . $id . '&v=' . $v . '&s=' . self::avSig($id, $v);
    }

    public static function refreshAvatar(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $res = App::tg()->call('getUserProfilePhotos', ['user_id' => $id, 'limit' => 1], 6);
        if (empty($res['ok'])) {
            return;
        }
        $ph  = $res['result']['photos'][0][0] ?? null;
        $old = Store::get('whisper_av', (string) $id);
        $old = is_array($old) ? $old : [];
        if ($ph === null) {
            if (!empty($old['b'])) {
                Store::set('whisper_av', (string) $id, ['u' => null, 'at' => time()]);
            }
            return;
        }
        $uid = (string) ($ph['file_unique_id'] ?? $ph['file_id']);
        if (($old['u'] ?? null) === $uid && !empty($old['b'])) {
            return;
        }
        $img = App::tg()->download($ph['file_id']);
        if ($img === null || strlen($img) < 100) {
            return;
        }
        Store::set('whisper_av', (string) $id, ['u' => $uid, 'b' => base64_encode($img), 'at' => time()]);
    }

    public static function prefetch(array $tokens): void
    {
        $calls = [];
        foreach ($tokens as $t) {
            $target = null;
            if (preg_match('/^@([A-Za-z0-9_]{3,32})$/', $t, $m)) {
                $target = '@' . $m[1];
            } elseif (preg_match('/^\d{5,15}$/', $t)) {
                $target = (int) $t;
            }
            if ($target === null) {
                continue;
            }
            $row = UserInfo::local($target);
            $cid = $row['id'] ?? $target;
            $key = 'chat_' . mb_strtolower((string) $cid);
            $hit = Store::get('cache', $key);
            if (is_array($hit) && isset($hit['at']) && time() - $hit['at'] < 3600) {
                continue;
            }
            $calls[$key] = ['chat_id' => $cid];
        }
        if ($calls === []) {
            return;
        }
        foreach (App::tg()->multi('getChat', $calls, 8) as $key => $r) {
            if (!empty($r['ok'])) {
                Store::set('cache', $key, ['at' => time(), 'v' => $r['result']]);
            }
        }
    }

    public static function pendingKeyboard(string $token = '', bool $multi = false): array
    {
        $show = $token === '' ? 'wh:show' : 'wh:show:' . $token;
        if ($multi) {
            return self::seenKeyboard($show, $token);
        }
        return ikb([[btn('💬 نمایش', $show)]]);
    }

    public static function seenKeyboard(string $show = 'wh:show', string $ref = ''): array
    {
        $sfx = $ref === '' ? '' : ':' . $ref;
        return ikb([
            [btn('🗑 حذف', 'wh:del' . $sfx, 'danger'), btn('💬 نمایش', $show), btn('📊 آمار', 'wh:stats' . $sfx, 'success')],
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
            return array_slice($l, 0, 48);
        });
        if (!empty($r['id'])) {
            self::refreshAvatar((int) $r['id']);
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
            static fn($r) => '<u>' . userTag(!empty($r['id']) ? (int) $r['id'] : null, (string) ($r['name'] ?? ''), $r['username'] ?? null) . '</u>',
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
            $names[] = '<u>' . userTag((int) $id, (string) $name) . '</u>';
        }
        return '📭 نجوای ' . implode('، ', $names) . ' مشاهده شد (' . fa(count($readers)) . ' از ' . fa(count($rs)) . ') 🔹';
    }

    public static function draft(array $from, string $text, array $opts): string
    {
        $opts  = array_values($opts);
        $token = substr(md5((int) $from['id'] . '|' . $text . '|' . json_encode($opts)), 0, 16);
        Store::set('whisper_pending', $token, [
            'sender_id'   => (int) $from['id'],
            'sender_name' => userName($from),
            'text'        => self::seal($text),
            'opts'        => $opts,
        ]);
        return $token;
    }

    public static function bind(string $ref, string $imid): void
    {
        if (!preg_match('/^([a-f0-9]{16})(?:_(\d{1,2}))?$/', $ref, $m)) {
            return;
        }
        $p = Store::getToday('whisper_pending', $m[1]);
        if (!is_array($p)) {
            return;
        }
        $rs = $p['opts'][(int) ($m[2] ?? 0)] ?? self::recipients($p);
        foreach ($rs as $i => $r) {
            if (empty($r['id']) && !empty($r['username'])) {
                $row = UserInfo::local('@' . $r['username']);
                if (!empty($row['id'])) {
                    $rs[$i]['id'] = (int) $row['id'];
                }
            }
        }
        $rs    = array_values($rs);
        $first = $rs[0] ?? ['id' => null, 'username' => null, 'name' => ''];
        Store::set('whisper', $imid, [
            'sender_id'          => (int) $p['sender_id'],
            'sender_name'        => $p['sender_name'] ?? '',
            'recipient_id'       => $first['id'] ?? null,
            'recipient_username' => $first['username'] ?? null,
            'recipient_name'     => $first['name'] ?? '',
            'recipients'         => $rs,
            'text'               => $p['text'],
            'seen' => 0, 'revealed' => false, 'snoopers' => [], 'deleted' => false,
        ]);
        Store::delete('whisper_pending', $m[1]);
        foreach ($rs as $r) {
            self::addRecent((int) $p['sender_id'], $r);
        }
        if (count($rs) > 1) {
            Store::set('whisper_group', (string) $p['sender_id'], $rs);
        }
        self::refreshAvatar((int) $p['sender_id']);
    }

    private static function isRecipient(array $w, array $from): bool
    {
        foreach (self::recipients($w) as $r) {
            if (!empty($r['id'])) {
                if ((int) $from['id'] === (int) $r['id']) {
                    return true;
                }
                continue;
            }
            if (!empty($r['username']) && !empty($from['username']) && strcasecmp($from['username'], $r['username']) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function onCallback(array $cq, string $data, string $imid): void
    {
        self::dispatch($cq, $data, $imid);
        self::refreshAvatar((int) ($cq['from']['id'] ?? 0));
    }

    private static function dispatch(array $cq, string $data, string $imid): void
    {
        $tg   = App::tg();
        $from = $cq['from'];
        $w    = Store::get('whisper', $imid);
        if (preg_match('/^wh:(show|del|stats):([a-f0-9]{16}(?:_\d{1,2})?)$/', $data, $m)) {
            $data = 'wh:' . $m[1];
            if (!is_array($w)) {
                self::bind($m[2], $imid);
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
                    $tg->alert($cq, '⚠️ برای نمایش باید فرستنده یا گیرنده‌ی نجوا باشی');
                    return;
                }

                $wt = mb_substr(self::open((string) $w['text']), 0, self::MAX_LEN);
                $tg->alert($cq, mb_strlen($wt) <= self::MAX_LEN - 3 ? '🤫 ' . $wt : $wt);

                if ($isRecipient) {
                    $k   = (string) $from['id'];
                    $new = !isset(((array) ($w['readers'] ?? []))[$k]);
                    $upd = Store::update('whisper', $imid, static function ($s) use ($from, $k) {
                        if (!is_array($s)) {
                            return null;
                        }
                        $rs = self::recipients($s);
                        foreach ($rs as $i => $r) {
                            if (empty($r['id']) && !empty($r['username']) && !empty($from['username']) && strcasecmp($from['username'], $r['username']) === 0) {
                                $rs[$i]['id'] = (int) $from['id'];
                            }
                        }
                        $s['recipients']  = $rs;
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
                    $tg->alert($cq, '⚠️ برای حذف باید فرستنده‌ی نجوا باشی');
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
                $sn  = array_values((array) ($w['snoopers'] ?? []));
                $msg = ((int) ($w['seen'] ?? 0) > 0 ? '👁 ' . fa((int) $w['seen']) . ' بار خونده شده' : '⚠️ هنوز کسی ندیده') . "\n\n";
                $msg .= $sn === []
                    ? '😇 فضول‌ها: هیچ‌کس'
                    : '😁 فضول‌ها: ' . fa(count($sn)) . " نفر\n" . implode(' ', array_map(static fn($x) => '[' . $x['name'] . ']', array_slice($sn, 0, 8)));
                $tg->alert($cq, $msg);
                return;
        }
        $tg->answer($cq);
    }
}
