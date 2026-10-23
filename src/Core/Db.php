<?php

final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = '';

    public static function driver(): string
    {
        if (self::$driver === '') {
            self::$driver = env('DATABASE_URL', '') !== '' ? 'pgsql' : 'sqlite';
        }
        return self::$driver;
    }

    public static function isPg(): bool
    {
        return self::driver() === 'pgsql';
    }

    private static ?string $sqlitePath = null;

    public static function sqlitePath(): string
    {
        if (self::$sqlitePath !== null) {
            return self::$sqlitePath;
        }
        if ($p = env('SQLITE_PATH')) {
            return self::$sqlitePath = (string) $p;
        }
        $ram = '/dev/shm/nullini';
        $useRam = is_dir($ram) || (
            env('GITHUB_TOKEN') && is_dir('/dev/shm') && is_writable('/dev/shm')
            && (float) @disk_free_space('/dev/shm') > 48 * 1024 * 1024 && @mkdir($ram, 0775, true)
        );
        return self::$sqlitePath = ($useRam ? $ram : NULLINI_DATA) . '/Nullini.db';
    }

    public static function useDisk(): void
    {
        @rmdir('/dev/shm/nullini');
        self::$sqlitePath = NULLINI_DATA . '/Nullini.db';
    }

    public static function inRam(): bool
    {
        return str_starts_with(self::sqlitePath(), '/dev/shm/');
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (self::isPg()) {
            $u = parse_url((string) env('DATABASE_URL'));
            if (!$u || empty($u['host'])) {
                throw new RuntimeException('DATABASE_URL نامعتبره');
            }
            parse_str($u['query'] ?? '', $q);
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=10;sslmode=%s;application_name=nullini',
                trim($u['host'], '[]'),
                (int) ($u['port'] ?? 5432),
                ltrim(rawurldecode($u['path'] ?? '/postgres'), '/'),
                $q['sslmode'] ?? 'prefer'
            );
            self::$pdo = new PDO($dsn, rawurldecode($u['user'] ?? ''), rawurldecode($u['pass'] ?? ''), $opts);
        } else {
            self::$pdo = new PDO('sqlite:' . self::sqlitePath(), null, null, $opts);
            foreach ([
                'journal_mode=WAL',
                'busy_timeout=10000',
                'synchronous=' . (self::inRam() ? 'OFF' : 'NORMAL'),
                'temp_store=MEMORY',
                'cache_size=-16000',
                'mmap_size=134217728',
                'foreign_keys=ON',
            ] as $pragma) {
                self::$pdo->exec('PRAGMA ' . $pragma);
            }
        }
        return self::$pdo;
    }

    public static function changeMarker(): string
    {
        return dirname(self::sqlitePath()) . '/.changed';
    }

    private static function changed(): void
    {
        if (self::driver() === 'sqlite') {
            @file_put_contents(self::changeMarker(), sprintf('%.6f-%d', microtime(true), getmypid()));
        }
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        for ($try = 0; ; $try++) {
            try {
                $st = self::pdo()->prepare($sql);
                $st->execute($params);
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) && $st->rowCount() > 0) {
                    self::changed();
                }
                return $st;
            } catch (PDOException $e) {
                $lost = str_starts_with((string) $e->getCode(), '08')
                    || preg_match('/server closed|no connection|terminating connection|broken pipe|gone away/i', $e->getMessage());
                if ($try === 0 && $lost && !(self::$pdo?->inTransaction() ?? false)) {
                    self::disconnect();
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function json(): string
    {
        return self::isPg() ? 'CAST(? AS JSON)' : '?';
    }

    public static function atomic(string $lock, callable $fn)
    {
        $pdo = self::pdo();
        if (self::isPg()) {
            $pdo->beginTransaction();
            self::run('SELECT pg_advisory_xact_lock(hashtext(?))', [$lock]);
        } else {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            $result = $fn();
            self::isPg() ? $pdo->commit() : $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            try {
                self::isPg() ? $pdo->rollBack() : $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                self::disconnect();
            }
            throw $e;
        }
    }

    public static function migrate(): void
    {
        $json = self::isPg() ? 'JSON' : 'TEXT';
        $stmts = [
            "CREATE TABLE IF NOT EXISTS kv (
                ns         VARCHAR(40)  NOT NULL,
                k          VARCHAR(255) NOT NULL,
                v          {$json},
                updated_at BIGINT       NOT NULL,
                PRIMARY KEY (ns, k)
            )",
            'CREATE INDEX IF NOT EXISTS kv_ns_updated ON kv (ns, updated_at)',

            'CREATE TABLE IF NOT EXISTS users (
                id         BIGINT PRIMARY KEY,
                name       VARCHAR(255),
                username   VARCHAR(64),
                lang       VARCHAR(16),
                is_premium SMALLINT NOT NULL DEFAULT 0,
                started    SMALLINT NOT NULL DEFAULT 0,
                blocked    SMALLINT NOT NULL DEFAULT 0,
                first_seen BIGINT   NOT NULL,
                last_seen  BIGINT   NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS users_last_seen ON users (last_seen)',

            'CREATE TABLE IF NOT EXISTS gw_entries (
                gid  VARCHAR(16)  NOT NULL,
                uid  BIGINT       NOT NULL,
                name VARCHAR(255),
                at   BIGINT       NOT NULL,
                PRIMARY KEY (gid, uid)
            )',

            'CREATE TABLE IF NOT EXISTS stats (
                day   INTEGER     NOT NULL,
                event VARCHAR(60) NOT NULL,
                hits  BIGINT      NOT NULL DEFAULT 0,
                PRIMARY KEY (day, event)
            )',
        ];
        foreach ($stmts as $sql) {
            self::pdo()->exec($sql);
        }
    }
}
