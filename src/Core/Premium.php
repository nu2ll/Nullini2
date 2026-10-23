<?php

final class Premium
{
    private static ?array $map = null;
    private static string $re = '';
    private static string $alt = '';

    private static function map(): array
    {
        if (self::$map === null) {
            self::$map = [];
            foreach (array_reverse((array) App::cfg('premium_packs', [])) as $pack) {
                foreach (self::pack((string) $pack) as $emoji => $id) {
                    self::$map[$emoji] = $id;
                }
            }
            $keys = array_keys(self::$map);
            usort($keys, static fn($a, $b) => strlen($b) <=> strlen($a));
            self::$alt = implode('|', array_map(static fn($k) => preg_quote($k, '/'), $keys));
            self::$re  = $keys ? '/(?:' . self::$alt . ')\x{FE0F}?(?!\x{200D})/u' : '';
        }
        return self::$map;
    }

    public static function warm(): int
    {
        return count(self::map());
    }

    private static function pack(string $name): array
    {
        $name = trim(basename(trim($name)));
        if ($name === '') {
            return [];
        }
        $r = App::tgFor(1)->call('getStickerSet', ['name' => $name], 15);
        if (empty($r['ok'])) {
            return [];
        }
        $out = [];
        foreach ((array) ($r['result']['stickers'] ?? []) as $st) {
            $e  = str_replace("\u{FE0F}", '', (string) ($st['emoji'] ?? ''));
            $id = (string) ($st['custom_emoji_id'] ?? '');
            if ($e !== '' && $id !== '' && !isset($out[$e])) {
                $out[$e] = $id;
            }
        }
        return $out;
    }

    private static function html(string $s): string
    {
        $map = self::map();
        if (self::$re === '' || $s === '') {
            return $s;
        }
        $parts = preg_split('/(<[^>]*>)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $s;
        }
        $skip = 0;
        foreach ($parts as $i => $p) {
            if ($p !== '' && $p[0] === '<') {
                if (preg_match('/^<(\/?)(code|pre|tg-emoji)\b/i', $p, $m)) {
                    $skip = max(0, $skip + ($m[1] === '/' ? -1 : 1));
                }
                continue;
            }
            if ($skip === 0) {
                $parts[$i] = preg_replace_callback(self::$re, static function ($m) use ($map) {
                    $id = $map[str_replace("\u{FE0F}", '', $m[0])] ?? null;
                    return $id === null ? $m[0] : '<tg-emoji emoji-id="' . $id . '">' . $m[0] . '</tg-emoji>';
                }, $p) ?? $p;
            }
        }
        return implode('', $parts);
    }

    private static function field(array &$arr, string $key): void
    {
        if (isset($arr[$key]) && is_string($arr[$key]) && ($arr['parse_mode'] ?? null) === 'HTML') {
            $arr[$key] = self::html($arr[$key]);
        }
    }

    private static function buttons($markup)
    {
        if (!is_array($markup) || !isset($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
            return $markup;
        }
        $map = self::map();
        foreach ($markup['inline_keyboard'] as $r => $row) {
            foreach ((array) $row as $c => $b) {
                if (!is_array($b) || !isset($b['text']) || isset($b['icon_custom_emoji_id']) || self::$re === '') {
                    continue;
                }
                if (preg_match('/^((?:' . self::$alt . ')\x{FE0F}?)(?!\x{200D})\s*(.*)$/us', (string) $b['text'], $m) && trim($m[2]) !== '') {
                    $id = $map[str_replace("\u{FE0F}", '', $m[1])] ?? null;
                    if ($id !== null) {
                        $markup['inline_keyboard'][$r][$c]['icon_custom_emoji_id'] = $id;
                        $markup['inline_keyboard'][$r][$c]['text']                 = $m[2];
                    }
                }
            }
        }
        return $markup;
    }

    public static function apply(string $method, array $params): ?array
    {
        self::map();
        if (self::$re === '') {
            return null;
        }
        $before = $params;
        switch ($method) {
            case 'sendMessage':
            case 'editMessageText':
                self::field($params, 'text');
                break;
            case 'sendPhoto':
            case 'sendVideo':
            case 'sendAudio':
            case 'sendDocument':
            case 'sendAnimation':
            case 'sendVoice':
            case 'editMessageCaption':
                self::field($params, 'caption');
                break;
            case 'editMessageMedia':
                if (isset($params['media']) && is_array($params['media'])) {
                    self::field($params['media'], 'caption');
                }
                break;
            case 'sendMediaGroup':
                foreach ((array) ($params['media'] ?? []) as $i => $m) {
                    if (is_array($m)) {
                        self::field($params['media'][$i], 'caption');
                    }
                }
                break;
            case 'answerInlineQuery':
                foreach ((array) ($params['results'] ?? []) as $i => $res) {
                    if (!is_array($res)) {
                        continue;
                    }
                    if (isset($res['input_message_content']) && is_array($res['input_message_content'])) {
                        self::field($params['results'][$i]['input_message_content'], 'message_text');
                    }
                    self::field($params['results'][$i], 'caption');
                }
                break;
            case 'answerGuestQuery':
                if (isset($params['result']) && is_array($params['result'])) {
                    if (isset($params['result']['input_message_content']) && is_array($params['result']['input_message_content'])) {
                        self::field($params['result']['input_message_content'], 'message_text');
                    }
                    self::field($params['result'], 'caption');
                }
                break;
            case 'editMessageReplyMarkup':
                break;
            default:
                return null;
        }
        if (isset($params['chat_id']) && isset($params['reply_markup'])) {
            $params['reply_markup'] = self::buttons($params['reply_markup']);
        }
        return $params == $before ? null : $params;
    }
}
