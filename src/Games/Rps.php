<?php

final class Rps
{
    private const P      = 'rps';
    private const LABELS = ['rock' => '🪨 سنگ', 'paper' => '📄 کاغذ', 'scissors' => '✂️ قیچی'];
    private const BEATS  = ['rock' => 'scissors', 'paper' => 'rock', 'scissors' => 'paper'];

    public static function intro(): string
    {
        return "<b>✊ سنگ کاغذ قیچی</b>\n\nبهترین از ۳ دور! حالت بازی رو انتخاب کن:";
    }

    public static function modeKeyboard(): array
    {
        return GameKit::modeKeyboard(self::P);
    }

    private static function choiceKeyboard(bool $pvp): array
    {
        $row = [];
        foreach (self::LABELS as $k => $label) {
            $row[] = btn($label, self::P . ":c:{$k}");
        }
        return ikb([$row, $pvp ? GameKit::cancelRow(self::P) : []]);
    }

    private static function botPick(string $human): string
    {
        $roll = random_int(1, 100);
        if ($roll <= 50) {
            return self::BEATS[$human];
        }
        if ($roll <= 75) {
            return $human;
        }
        return array_search($human, self::BEATS, true);
    }

    private static function winner(string $a, string $b): int
    {
        return $a === $b ? 0 : (self::BEATS[$a] === $b ? 1 : 2);
    }

    private static function header(array $s): string
    {
        $mode = ($s['mode'] ?? '') === 'bot' ? 'با ربات' : 'دو نفره';
        return "<b>✊ سنگ کاغذ قیچی</b> · {$mode}\n" . divider() . "\n";
    }

    private static function scoreLine(array $s): string
    {
        return '🏅 ' . GameKit::name($s['p1']) . ' <b>' . fa($s['score'][0]) . '</b> — <b>' . fa($s['score'][1]) . '</b> ' . GameKit::name($s['p2'] ?? null);
    }

    public static function onCallback(array $cq, string $data, string $imid): void
    {
        $tg   = App::tg();
        $from = $cq['from'];
        $s    = GameKit::ensure($imid, self::P, $from);

        $handled = GameKit::common($s, $cq, $data, self::P,
            function (string $mode) use (&$s, $imid, $tg) {
                $s['mode']   = $mode === 'bot' ? 'bot' : 'pvp';
                $s['status'] = 'playing';
                $s['round']  = 1;
                $s['score']  = [0, 0];
                $s['picks']  = [null, null];
                if ($s['mode'] === 'bot') {
                    $s['p2'] = ['id' => 0, 'name' => '🤖 نالینی'];
                }
                GameKit::save($imid, $s);
                $tg->editInline($imid, self::header($s) . "دور ۱ از ۳\n👇 انتخابت رو بزن:", self::choiceKeyboard($s['mode'] === 'pvp'));
            },
            function () use (&$s, $imid, $tg) {
                $s = GameKit::fresh(self::P, $s['p1']);
                GameKit::save($imid, $s);
                $tg->editInline($imid, self::intro(), self::modeKeyboard());
            },
            function (string $by) use (&$s, $imid, $tg) {
                GameKit::save($imid, $s);
                $tg->editInline($imid, self::header($s) . "✖️ بازی توسط {$by} لغو شد.", GameKit::replayKeyboard(self::P));
            }
        );
        if ($handled) {
            return;
        }

        if (!str_starts_with($data, self::P . ':c:') || ($s['status'] ?? '') !== 'playing') {
            $tg->answer($cq, '⌛️ این بازی تموم شده');
            return;
        }
        $pick = substr($data, strlen(self::P . ':c:'));
        if (!isset(self::LABELS[$pick])) {
            $tg->answer($cq);
            return;
        }

        if ($s['mode'] === 'bot') {
            if (!GameKit::isCreator($s, (int) $from['id'])) {
                $tg->alert($cq, '🚫 این بازی مال یکی دیگه‌ست؛' . "\n" . 'خودت از منو یکی بساز!');
                return;
            }
            $s['picks'] = [$pick, self::botPick($pick)];
        } else {
            $seat = GameKit::seat($s, $from, empty($s['p2']) ? 2 : 1);
            if ($seat === 0) {
                $tg->alert($cq, '🚫 این بازی دو نفره پر شده.');
                return;
            }
            if ($s['picks'][$seat - 1] !== null) {
                $tg->answer($cq, '✅ انتخابت ثبت شده؛ منتظر حریف باش');
                return;
            }
            $s['picks'][$seat - 1] = $pick;

            if ($s['picks'][0] === null || $s['picks'][1] === null) {
                GameKit::save($imid, $s);
                $who = $s['picks'][0] !== null ? GameKit::name($s['p1']) : GameKit::name($s['p2']);
                $tg->editInline($imid, self::header($s) . 'دور ' . fa($s['round']) . " از ۳\n✅ {$who} انتخاب کرد؛\nمنتظر نفر بعدی... 🤫\n\n" . self::scoreLine($s), self::choiceKeyboard(true));
                $tg->answer($cq, '✅ ثبت شد (مخفیه)');
                return;
            }
        }

        [$a, $b] = $s['picks'];
        $w       = self::winner($a, $b);
        if ($w > 0) {
            $s['score'][$w - 1]++;
        }
        $roundText = $w === 0 ? '🤝 این دور مساوی شد' : '🎉 این دور رو ' . GameKit::name($w === 1 ? $s['p1'] : $s['p2']) . ' برد';
        $body      = self::header($s)
            . 'دور ' . fa($s['round']) . " از ۳\n"
            . GameKit::name($s['p1']) . ': ' . self::LABELS[$a] . "\n"
            . GameKit::name($s['p2']) . ': ' . self::LABELS[$b] . "\n\n"
            . "<b>{$roundText}</b>\n" . self::scoreLine($s);

        $over = max($s['score']) >= 2 || $s['round'] >= 3;
        if ($over) {
            $s['status'] = 'over';
            [$x, $y]     = $s['score'];
            $final       = $x === $y ? '🤝 <b>کل مسابقه مساوی شد!</b>' : '🏆 <b>قهرمان: ' . GameKit::name($x > $y ? $s['p1'] : $s['p2']) . '</b>';
            GameKit::save($imid, $s);
            $tg->editInline($imid, $body . "\n" . divider() . "\n" . $final, GameKit::replayKeyboard(self::P));
        } else {
            $s['round']++;
            $s['picks'] = [null, null];
            GameKit::save($imid, $s);
            $tg->editInline($imid, $body . "\n\n👇 دور " . fa($s['round']) . ':', self::choiceKeyboard($s['mode'] === 'pvp'));
        }
        $tg->answer($cq);
    }
}
