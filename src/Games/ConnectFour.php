<?php

final class ConnectFour
{
    private const P    = 'c4';
    private const ROWS = 6;
    private const COLS = 7;
    private const DISC = ['R' => '🔴', 'Y' => '🟡', '' => '⚪'];

    public static function intro(): string
    {
        return "<b>🔴 دوز سکه‌ای</b>\n\nچهارتا هم‌رنگ پشت سر هم بچین!\nحالت بازی رو انتخاب کن:";
    }

    public static function modeKeyboard(): array
    {
        return GameKit::modeKeyboard(self::P);
    }

    private static function drop(array $b, int $col): ?int
    {
        for ($r = self::ROWS - 1; $r >= 0; $r--) {
            if ($b[$r * self::COLS + $col] === '') {
                return $r;
            }
        }
        return null;
    }

    private static function winner(array $b): array
    {
        foreach ([[0, 1], [1, 0], [1, 1], [1, -1]] as [$dr, $dc]) {
            for ($r = 0; $r < self::ROWS; $r++) {
                for ($c = 0; $c < self::COLS; $c++) {
                    $m = $b[$r * self::COLS + $c];
                    if ($m === '') {
                        continue;
                    }
                    $cells = [$r * self::COLS + $c];
                    for ($k = 1; $k < 4; $k++) {
                        $rr = $r + $dr * $k;
                        $cc = $c + $dc * $k;
                        if ($rr < 0 || $rr >= self::ROWS || $cc < 0 || $cc >= self::COLS || $b[$rr * self::COLS + $cc] !== $m) {
                            continue 2;
                        }
                        $cells[] = $rr * self::COLS + $cc;
                    }
                    return [$m, $cells];
                }
            }
        }
        return [in_array('', $b, true) ? null : 'draw', []];
    }

    private static function validCols(array $b): array
    {
        return array_values(array_filter(range(0, self::COLS - 1), static fn($c) => $b[$c] === ''));
    }

    private static function botMove(array $b): int
    {
        $cols = self::validCols($b);
        $try  = static function (array $b, int $c, string $m): array {
            $b[self::drop($b, $c) * self::COLS + $c] = $m;
            return $b;
        };
        foreach ($cols as $c) {
            if (self::winner($try($b, $c, 'Y'))[0] === 'Y') {
                return $c;
            }
        }
        if (random_int(1, 100) <= 50) {
            foreach ($cols as $c) {
                if (self::winner($try($b, $c, 'R'))[0] === 'R') {
                    return $c;
                }
            }
        }
        $safe = array_values(array_filter($cols, static function ($c) use ($b, $try) {
            $after = $try($b, $c, 'Y');
            foreach (self::validCols($after) as $c2) {
                if (self::winner($try($after, $c2, 'R'))[0] === 'R') {
                    return false;
                }
            }
            return true;
        }));
        $pool     = $safe ?: $cols;
        $weighted = [];
        foreach ($pool as $c) {
            $weighted = array_merge($weighted, array_fill(0, 4 - (int) abs($c - 3), $c));
        }
        return $weighted[array_rand($weighted)];
    }

    private static function board(array $b, bool $active, array $s = []): array
    {
        [, $win] = self::winner($b);
        $rows    = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $row = [];
            for ($c = 0; $c < self::COLS; $c++) {
                $i     = $r * self::COLS + $c;
                $row[] = btn(self::DISC[$b[$i]], $active ? self::P . ":col:{$c}" : 'noop', in_array($i, $win, true) ? 'success' : null);
            }
            $rows[] = $row;
        }
        $rows[] = $active ? GameKit::controlRow($s, self::P) : [btn('🔁 بازی دوباره', self::P . ':replay', 'success')];
        return ikb($rows);
    }

    private static function header(array $s): string
    {
        return "<b>🔴 دوز سکه‌ای</b> · " . ($s['mode'] === 'bot' ? 'با ربات' : 'دو نفره') . "\n" . divider() . "\n"
            . '🔴 ' . GameKit::name($s['p1']) . '  vs  🟡 ' . GameKit::name($s['p2'] ?? null, '❔ منتظر حریف') . "\n" . divider() . "\n";
    }

    private static function render(string $imid, array $s): void
    {
        $tg = App::tg();
        if ($s['status'] === 'over') {
            [$w] = self::winner($s['board']);
            $end = !empty($s['resigned']) ? GameKit::resignLine($s)
                : ($w === 'draw' ? '🤝 <b>بازی مساوی شد!</b>' : '🏆 <b>' . GameKit::name($w === 'R' ? $s['p1'] : $s['p2']) . ' برد!</b>');
            $tg->editInline($imid, self::header($s) . $end, self::board($s['board'], false));
            return;
        }
        $turn = $s['turn'] === 'R' ? '🔴 ' . GameKit::name($s['p1']) : '🟡 ' . GameKit::name($s['p2'] ?? null, 'هر کی اول بزنه');
        $tg->editInline($imid, self::header($s) . "👉 نوبت: {$turn}\n<i>روی هر خونه‌ی یه ستون بزنی،\nسکه میفته اون ستون.</i>" . GameKit::resignNote($s), self::board($s['board'], true, $s));
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
                $s['board']  = array_fill(0, self::ROWS * self::COLS, '');
                $s['turn']   = 'R';
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

        if (!str_starts_with($data, self::P . ':col:') || ($s['status'] ?? '') !== 'playing') {
            $tg->answer($cq, '⌛️ این بازی تموم شده');
            return;
        }
        $col = (int) substr($data, strlen(self::P . ':col:'));
        if ($col < 0 || $col >= self::COLS) {
            $tg->answer($cq);
            return;
        }

        $turnSeat = $s['turn'] === 'R' ? 1 : 2;
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

        $row = self::drop($s['board'], $col);
        if ($row === null) {
            $tg->answer($cq, '🈵 این ستون پره');
            return;
        }
        $s['board'][$row * self::COLS + $col] = $s['turn'];
        unset($s['resign_ask']);

        if (self::winner($s['board'])[0] === null) {
            if ($s['mode'] === 'bot') {
                $bc                                      = self::botMove($s['board']);
                $s['board'][self::drop($s['board'], $bc) * self::COLS + $bc] = 'Y';
            } else {
                $s['turn'] = $s['turn'] === 'R' ? 'Y' : 'R';
            }
        }
        if (self::winner($s['board'])[0] !== null) {
            $s['status'] = 'over';
        }
        GameKit::save($imid, $s);
        self::render($imid, $s);
        $tg->answer($cq);
    }
}
