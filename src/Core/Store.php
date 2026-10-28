<?php

final class Store
{
    private static function encode($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function decode($raw)
    {
        return $raw === null ? null : json_decode((string) $raw, true);
    }

    public static function get(string $ns, string $key, $default = null)
    {
        $v = self::decode(Db::value('SELECT v FROM kv WHERE ns = ? AND k = ?', [$ns, $key]));
        return $v ?? $default;
    }

    public static function dayStart(): int
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran')))->getTimestamp();
    }

    public static function getToday(string $ns, string $key, $default = null)
    {
        $v = self::decode(Db::value('SELECT v FROM kv WHERE ns = ? AND k = ? AND updated_at >= ?', [$ns, $key, self::dayStart()]));
        return $v ?? $default;
    }

    public static function cleanupDaily(array $namespaces): int
    {
        $removed = 0;
        foreach ($namespaces as $ns) {
            $removed += Db::run('DELETE FROM kv WHERE ns = ? AND updated_at < ?', [$ns, self::dayStart()])->rowCount();
        }
        return $removed;
    }

    public static function set(string $ns, string $key, $value): void
    {
        Db::run(
            'INSERT INTO kv (ns, k, v, updated_at) VALUES (?, ?, ' . Db::json() . ', ?)
             ON CONFLICT (ns, k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at',
            [$ns, $key, self::encode($value), time()]
        );
    }

    public static function update(string $ns, string $key, callable $fn)
    {
        return Db::atomic("{$ns}|{$key}", static function () use ($ns, $key, $fn) {
            $new = $fn(self::get($ns, $key));
            if ($new !== null) {
                self::set($ns, $key, $new);
            }
            return $new;
        });
    }

    public static function delete(string $ns, string $key): void
    {
        Db::run('DELETE FROM kv WHERE ns = ? AND k = ?', [$ns, $key]);
    }

    public static function remember(string $ns, string $key, int $ttl, callable $producer)
    {
        $hit = self::get($ns, $key);
        if (is_array($hit) && isset($hit['at']) && (time() - $hit['at']) < $ttl) {
            return $hit['v'];
        }
        $value = $producer();
        if ($value !== null) {
            self::set($ns, $key, ['at' => time(), 'v' => $value]);
            return $value;
        }
        return is_array($hit) ? ($hit['v'] ?? null) : null;
    }

    public static function count(string $ns): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM kv WHERE ns = ?', [$ns]);
    }

    public static function cleanup(array $ttls): int
    {
        $removed = 0;
        foreach ($ttls as $ns => $ttl) {
            $removed += Db::run('DELETE FROM kv WHERE ns = ? AND updated_at < ?', [$ns, time() - $ttl])->rowCount();
        }
        return $removed;
    }
}
