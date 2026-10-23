<?php

final class Fonts
{
    public const MAX_LEN = 60;

    private const MATH = [
        'bold'        => ['𝐁𝐨𝐥𝐝', 0x1D400, 0x1D41A, 0x1D7CE, []],
        'italic'      => ['𝐼𝑡𝑎𝑙𝑖𝑐', 0x1D434, 0x1D44E, null, ['h' => 0x210E]],
        'bolditalic'  => ['𝑩𝒐𝒍𝒅 𝑰𝒕𝒂𝒍𝒊𝒄', 0x1D468, 0x1D482, null, []],
        'script'      => ['𝒮𝒸𝓇𝒾𝓅𝓉', 0x1D49C, 0x1D4B6, null, ['B' => 0x212C, 'E' => 0x2130, 'F' => 0x2131, 'H' => 0x210B, 'I' => 0x2110, 'L' => 0x2112, 'M' => 0x2133, 'R' => 0x211B, 'e' => 0x212F, 'g' => 0x210A, 'o' => 0x2134]],
        'boldscript'  => ['𝓑𝓸𝓵𝓭 𝓢𝓬𝓻𝓲𝓹𝓽', 0x1D4D0, 0x1D4EA, null, []],
        'fraktur'     => ['𝔉𝔯𝔞𝔨𝔱𝔲𝔯', 0x1D504, 0x1D51E, null, ['C' => 0x212D, 'H' => 0x210C, 'I' => 0x2111, 'R' => 0x211C, 'Z' => 0x2128]],
        'boldfraktur' => ['𝕭𝖔𝖑𝖉 𝕱𝖗𝖆𝖐𝖙𝖚𝖗', 0x1D56C, 0x1D586, null, []],
        'double'      => ['𝔻𝕠𝕦𝕓𝕝𝕖', 0x1D538, 0x1D552, 0x1D7D8, ['C' => 0x2102, 'H' => 0x210D, 'N' => 0x2115, 'P' => 0x2119, 'Q' => 0x211A, 'R' => 0x211D, 'Z' => 0x2124]],
        'sans'        => ['𝖲𝖺𝗇𝗌', 0x1D5A0, 0x1D5BA, 0x1D7E2, []],
        'sansbold'    => ['𝗦𝗮𝗻𝘀 𝗕𝗼𝗹𝗱', 0x1D5D4, 0x1D5EE, 0x1D7EC, []],
        'sansitalic'  => ['𝘚𝘢𝘯𝘴 𝘐𝘵𝘢𝘭𝘪𝘤', 0x1D608, 0x1D622, null, []],
        'sansbi'      => ['𝙎𝙖𝙣𝙨 𝘽𝙤𝙡𝙙 𝙄𝙩𝙖𝙡𝙞𝙘', 0x1D63C, 0x1D656, null, []],
        'mono'        => ['𝙼𝚘𝚗𝚘', 0x1D670, 0x1D68A, 0x1D7F6, []],
        'fullwidth'   => ['Ｗｉｄｅ', 0xFF21, 0xFF41, 0xFF10, []],
    ];

    private const SMALLCAPS = ['a' => 'ᴀ', 'b' => 'ʙ', 'c' => 'ᴄ', 'd' => 'ᴅ', 'e' => 'ᴇ', 'f' => 'ꜰ', 'g' => 'ɢ', 'h' => 'ʜ', 'i' => 'ɪ', 'j' => 'ᴊ', 'k' => 'ᴋ', 'l' => 'ʟ', 'm' => 'ᴍ',
        'n' => 'ɴ', 'o' => 'ᴏ', 'p' => 'ᴘ', 'q' => 'ǫ', 'r' => 'ʀ', 's' => 'ꜱ', 't' => 'ᴛ', 'u' => 'ᴜ', 'v' => 'ᴠ', 'w' => 'ᴡ', 'x' => 'x', 'y' => 'ʏ', 'z' => 'ᴢ'];

