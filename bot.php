<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from command line: php bot.php');
}

require __DIR__ . '/src/bootstrap.php';
App::boot();
set_time_limit(0);
ini_set('memory_limit', '256M');

define('PID_FILE', NULLINI_DATA . '/bot.pid');
define('LOCK_FILE', NULLINI_DATA . '/bot.lock');
define('CHILD_LIMIT', 120);
define('ALLOWED_UPDATES', ['message', 'inline_query', 'chosen_inline_result', 'callback_query', 'my_chat_member', 'guest_message']);

function out(string $line, string $tag = 'boot'): void
{
    logInfo($tag, $line);
}

function isRunning(): ?int
{
    $pid = is_file(PID_FILE) ? (int) file_get_contents(PID_FILE) : 0;
    if ($pid <= 0) {
        return null;
    }
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0) ? $pid : null;
    }
    return is_dir("/proc/{$pid}") ? $pid : null;
}

function stopBot(): void
{
    $pid = isRunning();
    if ($pid === null) {
        out('not running');
        return;
    }
    function_exists('posix_kill') ? posix_kill($pid, 15) : exec('kill ' . (int) $pid);
    for ($i = 0; $i < 40 && isRunning(); $i++) {
        usleep(250000);
    }
    out(isRunning() ? "still running (pid {$pid})" : 'stopped');
}

function startDaemon(): void
{
    $log = NULLINI_DATA . '/bot.log';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
    sleep(2);
    $pid = isRunning();
    out($pid ? "started in background (pid {$pid}), log: data/bot.log" : 'failed to start, see data/bot.log');
}

function disabled(string $fn): bool
{
    return !function_exists($fn) || in_array($fn, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
}

function workerMode(): string
{
    $want = (string) (App::cfg('polling', [])['workers'] ?? 'auto');
    $fork = !disabled('pcntl_fork') && !disabled('pcntl_waitpid');
    $spawn = !disabled('exec');
    if ($want === 'fork' && $fork) {
        return 'fork';
    }
    if ($want === 'spawn' && $spawn) {
        return 'spawn';
    }
    if ($want === 'inline') {
        return 'inline';
    }
    return $fork ? 'fork' : ($spawn ? 'spawn' : 'inline');
}

function reap(array &$children, bool $block = false): void
{
    while ($children) {
        $pid = pcntl_waitpid(-1, $status, WNOHANG);
        if ($pid <= 0) {
            break;
        }
        unset($children[$pid]);
    }
    $now = time();
    foreach ($children as $pid => $c) {
        if ($now - $c['at'] > $c['limit'] + 5 && function_exists('posix_kill')) {
            posix_kill($pid, SIGKILL);
        }
    }
    if ($block && $children) {
        usleep(50000);
    }
}

function forkChild(callable $job, int $limit, array &$children): int
{
    App::resetConnections();
    $pid = pcntl_fork();
    if ($pid === 0) {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_DFL);
        pcntl_alarm($limit);
        try {
            $job();
        } catch (\Throwable $e) {
            logError('child', $e->getMessage());
        }
        exit(0);
    }
    if ($pid > 0) {
        $children[$pid] = ['at' => time(), 'limit' => $limit];
    }
    return $pid;
}

function background(callable $job, string $mode, array &$children, int $limit = 300): int
{
    if ($mode === 'fork') {
        $pid = forkChild($job, $limit, $children);
        if ($pid > 0) {
            return $pid;
        }
    }
    try {
        $job();
    } catch (\Throwable $e) {
        logError('bg', $e->getMessage());
    }
    return -1;
}

function dispatch(array $update, string $mode, array &$children, int $max): void
{
    if ($mode === 'fork') {
        while (count($children) >= $max) {
            reap($children, true);
        }
        if (forkChild(static fn() => Kernel::handle($update), CHILD_LIMIT, $children) > 0) {
            return;
        }
    }
    if ($mode === 'spawn') {
        $dir = NULLINI_DATA . '/updates';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $update['update_id'] . '.json';
        if (file_put_contents($file, json_encode($update, JSON_UNESCAPED_UNICODE)) !== false) {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --handle ' . escapeshellarg($file) . ' > /dev/null 2>&1 &');
            return;
        }
    }
    Kernel::handle($update);
}

