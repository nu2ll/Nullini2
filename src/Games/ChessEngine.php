<?php

final class ChessEngine
{
    public const START = 'rnbqkbnrpppppppp................................PPPPPPPPRNBQKBNR';

    private const KNIGHT = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
    private const KING   = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
    private const DIAG   = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
    private const ORTH   = [[-1, 0], [1, 0], [0, -1], [0, 1]];
    private const PROMO  = [1 => 'q', 2 => 'r', 3 => 'b', 4 => 'n'];

    public static function newState(): array
    {
        return ['b' => self::START, 't' => 'w', 'c' => 'KQkq', 'ep' => -1, 'h' => 0, 'f' => 1];
    }

    public static function color(string $p): string
    {
        if ($p === '.') {
            return '';
        }
        return ctype_upper($p) ? 'w' : 'b';
    }

    public static function move(int $from, int $to, int $promo = 0): int
    {
        return $from | ($to << 6) | ($promo << 12);
    }

    public static function from(int $m): int
    {
        return $m & 63;
    }

    public static function to(int $m): int
    {
        return ($m >> 6) & 63;
    }

    public static function promo(int $m): int
    {
        return $m >> 12;
    }

    public static function opp(string $t): string
    {
        return $t === 'w' ? 'b' : 'w';
    }

