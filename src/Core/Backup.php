<?php

final class Backup
{
    public static int $timeout = 20;

    private const FILE  = 'Nullini.db';
    private const MAGIC = "SQLite format 3\0";

    private static ?string $repo = null;

    private static function cfg(string $k)
    {
        return ((array) App::cfg('backup', []))[$k] ?? null;
    }

    private static function token(): string
    {
        return trim((string) self::cfg('token'));
    }

    private static function branch(): string
    {
        return (string) (self::cfg('branch') ?: 'data');
    }

    private static function deployId(): string
    {
        return (string) env('RAILWAY_DEPLOYMENT_ID', 'local-' . gethostname());
    }

    public static function enabled(): bool
    {
        return Db::driver() === 'sqlite' && self::token() !== '' && self::repo() !== '';
    }

    private static function gh(string $method, string $path, ?array $body = null, string $accept = 'application/vnd.github+json'): array
    {
        $url = str_starts_with($path, 'https://') ? $path : 'https://api.github.com' . $path;
        $r   = Http::request($method, $url, [
            'headers' => [
                'Authorization: Bearer ' . self::token(),
                'Accept: ' . $accept,
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
            ],
            'body'    => $body !== null ? json_encode($body) : '',
            'timeout'         => self::$timeout,
            'connect_timeout' => 8,
        ]);
        $r['json'] = ($r['body'] !== null && $accept !== 'application/vnd.github.raw+json') ? json_decode($r['body'], true) : null;
        return $r;
    }

    public static function repo(): string
    {
        if (self::$repo !== null) {
            return self::$repo;
        }
        $repo = trim((string) self::cfg('repo'));
        if ($repo === '' && env('RAILWAY_GIT_REPO_OWNER') && env('RAILWAY_GIT_REPO_NAME')) {
            $repo = env('RAILWAY_GIT_REPO_OWNER') . '/' . env('RAILWAY_GIT_REPO_NAME');
        }
        if ($repo === '' && self::token() !== '') {
            $list = self::gh('GET', '/user/repos?per_page=100&sort=updated')['json'] ?? [];
            if (is_array($list)) {
                foreach ($list as $r) {
                    if (stripos($r['name'] ?? '', 'nullini') !== false) {
                        $repo = $r['full_name'];
                        break;
                    }
                }
                if ($repo === '' && count($list) === 1) {
                    $repo = $list[0]['full_name'] ?? '';
                }
            }
        }
        return self::$repo = preg_match('#^[\w.-]+/[\w.-]+$#', $repo) ? $repo : '';
    }

    private static function r(string $path): string
    {
        return '/repos/' . self::repo() . $path;
    }

    private static function head(): ?array
    {
        $ref = self::gh('GET', self::r('/git/ref/heads/' . rawurlencode(self::branch())));
        $sha = $ref['json']['object']['sha'] ?? null;
        if ($ref['code'] !== 200 || !$sha) {
            return null;
        }
        $c = self::gh('GET', self::r('/git/commits/' . $sha))['json'] ?? [];
        return ['sha' => $sha, 'message' => (string) ($c['message'] ?? ''), 'tree' => $c['tree']['sha'] ?? null];
    }

    private static function waitForHandoff(): void
    {
        for ($waited = 0; $waited <= 25; $waited += 3) {
            $h = self::head();
            if ($h === null || !preg_match('/deploy:(\S+) · (\w+) · at:(\d+)/', $h['message'], $m)) {
                return;
            }
            [, $deploy, $kind, $at] = $m;
            if ($deploy === self::deployId() || $kind === 'final' || time() - (int) $at > 180) {
                return;
            }
            sleep(3);
        }
    }

    public static function pull(): string
    {
        self::$timeout = 60;
        try {
            return self::doPull();
        } finally {
            self::$timeout = 20;
        }
    }

    private static function doPull(): string
    {
        $path    = Db::sqlitePath();
        $bundled = NULLINI_ROOT . '/' . self::FILE;

        if (!self::enabled()) {
            if (!is_file($path) && is_file($bundled)) {
                copy($bundled, $path);
                return 'using bundled Nullini.db (GitHub sync off)';
            }
            return 'GitHub sync off (GITHUB_TOKEN empty), data is lost on every deploy';
        }

        self::waitForHandoff();
        $r = self::gh('GET', self::r('/contents/' . self::FILE . '?ref=' . rawurlencode(self::branch())), null, 'application/vnd.github.raw+json');

        if ($r['code'] === 200 && is_string($r['body']) && str_starts_with($r['body'], self::MAGIC)) {
            if (Db::inRam() && strlen($r['body']) * 3 > (float) @disk_free_space(dirname($path))) {
                Db::useDisk();
                $path = Db::sqlitePath();
            }
            $tmp = $path . '.download';
            file_put_contents($tmp, $r['body']);
            if (!self::verify($tmp)) {
                @unlink($tmp);
                return 'GitHub copy is corrupt, keeping local database';
            }
            @unlink($path . '-wal');
            @unlink($path . '-shm');
            rename($tmp, $path);
            self::markPushed(self::fingerprint());
            return 'pulled Nullini.db from GitHub · ' . round(strlen($r['body']) / 1024) . ' KB' . (Db::inRam() ? ' · in RAM' : '');
        }
        if ($r['code'] === 404) {
            if (is_file($bundled) && !is_file($path)) {
                copy($bundled, $path);
                return 'not on GitHub yet, starting from bundled Nullini.db';
            }
            return 'first run, creating a new database';
        }
        return "pull failed (HTTP {$r['code']}), keeping local database";
    }