    private const UPSIDE = ['a' => 'ɐ', 'b' => 'q', 'c' => 'ɔ', 'd' => 'p', 'e' => 'ǝ', 'f' => 'ɟ', 'g' => 'ƃ', 'h' => 'ɥ', 'i' => 'ᴉ', 'j' => 'ɾ', 'k' => 'ʞ', 'l' => 'l', 'm' => 'ɯ',
        'n' => 'u', 'o' => 'o', 'p' => 'd', 'q' => 'b', 'r' => 'ɹ', 's' => 's', 't' => 'ʇ', 'u' => 'n', 'v' => 'ʌ', 'w' => 'ʍ', 'x' => 'x', 'y' => 'ʎ', 'z' => 'z',
        'A' => '∀', 'B' => 'ᗺ', 'C' => 'Ɔ', 'D' => 'ᗡ', 'E' => 'Ǝ', 'F' => 'Ⅎ', 'G' => '⅁', 'J' => 'ſ', 'L' => '˥', 'M' => 'W', 'P' => 'Ԁ', 'Q' => 'Ό', 'R' => 'ᴚ', 'T' => '⊥', 'U' => '∩', 'V' => 'Λ', 'W' => 'M', 'Y' => '⅄',
        '1' => 'Ɩ', '2' => 'ᄅ', '3' => 'Ɛ', '4' => 'ㄣ', '5' => 'ϛ', '6' => '9', '7' => 'ㄥ', '8' => '8', '9' => '6', '0' => '0', '.' => '˙', ',' => "'", '?' => '¿', '!' => '¡', '(' => ')', ')' => '('];

    private const NO_JOIN = ['ا', 'آ', 'أ', 'إ', 'د', 'ذ', 'ر', 'ز', 'ژ', 'و', 'ؤ', 'ة', 'ء'];

    private const FRAMES = [
        ['꧁', '꧂'], ['『', '』'], ['✿ ', ' ✿'], ['﴾ ', ' ﴿'], ['【', '】'], ['★彡 ', ' 彡★'], ['༺ ', ' ༻'],
        ['♡ ', ' ♡'], ['⚡ ', ' ⚡'], ['•°• ', ' •°•'], ['⫷ ', ' ⫸'], ['𓆩', '𓆪'], ['☾ ', ' ☽'], ['╰☆☆ ', ' ☆☆╮'],
    ];

    private static function mapLatin(string $text, callable $fn): string
    {
        $out = '';
        foreach (mb_str_split($text) as $ch) {
            $out .= $fn($ch);
        }
        return $out;
    }

    private static function math(string $text, array $st): string
    {
        [, $A, $a, $zero, $exc] = $st;
        return self::mapLatin($text, static function (string $ch) use ($A, $a, $zero, $exc) {
            if (isset($exc[$ch])) {
                return mb_chr($exc[$ch]);
            }
            $o = ord($ch);
            if (strlen($ch) === 1 && $o >= 65 && $o <= 90) {
                return mb_chr($A + $o - 65);
            }
            if (strlen($ch) === 1 && $o >= 97 && $o <= 122) {
                return mb_chr($a + $o - 97);
            }
            if (strlen($ch) === 1 && $o >= 48 && $o <= 57 && $zero !== null) {
                return mb_chr($zero + $o - 48);
            }
            if ($ch === ' ' && $A === 0xFF21) {
                return '　';
            }
            return $ch;
        });
    }

    private static function circled(string $text, bool $negative): string
    {
        return self::mapLatin($text, static function (string $ch) use ($negative) {
            $o = strlen($ch) === 1 ? ord($ch) : 0;
            if ($negative) {
                if ($o >= 97 && $o <= 122) {
                    $o -= 32;
                }
                return ($o >= 65 && $o <= 90) ? mb_chr(0x1F150 + $o - 65) : $ch;
            }
            if ($o >= 65 && $o <= 90) {
                return mb_chr(0x24B6 + $o - 65);
            }
            if ($o >= 97 && $o <= 122) {
                return mb_chr(0x24D0 + $o - 97);
            }
            if ($o === 48) {
                return '⓪';
            }
            return ($o >= 49 && $o <= 57) ? mb_chr(0x2460 + $o - 49) : $ch;
        });
    }

    private static function squared(string $text, bool $negative): string
    {
        return self::mapLatin($text, static function (string $ch) use ($negative) {
            $o = strlen($ch) === 1 ? ord($ch) : 0;
            if ($o >= 97 && $o <= 122) {
                $o -= 32;
            }
            return ($o >= 65 && $o <= 90) ? mb_chr(($negative ? 0x1F170 : 0x1F130) + $o - 65) : $ch;
        });
    }

    private static function combining(string $text, string $mark): string
    {
        return self::mapLatin($text, static fn(string $ch) => $ch === ' ' ? $ch : $ch . $mark);
    }

