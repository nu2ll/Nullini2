<?php

final class MathDuel
{
    private const P           = 'math';
    public const GAME_SECONDS = 120;
    public const Q_SECONDS    = 6;
    private const BOT_KEY     = 'bot';
    private const BOT_CORRECT = 60;
    private const ACTIVE_KEY  = '_active_math';

    public static function intro(): string
    {
        return "<b>🔢 دوئل ریاضی</b>\n\nمسابقه‌ی سرعت حساب!\nهر کی زودتر جواب درست بده امتیاز می‌گیره.\n\nحالت بازی رو انتخاب کن:";
    }

    public static function modeKeyboard(): array
    {
        return GameKit::modeKeyboard(self::P, '👥 چند نفره');
    }

    private static function question(): array
    {
        $op = ['+', '-', '×', '×', '+'][random_int(0, 4)];
        if ($op === '×') {
            [$a, $b] = [random_int(2, 12), random_int(2, 12)];
            $ans     = $a * $b;
        } elseif ($op === '-') {
            $a   = random_int(10, 80);
            $b   = random_int(1, $a);
            $ans = $a - $b;
        } else {
            [$a, $b] = [random_int(5, 60), random_int(5, 60)];
            $ans     = $a + $b;
        }
        $opts = [$ans];
        while (count($opts) < 4) {
            $d = random_int(-10, 10);
            $w = $ans + $d;
            if ($d !== 0 && $w >= 0 && !in_array($w, $opts, true)) {
                $opts[] = $w;
            }
        }
        shuffle($opts);
        return ['text' => "{$a} {$op} {$b}", 'answer' => $ans, 'options' => $opts];
    }

    private static function nextRound(array $s): array
    {
        $now                   = time();
        $s['qid']              = (int) ($s['qid'] ?? 0) + 1;
        $s['q']                = self::question();
        $s['q_at']             = $now;
        $s['answered']         = [];
        if (($s['mode'] ?? '') === 'bot') {
            $s['bot_at']      = $now + random_int(2, self::Q_SECONDS - 1);
            $s['bot_correct'] = random_int(1, 100) <= self::BOT_CORRECT;
        }
        return $s;
    }

    private static function total(array $p): int
    {
        return array_sum($p['scores'] ?? []);
    }

    private static function playerList(array $s, bool $scores): string
    {
        $l = [];
        $i = 1;
        foreach ($s['players'] as $p) {
            $l[] = fa($i++) . '. ' . bidi(h($p['name'])) . ($scores ? ' — <b>' . fa(self::total($p)) . '</b>' : '');
        }
        return implode("\n", $l);
    }

    private static function lobbyText(array $s): string
    {
        return "<b>🔢 دوئل ریاضی</b> · لابی\n" . divider() . "\n"
            . '👥 بازیکن‌ها (' . fa(count($s['players'])) . "):\n" . self::playerList($s, false) . "\n" . divider() . "\n"
            . '⏱ مدت بازی: ' . fa(self::GAME_SECONDS / 60) . " دقیقه · هر سوال " . fa(self::Q_SECONDS) . " ثانیه\n"
            . '➕ برای اومدن دکمه‌ی «پیوستن» رو بزن؛' . "\n" . 'با حداقل ۲ نفر سازنده می‌تونه شروع کنه.';
    }

    private static function lobbyKeyboard(): array
    {
        return ikb([
            [btn('➕ پیوستن', self::P . ':join', 'success'), btn('▶️ شروع', self::P . ':start')],
            GameKit::cancelRow(self::P),
        ]);
    }

    private static function playText(array $s): string
    {
        $left = max(0, self::GAME_SECONDS - (time() - (int) $s['started']));
        return "<b>🔢 دوئل ریاضی</b> · سوال " . fa($s['qid']) . "\n" . divider() . "\n"
            . '❓ <b>' . fa($s['q']['text']) . " = ؟</b>\n\n"
            . "🏅 امتیازها:\n" . self::playerList($s, true) . "\n" . divider() . "\n"
            . '⏳ ' . fa(self::Q_SECONDS) . ' ثانیه وقت داری · ⏱ ~' . fa($left) . ' ثانیه تا پایان';
    }

