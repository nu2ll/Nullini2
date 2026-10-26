<?php

final class Search
{
    public static function googleUrl(string $q, string $type = ''): string
    {
        return 'https://www.google.com/search?' . http_build_query(['q' => $q, 'hl' => 'fa'] + ($type !== '' ? ['tbm' => $type] : []));
    }

    public static function link(string $q, string $t = 'a'): string
    {
        return self::googleUrl($q, ['i' => 'isch', 'n' => 'nws', 'v' => 'vid'][$t] ?? '');
    }

    public static function keyboard(string $q): array
    {
        return ikb([
            [urlBtn('🌐 نتایج گوگل', self::link($q), 'success')],
            [urlBtn('🖼 عکس‌ها', self::link($q, 'i'), null), urlBtn('📰 اخبار', self::link($q, 'n'), null), urlBtn('🎬 ویدیو', self::link($q, 'v'), null)],
        ]);
    }

    public static function favicon(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'google.com';
        return 'https://www.google.com/s2/favicons?sz=128&domain=' . rawurlencode($host);
    }

    public static function results(string $q, int $limit = 6): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $list = Store::remember('cache', 'search_' . md5(mb_strtolower($q)), 3600, static fn() => self::fetch($q, $limit));
        return is_array($list) ? array_slice($list, 0, $limit) : [];
    }

    public static function fetch(string $q, int $limit): ?array
    {
        $r = Http::request('GET', 'https://html.duckduckgo.com/html/?' . http_build_query(['q' => $q, 'kl' => 'wt-wt']), [
            'headers'         => ['Accept: text/html', 'Accept-Language: fa,en;q=0.8', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'],
            'timeout'         => 6,
            'connect_timeout' => 4,
        ]);
        if ((int) ($r['code'] ?? 0) !== 200 || !is_string($r['body'] ?? null) || $r['body'] === '' || !class_exists('DOMDocument')) {
            return null;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $r['body']);
        libxml_clear_errors();
        $xp  = new DOMXPath($dom);
        $out = [];
        foreach ($xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' result ')]") ?: [] as $node) {
            $cls = (string) $node->getAttribute('class');
            if (str_contains($cls, 'result--ad')) {
                continue;
            }
            $a = $xp->query(".//a[contains(@class, 'result__a')]", $node)->item(0);
            if (!$a) {
                continue;
            }
            $href = html_entity_decode((string) $a->getAttribute('href'));
            if (str_contains($href, 'uddg=')) {
                parse_str((string) parse_url($href, PHP_URL_QUERY), $qs);
                $href = (string) ($qs['uddg'] ?? '');
            }
            if (!preg_match('#^https?://#i', $href) || str_contains($href, 'duckduckgo.com/y.js')) {
                continue;
            }
            $sn    = $xp->query(".//*[contains(@class, 'result__snippet')]", $node)->item(0);
            $out[] = [
                'title'   => mb_substr(trim(preg_replace('/\s+/u', ' ', $a->textContent)), 0, 120),
                'url'     => $href,
                'snippet' => $sn ? mb_substr(trim(preg_replace('/\s+/u', ' ', $sn->textContent)), 0, 300) : '',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out ?: null;
    }

    public static function resultText(array $r): string
    {
        return '🔗 <b>' . h($r['title']) . '</b>' . ($r['snippet'] !== '' ? "\n\n" . h($r['snippet']) : '') . "\n\n" . h($r['url']);
    }
}