    private static function latinStyles(string $t): array
    {
        $out = [];
        foreach (self::MATH as $st) {
            $out[] = [$st[0], self::math($t, $st)];
        }
        $out[] = ['ⓒⓘⓡⓒⓛⓔ', self::circled($t, false)];
        $out[] = ['🅑🅛🅐🅒🅚', self::circled($t, true)];
        $out[] = ['🅂🅀🅄🄰🅁🄴', self::squared($t, false)];
        $out[] = ['🆂🆀🆄🅰🆁🅴', self::squared($t, true)];
        $out[] = ['ꜱᴍᴀʟʟ ᴄᴀᴘꜱ', self::mapLatin(mb_strtolower($t), static fn($c) => self::SMALLCAPS[$c] ?? $c)];
        $out[] = ['uʍop ǝpısdn', implode('', array_reverse(mb_str_split(self::mapLatin($t, static fn($c) => self::UPSIDE[$c] ?? $c))))];
        $out[] = ['S̶t̶r̶i̶k̶e̶', self::combining($t, "\u{0336}")];
        $out[] = ['U̲n̲d̲e̲r̲', self::combining($t, "\u{0332}")];
        $out[] = ['S̾t̾i̾t̾c̾h̾', self::combining($t, "\u{033E}")];
        $out[] = ['S p a c e d', implode(' ', mb_str_split($t))];
        return $out;
    }

    private static function isFa(string $ch): bool
    {
        return preg_match('/^[\x{0620}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}\x{0622}]$/u', $ch) === 1;
    }

    private static function stretch(string $t, string $joiner): string
    {
        $chars = mb_str_split($t);
        $out   = '';
        foreach ($chars as $i => $ch) {
            $out .= $ch;
            $next = $chars[$i + 1] ?? '';
            if (self::isFa($ch) && !in_array($ch, self::NO_JOIN, true) && $next !== '' && self::isFa($next)) {
                $out .= $joiner;
            }
        }
        return $out;
    }

    private static function faStyles(string $t): array
    {
        return [
            ['کشیده', self::stretch($t, 'ـــ')],
            ['کشیده‌ی فتحه‌دار', self::stretch($t, 'ـَـ')],
            ['کشیده‌ی کسره‌دار', self::stretch($t, 'ـِـ')],
            ['تشدیددار', self::stretch($t, 'ـّـ')],
            ['فتحه و کسره', self::stretch($t, 'ـَِـ')],
            ['ستاره‌ای', self::stretch($t, 'ـ۪ٜـ')],
            ['موجی', self::stretch($t, 'ـٰٖـ')],
            ['نقطه‌چین', self::stretch($t, 'ـ۟ـ')],
            ['هلالی', self::stretch($t, 'ـۘـ')],
            ['سکون‌دار', self::stretch($t, 'ـْـ')],
            ['خیلی کشیده', self::stretch($t, 'ـــــ')],
        ];
    }

    public static function all(string $text): array
    {
        $t = mb_substr(mbTrim($text), 0, self::MAX_LEN);
        if ($t === '') {
            return [];
        }
        $hasFa    = preg_match('/[\x{0600}-\x{06FF}]/u', $t) === 1;
        $hasLatin = preg_match('/[A-Za-z0-9]/', $t) === 1;
        $styles   = [];
        if ($hasFa) {
            $styles = array_merge($styles, self::faStyles($t));
        }
        if ($hasLatin) {
            $styles = array_merge($styles, self::latinStyles($t));
        }
        $fancy = $hasFa ? self::stretch($t, 'ـِـ') : self::math($t, self::MATH['boldscript']);
        foreach (self::FRAMES as $i => [$l, $r]) {
            $styles[] = ['قاب ' . trim($l), $l . ($i % 2 ? $fancy : $t) . $r];
        }
        $seen = [];
        return array_values(array_filter($styles, static function ($s) use (&$seen, $t) {
            if ($s[1] === $t || isset($seen[$s[1]])) {
                return false;
            }
            return $seen[$s[1]] = true;
        }));
    }

    public static function listText(string $text): string
    {
        $all = array_slice(self::all($text), 0, 30);
        if (!$all) {
            return Ui::guide('font');
        }
        $lines = [];
        foreach ($all as $i => [, $styled]) {
            $lines[] = fa($i + 1) . '. <code>' . h($styled) . '</code>';
        }
        return "<b>✨ فونت‌های «" . h(mb_substr($text, 0, self::MAX_LEN)) . "»</b>\n<i>روی هر کدوم بزنی کپی می‌شه 👇</i>\n\n" . implode("\n", $lines);
    }
}
