<?php

final class Telegram
{
    private string $base;
    private $ch = null;

    public function __construct(private string $token, private bool $premium = false)
    {
        $this->base = "https://api.telegram.org/bot{$token}/";
    }

    public function token(): string
    {
        return $this->token;
    }

    private function handle()
    {
        if ($this->ch === null) {
            $this->ch = curl_init();
            curl_setopt_array($this->ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TCP_KEEPALIVE  => 1,
            ]);
        }
        return $this->ch;
    }

    public function call(string $method, array $params = [], int $timeout = 20): ?array
    {
        if ($this->premium && ($alt = Premium::apply($method, $params)) !== null) {
            $r = $this->exec($method, $alt, $timeout);
            if ($r === null || !empty($r['ok']) || !preg_match('/emoji|entit|icon|button/i', (string) ($r['description'] ?? ''))) {
                return $r;
            }
        }
        return $this->exec($method, $params, $timeout);
    }

    private function exec(string $method, array $params, int $timeout): ?array
    {
        foreach ($params as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } elseif (is_array($v)) {
                $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } elseif (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            }
        }

        $tries = 0;
        while (true) {
            $ch = $this->handle();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $this->base . $method,
                CURLOPT_POSTFIELDS     => $params,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);

            if ($response === false) {
                logWarn('tg', "{$method}: network error: " . curl_error($ch));
                curl_close($ch);
                $this->ch = null;
                if (++$tries >= 10) {
                    return null;
                }
                usleep(400000);
                continue;
            }

            $data = json_decode((string) $response, true);
            if (!is_array($data)) {
                return null;
            }

            if (empty($data['ok'])) {
                $code = (int) ($data['error_code'] ?? 0);
                $desc = (string) ($data['description'] ?? '');

                if ($code === 429) {
                    if (++$tries >= 10) {
                        return $data;
                    }
                    $wait = (int) ($data['parameters']['retry_after'] ?? 1);
                    sleep(max(1, $wait));
                    continue;
                }
                if (stripos($desc, 'not modified') === false && stripos($desc, 'query is too old') === false && !($code === 409 && $method === 'getUpdates')) {
                    logWarn('tg', "{$method} {$code}: {$desc}");
                }
            }
            return $data;
        }
    }

    public function multi(string $method, array $list, int $timeout = 10): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($list as $k => $params) {
            $ch = curl_init($this->base . $method);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => array_map(static fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v, $params),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($hs as $k => $ch) {
            $data    = json_decode((string) curl_multi_getcontent($ch), true);
            $out[$k] = is_array($data) ? $data : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    public function send(int|string $chatId, string $text, array $extra = []): ?array
    {
        return $this->call('sendMessage', $extra + [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    public function reply(array $msg, string $text, array $extra = []): ?array
    {
        return $this->send($msg['chat']['id'], $text, $extra + [
            'reply_parameters' => ['message_id' => $msg['message_id'], 'allow_sending_without_reply' => true],
        ]);
    }

    public function editInline(string $inlineId, string $text, ?array $markup = null, bool $html = true): ?array
    {
        return $this->call('editMessageText', [
            'inline_message_id'    => $inlineId,
            'text'                 => $text,
            'parse_mode'           => $html ? 'HTML' : null,
            'reply_markup'         => $markup,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $markup = null): ?array
    {
        return $this->call('editMessageText', [
            'chat_id'              => $chatId,
            'message_id'           => $messageId,
            'text'                 => $text,
            'parse_mode'           => 'HTML',
            'reply_markup'         => $markup,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    public function answer(array $cq, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $cq['id'],
            'text'              => $text !== null ? mb_substr($text, 0, 200) : null,
            'show_alert'        => $alert ? true : null,
        ]);
    }

    public function alert(array $cq, string $text): void
    {
        $this->answer($cq, $text, true);
    }

    public function action(int|string $chatId, string $action = 'typing'): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action], 8);
    }

    public function react(int|string $chatId, int $messageId, string $emoji): void
    {
        $this->call('setMessageReaction', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'reaction'   => [['type' => 'emoji', 'emoji' => $emoji]],
        ], 8);
    }

    public function download(string $fileId): ?string
    {
        $info = $this->call('getFile', ['file_id' => $fileId]);
        $path = $info['result']['file_path'] ?? null;
        if (!$path) {
            return null;
        }
        $r = Http::request('GET', "https://api.telegram.org/file/bot{$this->token}/{$path}", ['timeout' => 40]);
        return $r['code'] === 200 ? $r['body'] : null;
    }

    public function photoFileId(int|string $chatId, string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? 'image/jpeg';
        $ext  = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $tmp  = tempnam(sys_get_temp_dir(), 'nl_img_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return null;
        }
        try {
            $r = $this->call('sendPhoto', [
                'chat_id'              => $chatId,
                'photo'                => new CURLFile($tmp, $mime, "nullini.{$ext}"),
                'disable_notification' => true,
            ], 90);
        } finally {
            @unlink($tmp);
        }
        if (empty($r['ok'])) {
            return null;
        }
        $sizes = $r['result']['photo'] ?? [];
        $id    = end($sizes)['file_id'] ?? null;
        if (isset($r['result']['message_id'])) {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $r['result']['message_id']]);
        }
        return $id;
    }

    public function voiceFileId(int|string $chatId, string $file): ?string
    {
        $r = $this->call('sendVoice', [
            'chat_id'              => $chatId,
            'voice'                => new CURLFile($file, 'audio/ogg', 'voice.ogg'),
            'disable_notification' => true,
        ], 180);
        if (empty($r['ok'])) {
            return null;
        }
        $id = $r['result']['voice']['file_id'] ?? null;
        if (isset($r['result']['message_id'])) {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $r['result']['message_id']]);
        }
        return $id;
    }

    public function sendPhotoBytes(int|string $chatId, string $bytes, string $caption = '', ?int $replyTo = null, bool $ai = false): bool
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? 'image/jpeg';
        $ext  = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $tmp  = tempnam(sys_get_temp_dir(), 'nl_img_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return false;
        }
        try {
            $r = $this->call('sendPhoto', [
                'chat_id'          => $chatId,
                'photo'            => new CURLFile($tmp, $mime, "nullini.{$ext}"),
                'caption'          => $caption !== '' ? $caption : null,
                'parse_mode'       => 'HTML',
                'reply_parameters' => $replyTo ? ['message_id' => $replyTo, 'allow_sending_without_reply' => true] : null,
            ], 90);
        } finally {
            @unlink($tmp);
        }
        if ($ai && !empty($r['ok'])) {
            AI::markMsg($chatId, $r['result']['message_id'] ?? 0);
        }
        return !empty($r['ok']);
    }

    public function sendAnimationBytes(int|string $chatId, string $bytes, string $caption = '', ?int $replyTo = null): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nl_gif_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return false;
        }
        try {
            $r = $this->call('sendAnimation', [
                'chat_id'          => $chatId,
                'animation'        => new CURLFile($tmp, 'image/gif', 'nullini.gif'),
                'caption'          => $caption !== '' ? $caption : null,
                'parse_mode'       => 'HTML',
                'reply_parameters' => $replyTo ? ['message_id' => $replyTo, 'allow_sending_without_reply' => true] : null,
            ], 90);
        } finally {
            @unlink($tmp);
        }
        return !empty($r['ok']);
    }
}
