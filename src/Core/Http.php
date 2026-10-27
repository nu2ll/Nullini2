<?php

final class Http
{
    public static function request(string $method, string $url, array $opt = []): array
    {
        $ch = curl_init($url);
        $set = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $opt['timeout'] ?? 15,
            CURLOPT_CONNECTTIMEOUT => $opt['connect_timeout'] ?? 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NulliniBot/3.0)',
            CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method !== 'GET') {
            $set[CURLOPT_CUSTOMREQUEST] = $method;
            $set[CURLOPT_POSTFIELDS]    = $opt['body'] ?? '';
        }
        curl_setopt_array($ch, $set);

        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ['code' => $code, 'body' => $body === false ? null : (string) $body, 'error' => $error, 'url' => $final];
    }

    public static function getJson(string $url, array $headers = [], int $timeout = 12): ?array
    {
        $r = self::request('GET', $url, ['headers' => array_merge(['Accept: application/json'], $headers), 'timeout' => $timeout]);
        if ($r['body'] === null || $r['code'] >= 400) {
            return null;
        }
        $data = json_decode($r['body'], true);
        return is_array($data) ? $data : null;
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): array
    {
        $r = self::request('POST', $url, [
            'headers' => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'timeout' => $timeout,
        ]);
        $data = $r['body'] !== null ? json_decode($r['body'], true) : null;
        return ['code' => $r['code'], 'data' => is_array($data) ? $data : null, 'raw' => $r['body'] ?? $r['error']];
    }

    public static function stream(string $url, array $payload, array $headers, int $timeout, callable $onChunk): array
    {
        $err = '';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: text/event-stream'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NulliniBot/3.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use (&$err, $onChunk): int {
                if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                    $onChunk($data);
                } elseif (strlen($err) < 20000) {
                    $err .= $data;
                }
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'raw' => $err, 'error' => $error];
    }

    public static function sseData(string &$buf, string $chunk): array
    {
        $buf .= $chunk;
        $out  = [];
        while (($pos = strpos($buf, "\n")) !== false) {
            $line = rtrim(substr($buf, 0, $pos), "\r");
            $buf  = substr($buf, $pos + 1);
            if (str_starts_with($line, 'data:')) {
                $out[] = ltrim(substr($line, 5));
            }
        }
        return $out;
    }
}
