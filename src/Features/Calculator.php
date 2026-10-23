<?php

final class Calculator
{
    public const ERROR = 'خطا';

    public static function keyboard(): array
    {
        $k = static fn(string $label, string $key, string $style = 'primary') => btn($label, "calc:{$key}", $style);
        return ikb([
            [$k('C', 'C', 'danger'), $k('(', '('), $k(')', ')'), $k('÷', '/')],
            [$k('7', '7'), $k('8', '8'), $k('9', '9'), $k('×', '*')],
            [$k('4', '4'), $k('5', '5'), $k('6', '6'), $k('−', '-')],
            [$k('1', '1'), $k('2', '2'), $k('3', '3'), $k('+', '+')],
            [$k('±', 'neg'), $k('0', '0'), $k('.', '.'), $k('=', '=', 'success')],
            [$k('⌫', 'back'), $k('%', '%'), $k('xʸ', '^')],
        ]);
    }

    public static function render(string $expr, bool $done = false): string
    {
        $pretty = strtr($expr, ['*' => '×', '/' => '÷', '-' => '−']);
        if ($done) {
            return "<b>🧮 ماشین‌حساب نالینی</b>\n\n= <b>" . h($pretty) . '</b>';
        }
        return "<b>🧮 ماشین‌حساب نالینی</b>\n\n<code>" . h($pretty) . '</code>';
    }

    public static function press(string $expr, string $key): string
    {
        if ($expr === self::ERROR) {
            $expr = '0';
        }
        $ops  = ['+', '-', '*', '/', '^'];
        $last = substr($expr, -1);

        switch ($key) {
            case 'C':
                return '0';
            case 'back':
                $new = substr($expr, 0, -1);
                return ($new === '' || $new === '-') ? '0' : $new;
            case '=':
                return self::evaluate($expr);
            case 'neg':
                if (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
                    return $expr[0] === '-' ? substr($expr, 1) : ($expr === '0' ? '0' : '-' . $expr);
                }
                return $expr;
            case '%':
                if (preg_match('/^(.*?)(\d+(?:\.\d+)?)$/', $expr, $m)) {
                    return $m[1] . self::format((float) $m[2] / 100);
                }
                return $expr;
            case '(':
                if ($expr === '0') {
                    return '(';
                }
                return (ctype_digit($last) || $last === ')') ? $expr . '*(' : $expr . '(';
            case ')':
                $open = substr_count($expr, '(') - substr_count($expr, ')');
                return ($open > 0 && (ctype_digit($last) || $last === ')')) ? $expr . ')' : $expr;
            case '.':
                if (preg_match('/[\d.]*$/', $expr, $m) && str_contains($m[0], '.')) {
                    return $expr;
                }
                return ctype_digit($last) ? $expr . '.' : $expr . '0.';
        }

        if (in_array($key, $ops, true)) {
            if (in_array($last, $ops, true)) {
                return substr($expr, 0, -1) . $key;
            }
            if ($last === '(' && $key !== '-') {
                return $expr;
            }
            return $expr . $key;
        }

        if (ctype_digit($key)) {
            if ($expr === '0') {
                return $key;
            }
            return $last === ')' ? $expr . '*' . $key : $expr . $key;
        }
        return $expr;
    }

    public static function looksLikeExpression(string $text): bool
    {
        $t = self::normalize($text);
        return $t !== ''
            && strlen($t) <= 120
            && preg_match('/^[\d+\-*\/^().\s]+$/', $t)
            && preg_match('/\d/', $t)
            && preg_match('/[+\-*\/^]/', ltrim($t, '-'));
    }

    public static function normalize(string $text): string
    {
        $t = en($text);
        $t = strtr($t, ['×' => '*', '÷' => '/', 'x' => '*', 'X' => '*', '−' => '-', '٫' => '.', '،' => '.', '**' => '^']);
        return trim(preg_replace('/^=+|=+$/', '', $t) ?? $t);
    }

    public static function evaluate(string $expr): string
    {
        try {
            preg_match_all('/\d+(?:\.\d+)?|[+\-*\/^()]/', self::normalize($expr), $m);
            $tokens = $m[0];
            if (!$tokens) {
                return '0';
            }
            $open = count(array_keys($tokens, '(')) - count(array_keys($tokens, ')'));
            for ($i = 0; $i < $open; $i++) {
                $tokens[] = ')';
            }
            $pos   = 0;
            $value = self::parseSum($tokens, $pos);
            if ($pos !== count($tokens) || !is_finite($value)) {
                return self::ERROR;
            }
            return self::format($value);
        } catch (\Throwable) {
            return self::ERROR;
        }
    }

    private static function parseSum(array $t, int &$p): float
    {
        $v = self::parseProduct($t, $p);
        while (isset($t[$p]) && ($t[$p] === '+' || $t[$p] === '-')) {
            $op = $t[$p++];
            $r  = self::parseProduct($t, $p);
            $v  = $op === '+' ? $v + $r : $v - $r;
        }
        return $v;
    }

    private static function parseProduct(array $t, int &$p): float
    {
        $v = self::parsePower($t, $p);
        while (isset($t[$p]) && ($t[$p] === '*' || $t[$p] === '/')) {
            $op = $t[$p++];
            $r  = self::parsePower($t, $p);
            if ($op === '/') {
                if ($r == 0.0) {
                    throw new RuntimeException('div0');
                }
                $v /= $r;
            } else {
                $v *= $r;
            }
        }
        return $v;
    }

    private static function parsePower(array $t, int &$p): float
    {
        $base = self::parseUnary($t, $p);
        if (isset($t[$p]) && $t[$p] === '^') {
            $p++;
            $exp = self::parsePower($t, $p);
            return $base ** $exp;
        }
        return $base;
    }

    private static function parseUnary(array $t, int &$p): float
    {
        if (isset($t[$p]) && ($t[$p] === '-' || $t[$p] === '+')) {
            $neg = $t[$p++] === '-';
            $v   = self::parseUnary($t, $p);
            return $neg ? -$v : $v;
        }
        return self::parsePrimary($t, $p);
    }

    private static function parsePrimary(array $t, int &$p): float
    {
        if (!isset($t[$p])) {
            throw new RuntimeException('incomplete');
        }
        if ($t[$p] === '(') {
            $p++;
            $v = self::parseSum($t, $p);
            if (($t[$p] ?? null) !== ')') {
                throw new RuntimeException('paren');
            }
            $p++;
            return $v;
        }
        if (!is_numeric($t[$p])) {
            throw new RuntimeException('number');
        }
        return (float) $t[$p++];
    }

    public static function format(float $n): string
    {
        if (abs($n) >= 1e15) {
            return sprintf('%.6e', $n);
        }
        if (floor($n) == $n) {
            return (string) (int) $n;
        }
        $s = rtrim(rtrim(number_format($n, 10, '.', ''), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }
}