function pollerLoop(int $bot): void
{
    if ($bot <= 0 || !isset(App::tokens()[$bot])) {
        return;
    }
    $lock = fopen(NULLINI_DATA . '/poller' . $bot . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }
    $main = isRunning();
    if ($main === null) {
        return;
    }
    $pcfg    = (array) App::cfg('polling', []);
    $mode    = workerMode();
    $max     = max(1, (int) ($pcfg['max_workers'] ?? 24));
    $running = true;
    if (!disabled('pcntl_signal')) {
        pcntl_async_signals(true);
        $stop = static function () use (&$running) {
            $running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
    $offsetFile = NULLINI_DATA . '/.offset' . $bot;
    $offset     = (int) @file_get_contents($offsetFile);
    $children   = [];
    if ($mode === 'fork' && !disabled('pcntl_signal')) {
        pcntl_signal(SIGCHLD, static function () use (&$children) {
            while (($pid = pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
                unset($children[$pid]);
            }
        });
    }
    $lastUpdate = 0;
    $errors     = 0;
    $conflict   = 0;
    out("poller #{$bot} online · {$mode} · premium emoji " . Premium::warm());

    while ($running && isRunning() === $main) {
        if ($mode === 'fork') {
            reap($children);
        }
        $timeout = time() - $lastUpdate < 15 ? 1 : max(1, (int) ($pcfg['timeout'] ?? 10));
        $r       = App::tgFor($bot)->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ALLOWED_UPDATES], $timeout + 15);

        if (empty($r['ok'])) {
            if ((int) ($r['error_code'] ?? 0) === 409) {
                if (!$conflict) {
                    $conflict = time();
                    logWarn('tg', "poller #{$bot}: another instance is still polling (409), waiting for it to stop");
                    App::tgFor($bot)->call('deleteWebhook');
                }
                sleep(3);
                continue;
            }
            $errors++;
            sleep(min(30, 2 * $errors));
            continue;
        }
        $errors   = 0;
        $conflict = 0;

        $updates = $r['result'] ?? [];
        if ($updates) {
            $lastUpdate = time();
        }
        foreach ($updates as $u) {
            $offset      = (int) $u['update_id'] + 1;
            $u['_bot']   = $bot;
            dispatch($u, $mode, $children, $max);
        }
        if ($updates) {
            @file_put_contents($offsetFile, (string) $offset);
        }
    }

    if ($mode === 'fork') {
        $deadline = time() + 10;
        while ($children && time() < $deadline) {
            reap($children, true);
        }
        foreach (array_keys($children) as $pid) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
        }
    }
    flock($lock, LOCK_UN);
}

function ensurePoller(int $bot): void
{
    $f = fopen(NULLINI_DATA . '/poller' . $bot . '.lock', 'c');
    if ($f === false) {
        return;
    }
    if (flock($f, LOCK_EX | LOCK_NB)) {
        flock($f, LOCK_UN);
        fclose($f);
        $log = is_writable('/proc/1/fd/1') ? '/proc/1/fd/1' : NULLINI_DATA . '/bot.log';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --poller ' . $bot . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
        return;
    }
    fclose($f);
}