    private static function playKeyboard(array $s): array
    {
        $btns = [];
        foreach ($s['q']['options'] as $o) {
            $btns[] = btn(fa($o), self::P . ":a:{$s['qid']}:{$o}");
        }
        $rows   = array_chunk($btns, 2);
        $rows[] = GameKit::cancelRow(self::P);
        return ikb($rows);
    }

    private static function finalText(array $s): string
    {
        $players = array_values($s['players']);
        usort($players, static fn($a, $b) => self::total($b) <=> self::total($a));
        $medals = ['🥇', '🥈', '🥉'];
        $l      = [];
        foreach ($players as $i => $p) {
            $l[] = ($medals[$i] ?? fa($i + 1) . '.') . ' ' . bidi(h($p['name'])) . ' — <b>' . fa(self::total($p)) . '</b> امتیاز';
        }
        return "<b>🔢 دوئل ریاضی</b> · پایان 🏁\n" . divider() . "\n" . implode("\n", $l);
    }

    private static function markActive(string $imid, bool $on): void
    {
        Store::update(GameKit::NS, self::ACTIVE_KEY, static function ($ids) use ($imid, $on) {
            $ids = is_array($ids) ? $ids : [];
            $ids = array_values(array_diff($ids, [$imid]));
            if ($on) {
                $ids[] = $imid;
            }
            return $ids;
        });
    }

    public static function hasActive(): bool
    {
        $ids = Store::get(GameKit::NS, self::ACTIVE_KEY, []);
        return is_array($ids) && $ids !== [];
    }

    public static function tick(): void
    {
        $ids = Store::get(GameKit::NS, self::ACTIVE_KEY, []);
        if (!is_array($ids) || !$ids) {
            return;
        }
        foreach ($ids as $imid) {
            self::advance((string) $imid);
        }
    }

    private static function advance(string $imid): void
    {
        $event = null;
        $s     = Store::update(GameKit::NS, $imid, static function ($s) use (&$event) {
            if (!is_array($s) || ($s['status'] ?? '') !== 'playing') {
                $event = 'gone';
                return null;
            }
            $now = time();
            if ($now - (int) $s['started'] >= self::GAME_SECONDS) {
                $s['status'] = 'over';
                $event       = 'over';
                return $s;
            }
            if ($now - (int) $s['q_at'] >= self::Q_SECONDS) {
                $event = 'next';
                return self::nextRound($s);
            }
            if (($s['mode'] ?? '') === 'bot' && empty($s['answered'][self::BOT_KEY]) && $now >= (int) ($s['bot_at'] ?? PHP_INT_MAX)) {
                $s['answered'][self::BOT_KEY] = true;
                if (!empty($s['bot_correct'])) {
                    $s['players'][self::BOT_KEY]['scores'][] = (int) $s['q']['answer'];
                    $event                                   = 'bot';
                    return $s;
                }
                $event = 'none';
                return $s;
            }
            return null;
        });

        App::useBot((int) ($s['bot'] ?? 0));
        $tg = App::tg();
        if ($event === 'gone' || $event === 'over') {
            self::markActive($imid, false);
        }
        if ($event === 'over' && $s) {
            $tg->editInline($imid, self::finalText($s), GameKit::replayKeyboard(self::P));
        } elseif (($event === 'next' || $event === 'bot') && $s) {
            $tg->editInline($imid, self::playText($s), self::playKeyboard($s));
        }
    }

