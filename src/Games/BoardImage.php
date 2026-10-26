<?php




final class BoardImage
{
    public const START = 'rnbqkbnrpppppppp' . '................................' . 'PPPPPPPPRNBQKBNR';

    private const FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ];

    
    private const SOLID   = ['k' => '♚', 'q' => '♛', 'r' => '♜', 'b' => '♝', 'n' => '♞', 'p' => '♟'];

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext') && function_exists('imagejpeg') && self::font() !== null;
    }

    private static function font(): ?string
    {
        foreach (self::FONTS as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    private static function hex($im, string $hex, int $alpha = 0)
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    
    private static function glyph($im, string $font, float $size, string $ch, int $cx, int $cy, $color): void
    {
        $box = imagettfbbox($size, 0, $font, $ch);
        $w   = $box[2] - $box[0];
        $h   = $box[1] - $box[7];
        imagettftext($im, $size, 0, (int) ($cx - $w / 2 - $box[0]), (int) ($cy + $h / 2 - $box[1]), $color, $font, $ch);
    }

    



    public static function render(string $board, array $last = [], int $check = -1, int $px = 720): ?string
    {
        $font = self::font();
        if (!self::available() || strlen($board) !== 64) {
            return null;
        }
        $px = max(160, min(1024, $px));
        $sq = intdiv($px, 8);
        $px = $sq * 8;
        $im = imagecreatetruecolor($px, $px);
        imagealphablending($im, true);
        imageantialias($im, true);

        $light   = self::hex($im, '#EEEED2');
        $dark    = self::hex($im, '#769656');
        $lightHl = self::hex($im, '#F6F669');
        $darkHl  = self::hex($im, '#BACA2B');
        $checkC  = self::hex($im, '#E8383B', 30);
        $shadow  = self::hex($im, '#000000', 95);
        $white   = self::hex($im, '#FFFFFF');
        $black   = self::hex($im, '#1E1E1E');
        $edge    = self::hex($im, '#222222');
        $halo    = self::hex($im, '#E8E8E8');

        $size = $sq * 0.62;
        for ($i = 0; $i < 64; $i++) {
            $r      = intdiv($i, 8);
            $c      = $i % 8;
            $x      = $c * $sq;
            $y      = $r * $sq;
            $isDark = ($r + $c) % 2 === 1;
            $bg     = in_array($i, $last, true) ? ($isDark ? $darkHl : $lightHl) : ($isDark ? $dark : $light);
            imagefilledrectangle($im, $x, $y, $x + $sq - 1, $y + $sq - 1, $bg);
            if ($i === $check) {
                imagefilledellipse($im, $x + intdiv($sq, 2), $y + intdiv($sq, 2), (int) ($sq * 0.95), (int) ($sq * 0.95), $checkC);
            }

            $p = $board[$i];
            if ($p !== '.') {
                $k  = strtolower($p);
                $cx = $x + intdiv($sq, 2);
                $cy = $y + intdiv($sq, 2) + (int) ($sq * 0.02);
                self::glyph($im, $font, $size, self::SOLID[$k], $cx + 2, $cy + 3, $shadow);
                $white_ = ctype_upper($p);
                
                $rad = $white_ ? 2.0 : 1.2;
                for ($a = 0; $a < 12; $a++) {
                    $ox = (int) round($rad * cos($a * M_PI / 6));
                    $oy = (int) round($rad * sin($a * M_PI / 6));
                    self::glyph($im, $font, $size, self::SOLID[$k], $cx + $ox, $cy + $oy, $white_ ? $edge : $halo);
                }
                self::glyph($im, $font, $size, self::SOLID[$k], $cx, $cy, $white_ ? $white : $black);
            }
        }

        
        $cs = max(7, $sq * 0.14);
        for ($c = 0; $c < 8; $c++) {
            $col = $c % 2 === 0 ? $light : $dark; 
            imagettftext($im, $cs, 0, $c * $sq + $sq - (int) ($cs * 1.1), $px - (int) ($cs * 0.45), $col, $font, chr(97 + $c));
        }
        for ($r = 0; $r < 8; $r++) {
            $col = $r % 2 === 0 ? $dark : $light;
            imagettftext($im, $cs, 0, (int) ($cs * 0.35), $r * $sq + (int) ($cs * 1.45), $col, $font, (string) (8 - $r));
        }

        ob_start();
        imagejpeg($im, null, 90);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}
