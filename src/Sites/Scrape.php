<?php

final class Scrape
{
    public const UA        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    public static function unescape(string $s): string
    {
        $s = preg_replace('#\\\\+u0026#', '&', $s) ?? $s;
        $s = preg_replace('#\\\\+/#', '/', $s) ?? $s;
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function unique(array $urls): array
    {
        $seen = [];
        $out  = [];
        foreach ($urls as $u) {
            $k = (string) strtok((string) $u, '?');
            if ($u !== '' && preg_match('#^https?://#', $u) && !isset($seen[$k])) {
                $seen[$k] = true;
                $out[]    = $u;
            }
        }
        return $out;
    }
}
