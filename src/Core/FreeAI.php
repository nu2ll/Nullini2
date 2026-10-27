<?php

final class FreeAI
{
    public const PROVIDERS = [
        'groq'       => ['name' => 'Groq', 'url' => 'https://api.groq.com/openai/v1'],
    ];

    // لیست ثابت مدل‌ها (دیگه از API گرفته نمی‌شه). برای کم/زیاد کردن فقط همین لیست رو عوض کن.
    private const MODELS = [
        'groq' => [
            'openai/gpt-oss-120b',
            'openai/gpt-oss-20b',
            'qwen/qwen3.8-27b',
        ],
    ];
    private const VISION = '/scout|maverick|vision|llava|\bvl\b|qwen3\.\d/i';

    public static function keys(string $prov): array
    {
        $keys = array_map('trim', (array) (App::cfg('ai', [])['free_keys'][$prov] ?? []));
        return array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
    }

    public static function active(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $id => $p) {
            if (self::keys($id)) {
                $out[$id] = $p['name'];
            }
        }
        return $out;
    }

    public static function valid(string $route): bool
    {
        [$prov] = explode(':', $route, 2);
        return isset(self::PROVIDERS[$prov]);
    }

    public static function models(string $prov): array
    {
        if (!isset(self::PROVIDERS[$prov])) {
            return [];
        }
        $list = array_map(static fn($id) => ['id' => $id, 'name' => $id, 'vision' => null], self::MODELS[$prov] ?? []);
        if (!empty(App::cfg('ai', [])['free_only'][$prov])) {
            $only = array_map('strval', (array) (App::cfg('ai', [])['free_order'][$prov] ?? []));
            $list = array_values(array_filter($list, static fn($e) => in_array($e['id'] ?? '', $only, true)));
        }
        return $list;
    }

    public static function title(string $prov, string $model): string
    {
        $e = self::entry($prov, $model);
        return $e['name'] ?? $model;
    }

    private static function entry(string $prov, string $model): ?array
    {
        foreach (self::models($prov) as $e) {
            if (($e['id'] ?? '') === $model) {
                return $e;
            }
        }
        return null;
    }

    public static function chat(string $route, array $messages, array $opt = []): ?string
    {
        [$prov, $model] = explode(':', $route, 2) + [1 => ''];
        if (!isset(self::PROVIDERS[$prov]) || !self::keys($prov)) {
            return null;
        }
        if ($model !== '') {
            if (self::hasImage($messages) && preg_match(self::VISION, $model) !== 1) {
                $opt['deadline'] ??= microtime(true) + 60;
                $pool = array_values(array_filter(self::ordered($prov), static fn($m) => preg_match(self::VISION, $m) === 1));
                usort($pool, static fn($a, $b) => preg_match('/qwen/i', $b) <=> preg_match('/qwen/i', $a));
                foreach ($pool as $m) {
                    if (self::isCooling($prov, $m)) {
                        continue;
                    }
                    $r = self::call($prov, $m, $messages, $opt);
                    if ($r['text'] !== null) {
                        return $r['text'];
                    }
                    if ($r['kind'] === 'failed') {
                        self::coolFor($prov, $m, $r['seen']);
                    }
                }
                return null;
            }
            return self::call($prov, $model, $messages, $opt)['text'];
        }
        return self::auto($prov, $messages, $opt);
    }

    public static function transcribe(string $prov, string $bytes, string $mime = 'audio/ogg', ?float $deadline = null): ?string
    {
        $keys   = self::keys($prov);
        $models = array_values(array_filter(array_map('strval', (array) (App::cfg('ai', [])['free_stt'][$prov] ?? []))));
        if ($bytes === '' || !isset(self::PROVIDERS[$prov]) || !$keys || !$models) {
            return null;
        }
        $ext = match (true) {
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3')                                    => 'mp3',
            str_contains($mime, 'mp4') || str_contains($mime, 'm4a') || str_contains($mime, 'aac')       => 'm4a',
            str_contains($mime, 'wav')                                                                   => 'wav',
            str_contains($mime, 'webm')                                                                  => 'webm',
            str_contains($mime, 'flac')                                                                  => 'flac',
            default                                                                                      => 'ogg',
        };
        $tmp = tempnam(sys_get_temp_dir(), 'nstt');
        if ($tmp === false) {
            return null;
        }
        $file = $tmp . '.' . $ext;
        @rename($tmp, $file);
        if (@file_put_contents($file, $bytes) === false) {
            @unlink($file);
            return null;
        }

        $deadline ??= microtime(true) + 40;
        $n        = count($keys);
        $start    = random_int(0, $n - 1);
        $text     = null;

        foreach ($models as $model) {
            for ($i = 0; $i < $n; $i++) {
                $left = $deadline - microtime(true);
                if ($left < 3) {
                    break 2;
                }
                $idx = ($start + $i) % $n;
                $r   = Http::request('POST', self::PROVIDERS[$prov]['url'] . '/audio/transcriptions', [
                    'headers' => ['Authorization: Bearer ' . $keys[$idx]],
                    'body'    => ['file' => new CURLFile($file, $mime, 'voice.' . $ext), 'model' => $model, 'response_format' => 'json', 'temperature' => '0'],
                    'timeout' => (int) max(5, min($left, 20)),
                ]);
                $j = $r['body'] !== null ? json_decode($r['body'], true) : null;
                if ($r['code'] === 200 && is_array($j)) {
                    Users::hit('model:' . $prov . '-' . $model);
                    $t = trim((string) ($j['text'] ?? ''));
                    if ($t === '') {
                        break 2;
                    }
                    $text = $t;
                    break 2;
                }
                logWarn('ai', "{$prov}:{$model} stt key{$idx} HTTP {$r['code']}: " . mb_substr((string) ($j['error']['message'] ?? $r['body'] ?? $r['error']), 0, 160));
                if (!in_array($r['code'], [0, 401, 402, 403, 408, 429, 500, 502, 503, 504], true)) {
                    continue 2;
                }
            }
        }
        @unlink($file);
        return $text;
    }

    private static function size(string $id): float
    {
        return preg_match_all('/(\d+(?:\.\d+)?)b(?![a-z])/i', $id, $m) ? max(array_map('floatval', $m[1])) : 0.0;
    }

    public static function ordered(string $prov): array
    {
        $ids  = array_column(self::models($prov), 'id');
        $skip = array_map('strval', (array) (App::cfg('ai', [])['free_skip'][$prov] ?? []));
        $ids  = array_values(array_diff($ids, $skip));
        $pref = array_values(array_filter(array_map('strval', (array) (App::cfg('ai', [])['free_order'][$prov] ?? []))));
        if (!$ids) {
            return $pref;
        }
        $rank = array_flip($pref);
        usort($ids, static function ($a, $b) use ($rank) {
            return [$rank[$a] ?? PHP_INT_MAX, -self::size($a), $a] <=> [$rank[$b] ?? PHP_INT_MAX, -self::size($b), $b];
        });
        return $ids;
    }

    private static function hasImage(array $messages): bool
    {
        foreach ($messages as $m) {
            foreach (is_array($m['content'] ?? null) ? $m['content'] : [] as $p) {
                if (($p['type'] ?? '') === 'image_url' || (($p['type'] ?? '') === 'media' && str_starts_with((string) ($p['mime'] ?? ''), 'image/'))) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function auto(string $prov, array $messages, array $opt): ?string
    {
        $deadline        = $opt['deadline'] ?? (microtime(true) + 60);
        $opt['deadline'] = $deadline;
        $opt['per_try']  = 15;

        $chain = self::ordered($prov);
        if (self::hasImage($messages)) {
            $vision = array_values(array_filter($chain, static fn($m) => preg_match(self::VISION, $m) === 1));
            $chain  = array_values(array_unique(array_merge($vision, $chain)));
        }

        foreach ($chain as $model) {
            if (self::isCooling($prov, $model)) {
                continue;
            }
            if ($deadline - microtime(true) < 3) {
                return null;
            }
            $r = self::call($prov, $model, $messages, $opt);
            if ($r['text'] !== null) {
                return $r['text'];
            }
            if ($r['kind'] === 'failed') {
                self::coolFor($prov, $model, $r['seen']);
            }
        }
        return null;
    }

    private static function coolKey(string $prov, string $model): string
    {
        return 'cool_' . $prov . '_' . substr(md5($model), 0, 10);
    }

    private static function isCooling(string $prov, string $model): bool
    {
        $c = Store::get('ai', self::coolKey($prov, $model));
        return is_array($c) && (int) ($c['until'] ?? 0) > time();
    }

    private static function retrySeconds(string $raw): int
    {
        if (!preg_match('/try again in\s+(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+(?:\.\d+)?)(m?s))?/i', $raw, $w)) {
            return 0;
        }
        $sec = (int) ($w[1] ?? 0) * 3600 + (int) ($w[2] ?? 0) * 60;
        if (($w[3] ?? '') !== '') {
            $sec += strtolower((string) ($w[4] ?? 's')) === 'ms' ? 1 : (int) ceil((float) $w[3]);
        }
        return $sec;
    }

    private static function coolFor(string $prov, string $model, array $seen): void
    {
        if (isset($seen[429])) {
            $wait = self::retrySeconds((string) $seen[429]);
            if (preg_match('/per ?day|\bTPD\b|\bRPD\b|daily/i', (string) $seen[429])) {
                $until = $wait > 0 ? time() + min($wait + 30, 86400) : (new DateTime('tomorrow', new DateTimeZone('UTC')))->getTimestamp() + 60;
                $why   = 'سهمیه‌ی روزانه تموم شد';
            } else {
                $until = time() + max(10, min($wait > 0 ? $wait : 60, 900));
                $why   = 'محدودیت دقیقه‌ای';
            }
        } elseif (isset($seen[404]) || (isset($seen[400]) && preg_match('/model.{0,80}(not found|decommission|does not exist|not supported|deprecated|no longer)/is', (string) $seen[400]))) {
            $until = time() + 86400;
            $why   = 'مدل پیدا نشد';
        } elseif (isset($seen[401]) || isset($seen[402]) || isset($seen[403])) {
            $until = time() + 3600;
            $why   = 'کلید نامعتبر یا بدون دسترسی';
        } elseif (array_intersect_key($seen, [0 => 1, 408 => 1, 500 => 1, 502 => 1, 503 => 1, 504 => 1])) {
            $until = time() + 120;
            $why   = 'شلوغه / جواب نداد';
        } else {
            return;
        }
        Store::set('ai', self::coolKey($prov, $model), ['until' => $until, 'why' => $why, 'model' => $prov . ':' . $model]);
    }

    private static function call(string $prov, string $model, array $messages, array $opt): array
    {
        $keys = self::keys($prov);
        $msgs = self::convert($messages, (self::entry($prov, $model)['vision'] ?? null) !== false);
        if ($msgs === null) {
            return ['text' => null, 'kind' => 'unsupported', 'seen' => []];
        }

        $route   = $prov . ':' . $model;
        $payload = [
            'model'       => $model,
            'messages'    => $msgs,
            'temperature' => $opt['temperature'] ?? 0.7,
        ];
        $deadline = $opt['deadline'] ?? (microtime(true) + 60);
        $wants    = $prov === 'groq' && !empty($opt['search']) && AI::searchOn();
        $search   = $wants && preg_match('/gpt-oss/i', $model) === 1;
        $tav      = $wants && !$search && WebSearch::available();
        $orig     = $payload['messages'];
        if ($search) {
            $payload['tools'] = [['type' => 'browser_search']];
        } elseif ($tav) {
            $payload['tools'] = [WebSearch::openaiTool()];
        }
        $effort = self::effort($prov, $model, $opt);
        if ($effort !== null) {
            $payload['reasoning_effort'] = $effort;
        }
        AI::$sources = [];
        $rounds   = 0;
        $cap      = $prov === 'groq' ? (($search || $tav) ? 20 : 10) : (int) ($opt['per_try'] ?? 45);
        $url      = self::PROVIDERS[$prov]['url'] . '/chat/completions';
        $n        = count($keys);
        $start    = random_int(0, $n - 1);
        $merged   = false;
        $seen     = [];

        for ($i = 0; $i < $n; $i++) {
            $left = $deadline - microtime(true);
            if ($left < 3) {
                return ['text' => null, 'kind' => 'timeout', 'seen' => $seen];
            }
            $idx = ($start + $i) % $n;
            $r   = $prov !== 'groq' && is_callable($opt['stream'] ?? null)
                ? self::streamOnce($url, $payload, $keys[$idx], (int) max(5, min($left, 55)), $opt['stream'])
                : Http::postJson($url, $payload, ['Authorization: Bearer ' . $keys[$idx]], (int) max(5, min($left, $cap)));

            AI::noteKey($prov, $keys[$idx], (int) $r['code']);
            if ($r['code'] === 200 && $r['data'] !== null) {
                $msg = $r['data']['choices'][0]['message'] ?? [];
                $calls = $tav ? array_values(array_filter((array) ($msg['tool_calls'] ?? []), 'is_array')) : [];
                if ($calls && $rounds < WebSearch::MAX_ROUNDS) {
                    $rounds++;
                    $payload['messages'][] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $calls];
                    foreach ($calls as $ci => $c) {
                        $args = json_decode((string) ($c['function']['arguments'] ?? ''), true);
                        $q    = is_array($args) ? (string) ($args['query'] ?? '') : '';
                        $payload['messages'][] = [
                            'role'         => 'tool',
                            'tool_call_id' => (string) ($c['id'] ?? ''),
                            'content'      => $ci < 2 ? WebSearch::run($q, $deadline) : 'Skipped: too many searches at once.',
                        ];
                    }
                    if ($rounds >= WebSearch::MAX_ROUNDS) {
                        $payload['tool_choice'] = 'none';
                    }
                    $i--;
                    continue;
                }
                $text = self::extract($r['data']);
                if ($text === null) {
                    logWarn('ai', "{$route}: empty reply");
                    return ['text' => null, 'kind' => 'empty', 'seen' => []];
                }
                foreach ((array) ($msg['executed_tools'] ?? []) as $et) {
                    foreach ((array) ($et['search_results']['results'] ?? []) as $sr) {
                        if (!empty($sr['url'])) {
                            AI::$sources[] = ['t' => (string) ($sr['title'] ?? ''), 'u' => (string) $sr['url']];
                        }
                    }
                }
                Users::hit('model:' . $prov . '-' . $model);
                return ['text' => $text, 'kind' => 'ok', 'seen' => []];
            }

            logWarn('ai', "{$route} key{$idx} HTTP {$r['code']}: " . mb_substr((string) ($r['data']['error']['message'] ?? $r['raw']), 0, 160));
            if (($search || $tav) && in_array($r['code'], [400, 429], true) && preg_match('/tool|search|browser|function/i', (string) $r['raw']) === 1) {
                unset($payload['tools'], $payload['tool_choice']);
                $payload['messages'] = $orig;
                $search              = false;
                $tav                 = false;
                $rounds              = 0;
                AI::$sources         = [];
                $i--;
                continue;
            }
            if ($r['code'] === 400 && isset($payload['reasoning_effort']) && preg_match('/reasoning|effort/i', (string) $r['raw']) === 1) {
                unset($payload['reasoning_effort']);
                $i--;
                continue;
            }
            if ($r['code'] === 400 && !$merged && preg_match('/instruction|system/i', (string) $r['raw'])) {
                $payload['messages'] = self::mergeSystem($payload['messages']);
                $merged              = true;
                $i--;
                continue;
            }
            $seen[$r['code']] = (string) $r['raw'];
            if (!in_array($r['code'], [0, 401, 402, 403, 408, 429, 500, 502, 503, 504], true)) {
                break;
            }
        }
        return ['text' => null, 'kind' => 'failed', 'seen' => $seen];
    }

    private static function effort(string $prov, string $model, array $opt): ?string
    {
        if ($prov !== 'groq' || ($opt['section'] ?? '') !== 'chat') {
            return null;
        }
        return AI::fitLevel('groq', $model, AI::thinkLevel('groq'));
    }

    private static function streamOnce(string $url, array $payload, string $key, int $timeout, callable $emit): array
    {
        $payload['stream'] = true;
        $text = '';
        $buf  = '';

        $r = Http::stream($url, $payload, ['Authorization: Bearer ' . $key], $timeout,
            static function (string $chunk) use (&$text, &$buf, $emit): void {
                foreach (Http::sseData($buf, $chunk) as $json) {
                    if ($json === '[DONE]') {
                        continue;
                    }
                    $d     = json_decode($json, true);
                    $delta = is_array($d) ? ($d['choices'][0]['delta']['content'] ?? null) : null;
                    if (is_string($delta) && $delta !== '') {
                        $text .= $delta;
                        $emit($text);
                    }
                }
            });

        if ($r['code'] === 200) {
            if (trim($text) === '' && $r['error'] !== '') {
                return ['code' => 0, 'data' => null, 'raw' => $r['error']];
            }
            return ['code' => 200, 'data' => ['choices' => [['message' => ['content' => $text]]]], 'raw' => ''];
        }

        $data = $r['raw'] !== '' ? json_decode($r['raw'], true) : null;
        return ['code' => $r['code'], 'data' => is_array($data) ? $data : null, 'raw' => $r['raw'] !== '' ? $r['raw'] : $r['error']];
    }

    private static function convert(array $messages, bool $vision): ?array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = in_array($m['role'] ?? 'user', ['system', 'assistant'], true) ? $m['role'] : 'user';
            $c    = $m['content'] ?? '';
            if (!is_array($c)) {
                if (trim((string) $c) !== '') {
                    $out[] = ['role' => $role, 'content' => (string) $c];
                }
                continue;
            }
            $parts = [];
            $image = false;
            foreach ($c as $p) {
                $type = $p['type'] ?? 'text';
                $url  = '';
                if ($type === 'text') {
                    if (($p['text'] ?? '') !== '') {
                        $parts[] = ['type' => 'text', 'text' => (string) $p['text']];
                    }
                } elseif ($type === 'media') {
                    if (!str_starts_with((string) ($p['mime'] ?? ''), 'image/')) {
                        return null;
                    }
                    $url = 'data:' . $p['mime'] . ';base64,' . $p['data'];
                } elseif ($type === 'image_url') {
                    $url = (string) ($p['image_url']['url'] ?? '');
                }
                if ($url !== '') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                    $image   = true;
                }
            }
            if (!$parts) {
                continue;
            }
            if ($image && !$vision) {
                return null;
            }
            $out[] = ['role' => $role, 'content' => $image ? $parts : implode("\n", array_column($parts, 'text'))];
        }
        return $out ?: null;
    }

    private static function mergeSystem(array $messages): array
    {
        $sys  = [];
        $rest = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $sys[] = (string) $m['content'];
            } else {
                $rest[] = $m;
            }
        }
        if (!$sys) {
            return $messages;
        }
        $note = "[دستورالعمل]\n" . implode("\n\n", $sys) . "\n[پایان دستورالعمل]\n\n";
        foreach ($rest as $i => $m) {
            if ($m['role'] !== 'user') {
                continue;
            }
            if (is_array($m['content'])) {
                array_unshift($rest[$i]['content'], ['type' => 'text', 'text' => $note]);
            } else {
                $rest[$i]['content'] = $note . $m['content'];
            }
            break;
        }
        return $rest;
    }

    private static function extract(array $data): ?string
    {
        $c = $data['choices'][0]['message']['content'] ?? null;
        if (is_array($c)) {
            $c = implode('', array_map(static fn($x) => (string) ($x['text'] ?? ''), $c));
        }
        $text = trim((string) preg_replace('#<think>.*?</think>#si', '', (string) $c));
        return $text !== '' ? $text : null;
    }
}
