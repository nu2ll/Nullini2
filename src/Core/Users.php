<?php

final class Users
{
    public static function touch(array $from, bool $started = false): void
    {
        $id = (int) ($from['id'] ?? 0);
        if ($id <= 0 || !empty($from['is_bot'])) {
            return;
        }
        $now = time();
        try {
            Db::run(
                'INSERT INTO users (id, name, username, lang, is_premium, started, blocked, first_seen, last_seen)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET
                    name       = excluded.name,
                    username   = excluded.username,
                    lang       = excluded.lang,
                    is_premium = excluded.is_premium,
                    started    = CASE WHEN excluded.started = 1 THEN 1 ELSE users.started END,
                    blocked    = CASE WHEN excluded.started = 1 THEN 0 ELSE users.blocked END,
                    last_seen  = excluded.last_seen
                 WHERE users.last_seen < excluded.last_seen - 3600 OR excluded.started = 1',
                [
                    $id,
                    mb_substr(userName($from), 0, 255),
                    isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : null,
                    isset($from['language_code']) ? mb_substr((string) $from['language_code'], 0, 16) : null,
                    !empty($from['is_premium']) ? 1 : 0,
                    $started ? 1 : 0,
                    $now,
                    $now,
                ]
            );
        } catch (\Throwable $e) {
            logError('db', 'users touch: ' . $e->getMessage());
        }
    }

    public static function markBlocked(int $id): void
    {
        Db::run('UPDATE users SET blocked = 1 WHERE id = ?', [$id]);
    }

    public static function summary(): array
    {
        $now = time();
        return Db::row(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN started = 1 AND blocked = 0 THEN 1 ELSE 0 END) AS reachable,
                    SUM(CASE WHEN last_seen > ? THEN 1 ELSE 0 END) AS day,
                    SUM(CASE WHEN last_seen > ? THEN 1 ELSE 0 END) AS week,
                    SUM(CASE WHEN first_seen > ? THEN 1 ELSE 0 END) AS new_today,
                    SUM(is_premium) AS premium
             FROM users',
            [$now - 86400, $now - 7 * 86400, $now - 86400]
        ) ?? [];
    }

    public static function eachReachable(callable $fn): void
    {
        $last = 0;
        do {
            $ids = Db::run('SELECT id FROM users WHERE started = 1 AND blocked = 0 AND id > ? ORDER BY id LIMIT 500', [$last])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $fn((int) $id);
                $last = (int) $id;
            }
        } while (count($ids) === 500);
    }

    public static function hit(string $event): void
    {
        try {
            Db::run(
                'INSERT INTO stats (day, event, hits) VALUES (?, ?, 1)
                 ON CONFLICT (day, event) DO UPDATE SET hits = stats.hits + 1',
                [(int) date('Ymd'), mb_substr($event, 0, 60)]
            );
        } catch (\Throwable $e) {
            logError('db', 'stats hit: ' . $e->getMessage());
        }
    }

    public static function topEvents(int $days = 1, int $limit = 10): array
    {
        $from = (int) date('Ymd', time() - ($days - 1) * 86400);
        return Db::run(
            'SELECT event, SUM(hits) AS hits FROM stats WHERE day >= ? GROUP BY event ORDER BY hits DESC LIMIT ' . (int) $limit,
            [$from]
        )->fetchAll();
    }

    public static function cleanupStats(int $keepDays = 120): void
    {
        Db::run('DELETE FROM stats WHERE day < ?', [(int) date('Ymd', time() - $keepDays * 86400)]);
    }
}
