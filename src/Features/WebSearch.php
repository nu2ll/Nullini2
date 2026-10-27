<?php

final class WebSearch
{
    public const MAX_ROUNDS = 10;

    private const URL = 'https://api.tavily.com/search';

    public static function keys(): array
    {
        return array_values(array_unique(envList('TAVILY_KEYS')));
    }

    public static function available(): bool
    {
        return self::keys() !== [];
    }

    public static function declaration(): array
    {
        return [
            'name'        => 'web_search',
            'description' => 'Search the web and get a short summary plus up to 10 results (title, URL, snippet). Use it whenever you judge that fresh or verified information from the internet would help you answer better. Pass one short, specific query. Base your answer on the results, and if they are empty or unclear, say so.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required'   => ['query'],
            ],
        ];
    }

    public static function run(string $q, float $deadline): string
    {
        $q = trim(mb_substr($q, 0, 200));
        if ($q === '') {
            return 'Empty query.';
        }
        $data = self::fetch($q, $deadline);
        if (!is_array($data) || empty($data['results'])) {
            return 'Web search returned nothing or is unavailable right now. Answer from what you know and say you could not verify it.';
        }

        $out = '';
        if (!empty($data['answer'])) {
            $out .= 'Summary: ' . $data['answer'] . "\n\n";
        }
        foreach (array_slice((array) $data['results'], 0, 10) as $i => $r) {
            $url = (string) ($r['url'] ?? '');
            if ($url !== '') {
                AI::$sources[] = ['t' => (string) ($r['title'] ?? ''), 'u' => $url];
            }
            $out .= ($i + 1) . '. ' . ($r['title'] ?? '') . ' (' . $url . ")\n" . mb_substr((string) ($r['content'] ?? ''), 0, 1000) . "\n\n";
        }
        return trim($out);
    }

    private static function fetch(string $q, float $deadline): ?array
    {
        $keys = self::keys();
        $n    = count($keys);
        if ($n === 0) {
            return null;
        }
        $start = random_int(0, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $left = $deadline - microtime(true);
            if ($left < 3) {
                return null;
            }
            $key = $keys[($start + $i) % $n];
            $r   = Http::postJson(self::URL, [
                'query'          => $q,
                'search_depth'   => 'basic',
                'max_results'    => 10,
                'include_answer' => true,
            ], ['Authorization: Bearer ' . $key], (int) max(3, min($left, 10)));

            if ($r['code'] === 200 && is_array($r['data'])) {
                return [
                    'answer'  => (string) ($r['data']['answer'] ?? ''),
                    'results' => array_values((array) ($r['data']['results'] ?? [])),
                ];
            }
            logWarn('search', "tavily key{$i} HTTP {$r['code']}: " . mb_substr((string) ($r['data']['detail']['error'] ?? $r['raw']), 0, 160));
            if (!in_array($r['code'], [0, 401, 403, 429, 432, 433, 500, 502, 503, 504], true)) {
                return null;
            }
        }
        return null;
    }
}
