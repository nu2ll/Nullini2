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
        curl_close($ch);

        return ['code' => $code, 'body' => $body === false ? null : (string) $body, 'error' => $error];
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
}