function bootExtraBots(array $pcfg): array
{
    $ok = [];
    foreach (App::tokens() as $bi => $_) {
        if ($bi === 0) {
            continue;
        }
        $tg = App::tgFor($bi);
        $me = $tg->call('getMe');
        if (empty($me['ok'])) {
            logError('boot', "getMe failed for bot #{$bi}: bad token or no connection to Telegram");
            continue;
        }
        Store::set('meta', 'me' . $bi, $me['result']);
        $tg->call('deleteWebhook', ['drop_pending_updates' => !empty($pcfg['drop_pending'])]);
        try {
            $tg->call('setMyCommands', ['commands' => Ui::commands('private'), 'scope' => ['type' => 'all_private_chats']], 10);
            $tg->call('setMyCommands', ['commands' => Ui::commands('group'), 'scope' => ['type' => 'all_group_chats']], 10);
            $tg->call('deleteMyCommands', ['scope' => ['type' => 'default']], 10);
            foreach ((array) App::cfg('admins', []) as $adminId) {
                if ((int) $adminId > 0) {
                    $tg->call('setMyCommands', ['commands' => Ui::commands('admin'), 'scope' => ['type' => 'chat', 'chat_id' => (int) $adminId]], 10);
                }
            }
        } catch (\Throwable $e) {
            logError('boot', "setMyCommands #{$bi}: " . $e->getMessage());
        }
        $tg->call('setChatMenuButton', ['menu_button' => ['type' => 'commands']], 10);
        out("@{$me['result']['username']} online (bot #{$bi})");
        $ok[] = $bi;
    }
    return $ok;
}

$arg = $argv[1] ?? '';

switch ($arg) {
    case '--handle':
        $file   = $argv[2] ?? '';
        $update = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        @unlink($file);
        if (is_array($update)) {
            Kernel::handle($update);
        }
        exit(0);

    case '--poller':
        pollerLoop((int) ($argv[2] ?? 1));
        exit(0);

    case '--download':
        App::useBot((int) ($argv[4] ?? 0));
        Downloader::work((string) ($argv[2] ?? ''), (string) ($argv[3] ?? 'hd'));
        exit(0);

    case '--autodl':
        App::useBot((int) ($argv[3] ?? 0));
        Downloader::auto((string) ($argv[2] ?? ''));
        exit(0);

    case '--warm':
        out('icons cached: ' . Icons::warm(), 'web');
        exit(0);

    case '--backup':
        Db::migrate();
        out(Backup::push('manual'), 'db');
        exit(0);

    case '--restore':
        out(Backup::pull(), 'db');
        exit(0);

    case '--stop':
        stopBot();
        exit(0);

    case '--status':
        $pid = isRunning();
        out($pid ? "running (pid {$pid})" : 'stopped');
        exit(0);

    case '--restart':
        stopBot();
        startDaemon();
        exit(0);

    case '--daemon':
        if ($pid = isRunning()) {
            out("already running (pid {$pid})");
            exit(0);
        }
        startDaemon();
        exit(0);
}

$quiet = $arg === '--keepalive';

$lock = fopen(LOCK_FILE, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        logError('boot', 'already running (see php bot.php --status)');
    }
    exit($quiet ? 0 : 1);
}
file_put_contents(PID_FILE, (string) getmypid());

$pcfg    = (array) App::cfg('polling', []);
$mode    = workerMode();
$max     = max(1, (int) ($pcfg['max_workers'] ?? 24));
$allowed = ALLOWED_UPDATES;

if ((string) App::cfg('bot_token', '') === '') {
    logError('boot', 'BOT_TOKEN is missing (.env or Railway variables)');
    exit(1);
}

if (Db::driver() === 'sqlite') {
    out(Backup::pull(), 'db');
}

for ($try = 1; ; $try++) {
    try {
        Db::migrate();
        break;
    } catch (\Throwable $e) {
        if ($try >= 10) {
            logError('db', 'connection failed: ' . $e->getMessage());
            exit(1);
        }
        out("waiting for database ({$try}/10): " . $e->getMessage(), 'db');
        Db::disconnect();
        sleep(3);
    }
}

$me = App::tg()->call('getMe');
if (empty($me['ok'])) {
    logError('boot', 'getMe failed: bad token or no connection to Telegram');
    exit(1);
}
Store::set('meta', 'me', $me['result']);