    public static function attacked(string $b, int $sq, string $by): bool
    {
        $r = intdiv($sq, 8);
        $c = $sq % 8;

        $pr   = $by === 'w' ? $r + 1 : $r - 1;
        $pawn = $by === 'w' ? 'P' : 'p';
        if ($pr >= 0 && $pr < 8) {
            if ($c > 0 && $b[$pr * 8 + $c - 1] === $pawn) {
                return true;
            }
            if ($c < 7 && $b[$pr * 8 + $c + 1] === $pawn) {
                return true;
            }
        }

        $n = $by === 'w' ? 'N' : 'n';
        foreach (self::KNIGHT as [$dr, $dc]) {
            $rr = $r + $dr;
            $cc = $c + $dc;
            if ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8 && $b[$rr * 8 + $cc] === $n) {
                return true;
            }
        }
        $k = $by === 'w' ? 'K' : 'k';
        foreach (self::KING as [$dr, $dc]) {
            $rr = $r + $dr;
            $cc = $c + $dc;
            if ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8 && $b[$rr * 8 + $cc] === $k) {
                return true;
            }
        }

        $bishop = $by === 'w' ? 'B' : 'b';
        $rook   = $by === 'w' ? 'R' : 'r';
        $queen  = $by === 'w' ? 'Q' : 'q';
        foreach ([[self::DIAG, $bishop], [self::ORTH, $rook]] as [$dirs, $slider]) {
            foreach ($dirs as [$dr, $dc]) {
                $rr = $r + $dr;
                $cc = $c + $dc;
                while ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8) {
                    $p = $b[$rr * 8 + $cc];
                    if ($p !== '.') {
                        if ($p === $slider || $p === $queen) {
                            return true;
                        }
                        break;
                    }
                    $rr += $dr;
                    $cc += $dc;
                }
            }
        }
        return false;
    }

    public static function kingSquare(string $b, string $color): int
    {
        $pos = strpos($b, $color === 'w' ? 'K' : 'k');
        return $pos === false ? -1 : $pos;
    }

    public static function inCheck(array $s, ?string $color = null): bool
    {
        $color ??= $s['t'];
        $k = self::kingSquare($s['b'], $color);
        return $k >= 0 && self::attacked($s['b'], $k, self::opp($color));
    }

    public static function pseudo(array $s, bool $capturesOnly = false): array
    {
        $b   = $s['b'];
        $t   = $s['t'];
        $opp = self::opp($t);
        $mv  = [];

        for ($sq = 0; $sq < 64; $sq++) {
            $p = $b[$sq];
            if ($p === '.' || self::color($p) !== $t) {
                continue;
            }
            $r = intdiv($sq, 8);
            $c = $sq % 8;
            $u = strtoupper($p);

            if ($u === 'P') {
                $d     = $t === 'w' ? -1 : 1;
                $start = $t === 'w' ? 6 : 1;
                $last  = $t === 'w' ? 0 : 7;
                $rr    = $r + $d;
                if ($rr < 0 || $rr > 7) {
                    continue;
                }
                $to = $rr * 8 + $c;
                if ($b[$to] === '.' && (!$capturesOnly || $rr === $last)) {
                    if ($rr === $last) {
                        for ($pr = 1; $pr <= 4; $pr++) {
                            $mv[] = self::move($sq, $to, $pr);
                        }
                    } else {
                        $mv[] = self::move($sq, $to);
                        $two  = ($r + 2 * $d) * 8 + $c;
                        if (!$capturesOnly && $r === $start && $b[$two] === '.') {
                            $mv[] = self::move($sq, $two);
                        }
                    }
                }
                foreach ([-1, 1] as $dc) {
                    $cc = $c + $dc;
                    if ($cc < 0 || $cc > 7) {
                        continue;
                    }
                    $to = $rr * 8 + $cc;
                    $q  = $b[$to];
                    if (($q !== '.' && self::color($q) === $opp) || $to === $s['ep']) {
                        if ($rr === $last) {
                            for ($pr = 1; $pr <= 4; $pr++) {
                                $mv[] = self::move($sq, $to, $pr);
                            }
                        } else {
                            $mv[] = self::move($sq, $to);
                        }
                    }
                }
                continue;
            }

            if ($u === 'N' || $u === 'K') {
                foreach ($u === 'N' ? self::KNIGHT : self::KING as [$dr, $dc]) {
                    $rr = $r + $dr;
                    $cc = $c + $dc;
                    if ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8) {
                        $q = $b[$rr * 8 + $cc];
                        if ($q === '.' ? !$capturesOnly : self::color($q) === $opp) {
                            $mv[] = self::move($sq, $rr * 8 + $cc);
                        }
                    }
                }
                if ($u === 'K' && !$capturesOnly) {
                    $cr = $s['c'];
                    if ($t === 'w' && $sq === 60) {
                        if (str_contains($cr, 'K') && $b[61] === '.' && $b[62] === '.' && $b[63] === 'R'
                            && !self::attacked($b, 60, $opp) && !self::attacked($b, 61, $opp) && !self::attacked($b, 62, $opp)) {
                            $mv[] = self::move(60, 62);
                        }
                        if (str_contains($cr, 'Q') && $b[59] === '.' && $b[58] === '.' && $b[57] === '.' && $b[56] === 'R'
                            && !self::attacked($b, 60, $opp) && !self::attacked($b, 59, $opp) && !self::attacked($b, 58, $opp)) {
                            $mv[] = self::move(60, 58);
                        }
                    } elseif ($t === 'b' && $sq === 4) {
                        if (str_contains($cr, 'k') && $b[5] === '.' && $b[6] === '.' && $b[7] === 'r'
                            && !self::attacked($b, 4, $opp) && !self::attacked($b, 5, $opp) && !self::attacked($b, 6, $opp)) {
                            $mv[] = self::move(4, 6);
                        }
                        if (str_contains($cr, 'q') && $b[3] === '.' && $b[2] === '.' && $b[1] === '.' && $b[0] === 'r'
                            && !self::attacked($b, 4, $opp) && !self::attacked($b, 3, $opp) && !self::attacked($b, 2, $opp)) {
                            $mv[] = self::move(4, 2);
                        }
                    }
                }
                continue;
            }

            $dirs = $u === 'B' ? self::DIAG : ($u === 'R' ? self::ORTH : array_merge(self::DIAG, self::ORTH));
            foreach ($dirs as [$dr, $dc]) {
                $rr = $r + $dr;
                $cc = $c + $dc;
                while ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8) {
                    $q = $b[$rr * 8 + $cc];
                    if ($q === '.') {
                        if (!$capturesOnly) {
                            $mv[] = self::move($sq, $rr * 8 + $cc);
                        }
                    } else {
                        if (self::color($q) === $opp) {
                            $mv[] = self::move($sq, $rr * 8 + $cc);
                        }
                        break;
                    }
                    $rr += $dr;
                    $cc += $dc;
                }
            }
        }
        return $mv;
    }

    public static function make(array $s, int $m): array
    {
        $from = $m & 63;
        $to   = ($m >> 6) & 63;
        $pr   = $m >> 12;
        $b    = $s['b'];
        $t    = $s['t'];
        $p    = $b[$from];
        $cap  = $b[$to];

        $b[$from] = '.';
        if (($p === 'P' || $p === 'p') && $to === $s['ep'] && $cap === '.') {
            $b[$t === 'w' ? $to + 8 : $to - 8] = '.';
            $cap = $t === 'w' ? 'p' : 'P';
        }
        $b[$to] = $pr ? ($t === 'w' ? strtoupper(self::PROMO[$pr]) : self::PROMO[$pr]) : $p;

        if ($p === 'K' && $from === 60) {
            if ($to === 62) {
                $b[63] = '.';
                $b[61] = 'R';
            } elseif ($to === 58) {
                $b[56] = '.';
                $b[59] = 'R';
            }
        } elseif ($p === 'k' && $from === 4) {
            if ($to === 6) {
                $b[7] = '.';
                $b[5] = 'r';
            } elseif ($to === 2) {
                $b[0] = '.';
                $b[3] = 'r';
            }
        }

        $cr = $s['c'];
        if ($cr !== '') {
            if ($p === 'K') {
                $cr = str_replace(['K', 'Q'], '', $cr);
            } elseif ($p === 'k') {
                $cr = str_replace(['k', 'q'], '', $cr);
            }
            foreach ([63 => 'K', 56 => 'Q', 7 => 'k', 0 => 'q'] as $sq => $right) {
                if ($from === $sq || $to === $sq) {
                    $cr = str_replace($right, '', $cr);
                }
            }
        }

        $pawn = $p === 'P' || $p === 'p';
        return [
            'b'  => $b,
            't'  => self::opp($t),
            'c'  => $cr,
            'ep' => ($pawn && abs($to - $from) === 16) ? intdiv($from + $to, 2) : -1,
            'h'  => ($pawn || $cap !== '.') ? 0 : $s['h'] + 1,
            'f'  => $t === 'b' ? $s['f'] + 1 : $s['f'],
        ];
    }

    public static function legal(array $s): array
    {
        $out = [];
        foreach (self::pseudo($s) as $m) {
            $n = self::make($s, $m);
            if (!self::inCheck($n, $s['t'])) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public static function legalFrom(array $s, int $from): array
    {
        return array_values(array_filter(self::legal($s), static fn($m) => ($m & 63) === $from));
    }

    public static function key(array $s): string
    {
        return $s['b'] . $s['t'] . $s['c'] . $s['ep'];
    }

    public static function insufficient(string $b): bool
    {
        $pieces = preg_replace('/[.Kk]/', '', $b);
        if ($pieces === '') {
            return true;
        }
        if (strlen($pieces) === 1 && strpbrk($pieces, 'NBnb') !== false) {
            return true;
        }
        return false;
    }

    public static function outcome(array $s, array $repetitions = []): ?string
    {
        if (!self::legal($s)) {
            return self::inCheck($s) ? 'mate' : 'stalemate';
        }
        if (self::insufficient($s['b'])) {
            return 'material';
        }
        if ($s['h'] >= 100) {
            return 'fifty';
        }
        if (($repetitions[self::key($s)] ?? 0) >= 3) {
            return 'repetition';
        }
        return null;
    }

    public static function square(int $sq): string
    {
        return chr(97 + $sq % 8) . (8 - intdiv($sq, 8));
    }

    public static function san(array $s, int $m): string
    {
        $from = $m & 63;
        $to   = ($m >> 6) & 63;
        $pr   = $m >> 12;
        $p    = strtoupper($s['b'][$from]);
        $cap  = $s['b'][$to] !== '.' || ($p === 'P' && $to === $s['ep']);

        if ($p === 'K' && abs($to - $from) === 2) {
            $san = $to > $from ? 'O-O' : 'O-O-O';
        } elseif ($p === 'P') {
            $san = ($cap ? self::square($from)[0] . 'x' : '') . self::square($to) . ($pr ? '=' . strtoupper(self::PROMO[$pr]) : '');
        } else {
            $amb  = '';
            $same = array_filter(self::legal($s), static fn($o) => $o !== $m && (($o >> 6) & 63) === $to && strtoupper($s['b'][$o & 63]) === $p);
            if ($same) {
                $fileClash = false;
                $rankClash = false;
                foreach ($same as $o) {
                    $fileClash = $fileClash || (($o & 63) % 8 === $from % 8);
                    $rankClash = $rankClash || (intdiv($o & 63, 8) === intdiv($from, 8));
                }
                $sqName = self::square($from);
                $amb    = !$fileClash ? $sqName[0] : (!$rankClash ? $sqName[1] : $sqName);
            }
            $san = $p . $amb . ($cap ? 'x' : '') . self::square($to);
        }

        $n = self::make($s, $m);
        if (self::inCheck($n)) {
            $san .= self::legal($n) ? '+' : '#';
        }
        return $san;
    }

    private const VALUE = ['P' => 100, 'N' => 320, 'B' => 330, 'R' => 500, 'Q' => 900, 'K' => 0];

    private const PST = [
        'P' => [0, 0, 0, 0, 0, 0, 0, 0, 50, 50, 50, 50, 50, 50, 50, 50, 10, 10, 20, 30, 30, 20, 10, 10, 5, 5, 10, 25, 25, 10, 5, 5, 0, 0, 0, 20, 20, 0, 0, 0, 5, -5, -10, 0, 0, -10, -5, 5, 5, 10, 10, -20, -20, 10, 10, 5, 0, 0, 0, 0, 0, 0, 0, 0],
        'N' => [-50, -40, -30, -30, -30, -30, -40, -50, -40, -20, 0, 0, 0, 0, -20, -40, -30, 0, 10, 15, 15, 10, 0, -30, -30, 5, 15, 20, 20, 15, 5, -30, -30, 0, 15, 20, 20, 15, 0, -30, -30, 5, 10, 15, 15, 10, 5, -30, -40, -20, 0, 5, 5, 0, -20, -40, -50, -40, -30, -30, -30, -30, -40, -50],
        'B' => [-20, -10, -10, -10, -10, -10, -10, -20, -10, 0, 0, 0, 0, 0, 0, -10, -10, 0, 5, 10, 10, 5, 0, -10, -10, 5, 5, 10, 10, 5, 5, -10, -10, 0, 10, 10, 10, 10, 0, -10, -10, 10, 10, 10, 10, 10, 10, -10, -10, 5, 0, 0, 0, 0, 5, -10, -20, -10, -10, -10, -10, -10, -10, -20],
        'R' => [0, 0, 0, 0, 0, 0, 0, 0, 5, 10, 10, 10, 10, 10, 10, 5, -5, 0, 0, 0, 0, 0, 0, -5, -5, 0, 0, 0, 0, 0, 0, -5, -5, 0, 0, 0, 0, 0, 0, -5, -5, 0, 0, 0, 0, 0, 0, -5, -5, 0, 0, 0, 0, 0, 0, -5, 0, 0, 0, 5, 5, 0, 0, 0],
        'Q' => [-20, -10, -10, -5, -5, -10, -10, -20, -10, 0, 0, 0, 0, 0, 0, -10, -10, 0, 5, 5, 5, 5, 0, -10, -5, 0, 5, 5, 5, 5, 0, -5, 0, 0, 5, 5, 5, 5, 0, -5, -10, 5, 5, 5, 5, 5, 0, -10, -10, 0, 5, 0, 0, 0, 0, -10, -20, -10, -10, -5, -5, -10, -10, -20],
        'K' => [-30, -40, -40, -50, -50, -40, -40, -30, -30, -40, -40, -50, -50, -40, -40, -30, -30, -40, -40, -50, -50, -40, -40, -30, -30, -40, -40, -50, -50, -40, -40, -30, -20, -30, -30, -40, -40, -30, -30, -20, -10, -20, -20, -20, -20, -20, -20, -10, 20, 20, 0, 0, 0, 0, 20, 20, 20, 30, 10, 0, 0, 10, 30, 20],
        'E' => [-50, -40, -30, -20, -20, -30, -40, -50, -30, -20, -10, 0, 0, -10, -20, -30, -30, -10, 20, 30, 30, 20, -10, -30, -30, -10, 30, 40, 40, 30, -10, -30, -30, -10, 30, 40, 40, 30, -10, -30, -30, -10, 20, 30, 30, 20, -10, -30, -30, -30, 0, 0, 0, 0, -30, -30, -50, -30, -30, -30, -30, -30, -30, -50],
    ];

    private const MATE = 100000;

    private static float $deadline = 0.0;
    private static int $nodes = 0;
    private static bool $stop = false;

    public static function evaluate(array $s): int
    {
        $b   = $s['b'];
        $mat = 0;
        for ($i = 0; $i < 64; $i++) {
            $p = $b[$i];
            if ($p !== '.' && $p !== 'P' && $p !== 'p' && $p !== 'K' && $p !== 'k') {
                $mat += self::VALUE[strtoupper($p)];
            }
        }
        $endgame = $mat <= 1300;
        $score   = 0;
        for ($i = 0; $i < 64; $i++) {
            $p = $b[$i];
            if ($p === '.') {
                continue;
            }
            $u     = strtoupper($p);
            $table = ($u === 'K' && $endgame) ? 'E' : $u;
            if ($p === $u) {
                $score += self::VALUE[$u] + self::PST[$table][$i];
            } else {
                $score -= self::VALUE[$u] + self::PST[$table][(7 - intdiv($i, 8)) * 8 + $i % 8];
            }
        }
        return $s['t'] === 'w' ? $score : -$score;
    }

    private static function order(array $s, array $moves, int $first = 0): array
    {
        $b      = $s['b'];
        $scored = [];
        foreach ($moves as $m) {
            $v   = 0;
            $cap = $b[($m >> 6) & 63];
            if ($cap !== '.') {
                $v = 10000 + 10 * self::VALUE[strtoupper($cap)] - self::VALUE[strtoupper($b[$m & 63])];
            }
            if ($m >> 12) {
                $v += 8000;
            }
            if ($m === $first) {
                $v = PHP_INT_MAX;
            }
            $scored[] = [$v, $m];
        }
        usort($scored, static fn($x, $y) => $y[0] <=> $x[0]);
        return array_column($scored, 1);
    }

    private static function quiesce(array $s, int $alpha, int $beta, int $depth): int
    {
        self::$nodes++;
        $stand = self::evaluate($s);
        if ($stand >= $beta) {
            return $beta;
        }
        if ($stand > $alpha) {
            $alpha = $stand;
        }
        if ($depth >= 4) {
            return $alpha;
        }
        foreach (self::order($s, self::pseudo($s, true)) as $m) {
            $n = self::make($s, $m);
            if (self::inCheck($n, $s['t'])) {
                continue;
            }
            $v = -self::quiesce($n, -$beta, -$alpha, $depth + 1);
            if ($v >= $beta) {
                return $beta;
            }
            if ($v > $alpha) {
                $alpha = $v;
            }
        }
        return $alpha;
    }

    private static function negamax(array $s, int $depth, int $alpha, int $beta, int $ply): int
    {
        if ((++self::$nodes & 511) === 0 && microtime(true) > self::$deadline) {
            self::$stop = true;
        }
        if (self::$stop) {
            return 0;
        }
        if ($depth <= 0) {
            return self::quiesce($s, $alpha, $beta, 0);
        }
        $moves = self::legal($s);
        if (!$moves) {
            return self::inCheck($s) ? -self::MATE + $ply : 0;
        }
        if ($s['h'] >= 100) {
            return 0;
        }
        $best = -PHP_INT_MAX;
        foreach (self::order($s, $moves) as $m) {
            $v = -self::negamax(self::make($s, $m), $depth - 1, -$beta, -$alpha, $ply + 1);
            if (self::$stop) {
                return 0;
            }
            if ($v > $best) {
                $best = $v;
            }
            if ($v > $alpha) {
                $alpha = $v;
            }
            if ($alpha >= $beta) {
                break;
            }
        }
        return $best;
    }

    public static function bestMove(array $s, int $level): ?int
    {
        $moves = self::legal($s);
        if (!$moves) {
            return null;
        }

        [$maxDepth, $seconds, $blunder] = match ($level) {
            1       => [1, 1.0, 35],
            2       => [3, 2.0, 0],
            default => [5, 4.0, 0],
        };

        if ($blunder && random_int(1, 100) <= $blunder) {
            return $moves[array_rand($moves)];
        }

        self::$deadline = microtime(true) + $seconds;
        self::$nodes    = 0;
        self::$stop     = false;

        $ordered = self::order($s, $moves);
        $best    = $ordered[0];

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $alpha     = -PHP_INT_MAX;
            $iterBest  = null;
            $scores    = [];
            foreach ($ordered as $m) {
                $v = -self::negamax(self::make($s, $m), $depth - 1, -PHP_INT_MAX, -$alpha, 1);
                if (self::$stop) {
                    break;
                }
                $scores[$m] = $v;
                if ($iterBest === null || $v > $alpha) {
                    $alpha    = $v;
                    $iterBest = $m;
                }
            }
            if ($iterBest !== null && !self::$stop) {
                $best = $iterBest;
                if ($level === 1 && $depth === $maxDepth) {
                    $near = array_keys(array_filter($scores, static fn($v) => $v >= $alpha - 40));
                    $best = $near[array_rand($near)];
                }
                if ($alpha >= self::MATE - 100) {
                    break;
                }
            }
            if (self::$stop) {
                break;
            }
            $ordered = self::order($s, $moves, $best);
        }
        return $best;
    }
}