    public static function onCallback(array $cq, string $data, string $imid): void
    {
        $tg   = App::tg();
        $from = $cq['from'];
        $uid  = (string) $from['id'];
        $s    = GameKit::ensure($imid, self::P, $from);
        $me   = ['name' => mb_substr(userName($from), 0, 28), 'scores' => []];

        $handled = GameKit::common($s, $cq, $data, self::P,
            function (string $mode) use (&$s, $imid, $tg, $uid, $me) {
                $s['mode']    = $mode === 'bot' ? 'bot' : 'pvp';
                $s['players'] = [$uid => $me];
                if ($s['mode'] === 'bot') {
                    $s['players'][self::BOT_KEY] = ['name' => '🤖 نالینی', 'scores' => []];
                    $s['status']                 = 'playing';
                    $s['started']                = time();
                    $s                           = self::nextRound($s);
                    GameKit::save($imid, $s);
                    self::markActive($imid, true);
                    $tg->editInline($imid, self::playText($s), self::playKeyboard($s));
                } else {
                    $s['status'] = 'lobby';
                    GameKit::save($imid, $s);
                    $tg->editInline($imid, self::lobbyText($s), self::lobbyKeyboard());
                }
            },
            function () use (&$s, $imid, $tg) {
                $s = GameKit::fresh(self::P, $s['p1']);
                GameKit::save($imid, $s);
                $tg->editInline($imid, self::intro(), self::modeKeyboard());
            },
            function (string $by) use (&$s, $imid, $tg) {
                GameKit::save($imid, $s);
                self::markActive($imid, false);
                $tg->editInline($imid, "<b>🔢 دوئل ریاضی</b>\n" . divider() . "\n✖️ بازی توسط {$by} لغو شد.", GameKit::replayKeyboard(self::P));
            }
        );
        if ($handled) {
            return;
        }

        if ($data === self::P . ':join') {
            if (($s['status'] ?? '') !== 'lobby') {
                $tg->answer($cq, '⏳ بازی شروع شده');
                return;
            }
            $joined = Store::update(GameKit::NS, $imid, static function ($st) use ($uid, $me) {
                if (!is_array($st) || isset($st['players'][$uid])) {
                    return null;
                }
                $st['players'][$uid] = $me;
                return $st;
            });
            if ($joined === null) {
                $tg->answer($cq, '✅ قبلاً اومدی تو بازی');
                return;
            }
            $tg->editInline($imid, self::lobbyText($joined), self::lobbyKeyboard());
            $tg->answer($cq, '✅ اومدی تو بازی!');
            return;
        }

        if ($data === self::P . ':start') {
            if (($s['status'] ?? '') !== 'lobby') {
                $tg->answer($cq);
                return;
            }
            if (!GameKit::isCreator($s, (int) $uid)) {
                $tg->alert($cq, '🔒 فقط سازنده می‌تونه بازی رو شروع کنه.');
                return;
            }
            if (count($s['players']) < 2) {
                $tg->alert($cq, '👥 حداقل ۲ نفر باید بیان تو بازی.');
                return;
            }
            $s['status']  = 'playing';
            $s['started'] = time();
            $s            = self::nextRound($s);
            GameKit::save($imid, $s);
            self::markActive($imid, true);
            $tg->editInline($imid, self::playText($s), self::playKeyboard($s));
            $tg->answer($cq, '🚀 شروع!');
            return;
        }

        if (str_starts_with($data, self::P . ':a:')) {
            [, , $qid, $value] = array_pad(explode(':', $data), 4, '');
            if (($s['status'] ?? '') !== 'playing') {
                $tg->answer($cq, '⌛️ بازی تموم شده');
                return;
            }
            if (!isset($s['players'][$uid])) {
                $tg->alert($cq, '🚫 تو این بازی نیستی؛' . "\n" . 'منتظر بازی بعدی باش.');
                return;
            }
            self::advance($imid);

            $result = null;
            $upd    = Store::update(GameKit::NS, $imid, static function ($st) use ($uid, $qid, $value, &$result) {
                if (!is_array($st) || ($st['status'] ?? '') !== 'playing') {
                    $result = 'over';
                    return null;
                }
                if ((int) $st['qid'] !== (int) $qid) {
                    $result = 'old';
                    return null;
                }
                if (!empty($st['answered'][$uid])) {
                    $result = 'used';
                    return null;
                }
                $st['answered'][$uid] = true;
                if ((int) $value === (int) $st['q']['answer']) {
                    $st['players'][$uid]['scores'][] = (int) $value;
                    $result                          = 'ok';
                } else {
                    $result = 'wrong';
                }
                return $st;
            });

            switch ($result) {
                case 'ok':
                    $tg->editInline($imid, self::playText($upd), self::playKeyboard($upd));
                    $tg->answer($cq, '✅ آفرین! +' . fa((int) $value));
                    return;
                case 'wrong':
                    $tg->answer($cq, '❌ اشتباه بود؛ شانست برای این سوال تموم شد');
                    return;
                case 'used':
                    $tg->answer($cq, '🚫 شانست رو برای این سوال استفاده کردی');
                    return;
                case 'old':
                    $tg->answer($cq, '⌛️ این سوال عوض شده؛ سوال جدید رو جواب بده');
                    return;
                default:
                    $tg->answer($cq, '⌛️ بازی تموم شده');
                    return;
            }
        }
        $tg->answer($cq);
    }
}