App::tg()->call('deleteWebhook', ['drop_pending_updates' => !empty($pcfg['drop_pending'])]);

try {
    $tg = App::tg();
    $tg->call('setMyCommands', ['commands' => Ui::commands('private'), 'scope' => ['type' => 'all_private_chats']], 10);
    $tg->call('setMyCommands', ['commands' => Ui::commands('group'), 'scope' => ['type' => 'all_group_chats']], 10);
    $tg->call('deleteMyCommands', ['scope' => ['type' => 'default']], 10);
    foreach ((array) App::cfg('admins', []) as $adminId) {
        if ((int) $adminId > 0) {
            $tg->call('setMyCommands', ['commands' => Ui::commands('admin'), 'scope' => ['type' => 'chat', 'chat_id' => (int) $adminId]], 10);
        }
    }
} catch (\Throwable $e) {
    logError('boot', 'setMyCommands: ' . $e->getMessage());
}

Downloader::cleanupTmp();
if (Downloader::bin() !== null && env('DL_UPDATE', '1') !== '0' && !disabled('exec')) {
    exec('timeout 240 python3 -m pip install -U -q --no-cache-dir --break-system-packages "yt-dlp[default,curl-cffi]" > /dev/null 2>&1 &');
    out('ready · ' . (Downloader::mtproto() ? 'mtproto, up to ' . (int) (Downloader::maxBytes() / 1048576) . ' MB' : 'bot api, up to 50 MB') . ' · updating yt-dlp', 'dl');
} elseif (Downloader::bin() === null) {
    logWarn('dl', 'yt-dlp not found, downloader disabled');
}

$webPid = 0;
if (env('WEB_SERVER', '1') !== '0' && !disabled('exec') && is_file(NULLINI_ROOT . '/public/router.php')) {
    $port = (int) (getenv('PORT') ?: 8080);
    $o    = [];
    exec('PHP_CLI_SERVER_WORKERS=12 ' . escapeshellarg(PHP_BINARY) . ' -S 0.0.0.0:' . $port . ' -t ' . escapeshellarg(NULLINI_ROOT . '/public')
        . ' ' . escapeshellarg(NULLINI_ROOT . '/public/router.php') . ' > /dev/null 2>&1 & echo $!', $o);
    $webPid = (int) ($o[0] ?? 0);
    try {
        Railway::ensureDomain($port);
    } catch (\Throwable $e) {
        logError('web', 'railway domain: ' . $e->getMessage());
    }
    $pub = App::publicUrl();
    out("port {$port}" . ($pub !== '' ? " · {$pub}" : ' · no public domain yet'), 'web');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --warm > /dev/null 2>&1 &');
}
App::tg()->call('setChatMenuButton', ['menu_button' => ['type' => 'commands']], 10);
$extra = bootExtraBots($pcfg);

out("@{$me['result']['username']} online · {$mode} · " . (Db::driver() === 'sqlite' ? 'sqlite' : 'postgres')
    . (Backup::enabled() ? ' · sync ' . Backup::repo() . ' every ' . min(3600, max(45, (int) env('SYNC_EVERY', 60))) . 's' : ' · sync off'));

