<?php

final class TicTacToe
{
    private const P     = 'ttt';
    private const LINES = [[0, 1, 2], [3, 4, 5], [6, 7, 8], [0, 3, 6], [1, 4, 7], [2, 5, 8], [0, 4, 8], [2, 4, 6]];
    private const MARK  = ['X' => '❌', 'O' => '⭕', '' => '⬜'];

    public static function intro(): string
    {
        return "<b>⭕ دوز</b>\n\nسه‌تا پشت سر هم ببر! حالت بازی رو انتخاب کن:";
    }

    public static function modeKeyboard(): array
    {
        return GameKit::modeKeyboard(self::P);
    }

    public static function winner(array $b): ?string
    {
        foreach (self::LINES as [$x, $y, $z]) {
            if ($b[$x] !== '' && $b[$x] === $b[$y] && $b[$y] === $b[$z]) {
                return $b[$x];
            }
        }
        return in_array('', $b, true) ? null : 'draw';
    }

    private static function winLine(array $b): array
    {
        foreach (self::LINES as $line) {
            [$x, $y, $z] = $line;
            if ($b[$x] !== '' && $b[$x] === $b[$y] && $b[$y] === $b[$z]) {
                return $line;
            }
        }
        return [];
    }

    private static function botMove(array $b): int
    {
        $empty = array_keys($b, '', true);
        foreach (['O', 'X'] as $i => $mark) {
            if ($i === 1 && random_int(1, 100) > 45) {
                break;
            }
            foreach ($empty as $c) {
                $copy     = $b;
                $copy[$c] = $mark;
                if (self::winner($copy) === $mark) {
                    return $c;
                }
            }
        }
        if (in_array(4, $empty, true) && random_int(0, 1)) {
            return 4;
        }
        return $empty[array_rand($empty)];
    }

    private static function board(array $b, bool $active, array $s = []): array
    {
        $win  = self::winLine($b);
        $rows = [];
        for ($r = 0; $r < 3; $r++) {
            $row = [];
            for ($c = 0; $c < 3; $c++) {
                $i     = $r * 3 + $c;
                $style = in_array($i, $win, true) ? 'success' : ($b[$i] === 'X' ? 'danger' : ($b[$i] === 'O' ? 'primary' : null));
                $row[] = btn(self::MARK[$b[$i]], $active ? self::P . ":cell:{$i}" : 'noop', $style);
            }
            $rows[] = $row;
        }
        $rows[] = $active ? GameKit::controlRow($s, self::P) : [btn('🔁 بازی دوباره', self::P . ':replay', 'success')];
        return ikb($rows);
    }

    private static function header(array $s): string
    {
        return "<b>⭕ دوز</b> · " . ($s['mode'] === 'bot' ? 'با ربات' : 'دو نفره') . "\n" . divider() . "\n"
            . '❌ ' . GameKit::name($s['p1']) . '  vs  ⭕ ' . GameKit::name($s['p2'] ?? null, '❔ منتظر حریف') . "\n" . divider() . "\n";
    }

    private static function render(string $imid, array $s): void
    {
        $tg = App::tg();
        if ($s['status'] === 'over') {
            $w   = self::winner($s['board']);
            $end = !empty($s['resigned']) ? GameKit::resignLine($s)
                : ($w === 'draw' ? '🤝 <b>بازی مساوی شد!</b>' : '🏆 <b>' . GameKit::name($w === 'X' ? $s['p1'] : $s['p2']) . ' برد!</b>');
            $tg->editInline($imid, self::header($s) . $end, self::board($s['board'], false));
            return;
        }
        $turn = $s['turn'] === 'X' ? '❌ ' . GameKit::name($s['p1']) : '⭕ ' . GameKit::name($s['p2'] ?? null, 'هر کی اول بزنه');
        $tg->editInline($imid, self::header($s) . "👉 نوبت: {$turn}" . GameKit::resignNote($s), self::board($s['board'], true, $s));
    }

    public static function onCallback(array $cq, string $data, string $imid): void
    {
        $tg   = App::tg();
        $from = $cq['from'];
        $s    = GameKit::ensure($imid, self::P, $from);

        $handled = GameKit::common($s, $cq, $data, self::P,
            function (string $mode) use (&$s, $imid) {
                $s['mode']   = $mode === 'bot' ? 'bot' : 'pvp';
                $s['status'] = 'playing';
                $s['board']  = array_fill(0, 9, '');
                $s['turn']   = 'X';
                $s['p2']     = $s['mode'] === 'bot' ? ['id' => 0, 'name' => '🤖 نالینی'] : null;
                GameKit::save($imid, $s);
                self::render($imid, $s);
            },
            function () use (&$s, $imid, $tg) {
                $s = GameKit::fresh(self::P, $s['p1']);
                GameKit::save($imid, $s);
                $tg->editInline($imid, self::intro(), self::modeKeyboard());
            },
            function (string $by) use (&$s, $imid, $tg) {
                GameKit::save($imid, $s);
                $tg->editInline($imid, self::header($s) . "✖️ بازی توسط {$by} لغو شد.", self::board($s['board'], false));
            },
            function () use (&$s, $imid) {
                GameKit::save($imid, $s);
                self::render($imid, $s);
            }
        );
        if ($handled) {
            return;
        }

        if (!str_starts_with($data, self::P . ':cell:') || ($s['status'] ?? '') !== 'playing') {
            $tg->answer($cq, '⌛️ این بازی تموم شده');
            return;
        }
        $i = (int) substr($data, strlen(self::P . ':cell:'));
        if ($i < 0 || $i > 8) {
            $tg->answer($cq);
            return;
        }

        $turnSeat = $s['turn'] === 'X' ? 1 : 2;
        if ($s['mode'] === 'bot') {
            if (!GameKit::isCreator($s, (int) $from['id'])) {
                $tg->alert($cq, '🚫 این بازی مال یکی دیگه‌ست؛' . "\n" . 'خودت از منو یکی بساز!');
                return;
            }
        } else {
            $seat = GameKit::seat($s, $from, $turnSeat);
            if ($seat === 0) {
                $tg->alert($cq, '🚫 این بازی دو نفره پر شده.');
                return;
            }
            if ($seat !== $turnSeat) {
                $tg->answer($cq, '⏳ نوبت تو نیست');
                return;
            }
        }
        if ($s['board'][$i] !== '') {
            $tg->answer($cq, '🈵 این خونه پره');
            return;
        }

        $s['board'][$i] = $s['turn'];
        unset($s['resign_ask']);
        if (self::winner($s['board']) === null) {
            if ($s['mode'] === 'bot') {
                $s['board'][self::botMove($s['board'])] = 'O';
            } else {
                $s['turn'] = $s['turn'] === 'X' ? 'O' : 'X';
            }
        }
        if (self::winner($s['board']) !== null) {
            $s['status'] = 'over';
        }
        GameKit::save($imid, $s);
        self::render($imid, $s);
        $tg->answer($cq);
    }
}