    public static function fingerprint(): string
    {
        return (string) @file_get_contents(Db::changeMarker());
    }

    private static function stateFile(): string
    {
        return NULLINI_DATA . '/.last_push';
    }

    public static function markPushed(string $fingerprint): void
    {
        @file_put_contents(self::stateFile(), $fingerprint);
    }

    public static function lastPushed(): string
    {
        return (string) @file_get_contents(self::stateFile());
    }

    public static function lastPushAt(): int
    {
        return is_file(self::stateFile()) ? (int) filemtime(self::stateFile()) : 0;
    }

    public static function isDirty(): bool
    {
        return self::fingerprint() !== self::lastPushed();
    }

    public static function verify(string $file): bool
    {
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $ok  = $pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok';
            $pdo = null;
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function snapshot(): string
    {
        $tmp = sys_get_temp_dir() . '/nullini_snap_' . getmypid() . '_' . mt_rand() . '.db';
        @unlink($tmp);
        try {
            Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($tmp));
            $bytes = self::verify($tmp) ? (string) file_get_contents($tmp) : '';
        } finally {
            @unlink($tmp);
        }
        return $bytes;
    }

    private static function backoffFile(): string
    {
        return NULLINI_DATA . '/.sync_backoff';
    }

    public static function backoffUntil(): int
    {
        return (int) @file_get_contents(self::backoffFile());
    }

    private static function backoff(int $seconds): void
    {
        @file_put_contents(self::backoffFile(), (string) (time() + $seconds));
    }

    private static function fail(string $step, array $r): string
    {
        if (in_array($r['code'], [403, 429], true)) {
            self::backoff(90);
        }
        logError('sync', "{$step} HTTP {$r['code']}: " . mb_substr((string) ($r['json']['message'] ?? $r['body']), 0, 200));
        return "push failed at {$step} (HTTP {$r['code']}), check token permission Contents: read and write";
    }

    public static function push(string $kind = 'live'): string
    {
        if (!self::enabled()) {
            return 'GitHub sync off (GITHUB_TOKEN empty)';
        }
        $fp    = self::fingerprint();
        $bytes = self::snapshot();
        if (!str_starts_with($bytes, self::MAGIC)) {
            return 'snapshot failed, push skipped to keep the GitHub copy safe';
        }

        $blob = self::gh('POST', self::r('/git/blobs'), ['content' => base64_encode($bytes), 'encoding' => 'base64']);
        $sha  = $blob['json']['sha'] ?? null;
        if (!$sha) {
            return self::fail('blob', $blob);
        }
        $tree = self::gh('POST', self::r('/git/trees'), ['tree' => [['path' => self::FILE, 'mode' => '100644', 'type' => 'blob', 'sha' => $sha]]]);
        if (empty($tree['json']['sha'])) {
            return self::fail('tree', $tree);
        }
        $users  = (int) Db::value('SELECT COUNT(*) FROM users');
        $commit = self::gh('POST', self::r('/git/commits'), [
            'message' => 'nullini db · deploy:' . self::deployId() . " · {$kind} · at:" . time() . " · {$users} users",
            'tree'    => $tree['json']['sha'],
            'parents' => [],
        ]);
        if (empty($commit['json']['sha'])) {
            return self::fail('commit', $commit);
        }
        $ref = self::gh('PATCH', self::r('/git/refs/heads/' . rawurlencode(self::branch())), ['sha' => $commit['json']['sha'], 'force' => true]);
        if ($ref['code'] === 422 || $ref['code'] === 404) {
            $ref = self::gh('POST', self::r('/git/refs'), ['ref' => 'refs/heads/' . self::branch(), 'sha' => $commit['json']['sha']]);
        }
        if ($ref['code'] !== 200 && $ref['code'] !== 201) {
            return self::fail('ref', $ref);
        }

        self::markPushed($fp);
        return "ok · pushed Nullini.db ({$kind}) · {$users} users · " . round(strlen($bytes) / 1024) . ' KB';
    }
}