$running = true;
if (!disabled('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$offsetFile  = NULLINI_DATA . '/.offset';
$offset      = (int) @file_get_contents($offsetFile);
$children    = [];
if ($mode === 'fork' && !disabled('pcntl_signal')) {
    pcntl_signal(SIGCHLD, static function () use (&$children) {
        while (($pid = pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
            unset($children[$pid]);
        }
    });
}
$lastTick    = 0.0;
$lastJanitor = 0;
$lastDay     = Store::dayStart();
$errors      = 0;
$sync        = Backup::enabled();
$syncPid     = -1;
$lastUpdate  = 0;
$syncEvery   = min(3600, max(45, (int) env('SYNC_EVERY', 60)));
$syncLimit   = min(30, $syncEvery - 10);
$lastSync    = time();
$polled      = false;
$conflict    = 0;
$lastGw      = 0;
$gwPid       = -1;
$lastPoller  = 0;

while ($running) {
    if (microtime(true) - $lastTick >= 1.0) {
        Kernel::tick();
        $lastTick = microtime(true);
    }
    if (Store::dayStart() !== $lastDay) {
        $lastDay = Store::dayStart();
        background(static fn() => Kernel::dailyCleanup(), $mode, $children, 120);
    }
    if (time() - $lastJanitor >= 3600) {
        $lastJanitor = time();
        background(static fn() => Kernel::janitor(), $mode, $children, 600);
    }

    if ($sync && $polled && time() - $lastSync >= $syncEvery && !isset($children[$syncPid]) && time() >= Backup::backoffUntil()) {
        $lastSync = time();
        $syncPid  = background(static function () {
            $r = Backup::push('live');
            if (!str_starts_with($r, 'ok')) {
                logError('sync', $r);
            }
        }, $mode, $children, $syncLimit);
    }
    if ($polled && time() - $lastGw >= 5 && !isset($children[$gwPid])) {
        $lastGw = time();
        try {
            if (Giveaway::pending()) {
                $gwPid = background(static fn() => Giveaway::tick(), $mode, $children, 240);
            }
        } catch (\Throwable $e) {
            logError('gw', $e->getMessage());
            Db::disconnect();
        }
    }
    if ($polled && $extra && time() - $lastPoller >= 10) {
        $lastPoller = time();
        foreach ($extra as $bi) {
            ensurePoller($bi);
        }
    }
    if ($mode === 'fork') {
        reap($children);
    }

    try {
        $busy = MathDuel::hasActive();
    } catch (\Throwable $e) {
        logError('loop', $e->getMessage());
        Db::disconnect();
        $busy = false;
    }
    $active  = $busy || time() - $lastUpdate < 15;
    $timeout = $active ? 1 : max(1, (int) ($pcfg['timeout'] ?? 10));
    $r       = App::tgFor(0)->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => $allowed], $timeout + 15);

    if (empty($r['ok'])) {
        if ((int) ($r['error_code'] ?? 0) === 409) {
            if (!$conflict) {
                $conflict = time();
                logWarn('tg', 'another instance is still polling (409), waiting for it to stop');
                App::tgFor(0)->call('deleteWebhook');
            }
            sleep(3);
            continue;
        }
        $errors++;
        sleep(min(30, 2 * $errors));
        continue;
    }
    $errors = 0;
    if ($conflict) {
        logInfo('tg', 'conflict resolved after ' . (time() - $conflict) . 's');
        if (!$polled && $sync) {
            Db::disconnect();
            out(Backup::pull(), 'db');
            Db::migrate();
        }
        $conflict = 0;
    }
    $polled = true;

    $updates = $r['result'] ?? [];
    if ($updates) {
        $lastUpdate = time();
    }
    foreach ($updates as $u) {
        $offset = (int) $u['update_id'] + 1;
        dispatch($u, $mode, $children, $max);
    }
    if ($updates) {
        @file_put_contents($offsetFile, (string) $offset);
    }
}

out('shutting down');
if ($mode === 'fork') {
    if (isset($children[$syncPid]) && function_exists('posix_kill')) {
        posix_kill($syncPid, SIGKILL);
    }
    $deadline = time() + 10;
    while ($children && time() < $deadline) {
        reap($children, true);
    }
    foreach (array_keys($children) as $pid) {
        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGKILL);
        }
        pcntl_waitpid($pid, $status);
    }
    $children = [];
}
if ($sync) {
    try {
        Backup::$timeout = 8;
        out(Backup::push('final'), 'db');
    } catch (\Throwable $e) {
        logError('db', 'final push failed: ' . $e->getMessage());
    }
}
if ($webPid > 0 && function_exists('posix_kill')) {
    posix_kill($webPid, SIGTERM);
}
@unlink(PID_FILE);
flock($lock, LOCK_UN);
