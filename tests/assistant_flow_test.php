<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
final class FakeTelegram {
    public array $sent = [];
    public function action($chat, $action): void {}
    public function reply(array $msg, string $text, array $extra = []): array { $this->sent[] = $text; return ['ok' => true]; }
    public function react(...$args): void {}
}
final class App {
    public static FakeTelegram $telegram;
    public static function tg(): FakeTelegram { return self::$telegram; }
    public static function cfg(string $key, $default = null) { return $key === 'ai_typing_effect' ? false : $default; }
}
final class Store {
    public static int $writes = 0;
    public static function get(...$args) { return null; }
    public static function update($namespace, $key, $fn): void { self::$writes++; $fn(null); }
}
final class Users { public static function hit(...$args): void {} }
final class LiveReply { public function __construct(...$args) { throw new RuntimeException('Streaming must not start for web or embedded tools'); } }
final class Intent {
    public static array $ran = [];
    public static function decide(...$args) { return null; }
    public static function run(array $msg, string $name, string $arg): bool { self::$ran[] = [$name, $arg]; return true; }
}
final class AI {
    public const FAIL_TEXT = 'failure';
    public static array $queue = [];
    public static bool $router = true;
    public static int $replyCalls = 0;
    public static function chat(...$args): ?string { if (!self::$queue) throw new RuntimeException('Unexpected model call'); return array_shift(self::$queue); }
    public static function reply($content, array $ctx, array $history, array $opt): array {
        self::$replyCalls++;
        if (empty($ctx['embedded_tools']) || isset($opt['on_text'])) throw new RuntimeException('OFF tools must be buffered and enabled');
        return ['answer' => null, 'reaction' => null, 'tool' => ['weather', 'تهران']];
    }
    public static function systemPrompt(...$args): string { return 'نالینی'; }
    public static function isServiceSender(...$args): bool { return false; }
    public static function routerOn(): bool { return self::$router; }
    public static function chunks(string $s, int $limit = 4096): array { return [$s]; }
    public static function toHtml(string $s): string { return h($s); }
}
final class Http {
    public static array $queue = [];
    public static function postJson(...$args): array { if (!self::$queue) throw new RuntimeException('Unexpected network call'); return array_shift(self::$queue); }
}
function env($key, $default = '') { return getenv($key) === false ? $default : getenv($key); }
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function userName($u): string { return $u['first_name'] ?? ''; }
function logWarn(...$args): void {}
require dirname(__DIR__) . '/src/Features/WebSearch.php';
require dirname(__DIR__) . '/src/Features/Assistant.php';
$count = 0;
function check(bool $ok, string $message): void { global $count; if (!$ok) throw new RuntimeException($message); $count++; }
App::$telegram = new FakeTelegram();
$msg = ['chat' => ['id' => 42, 'type' => 'private'], 'message_id' => 12, 'from' => ['id' => 7, 'first_name' => 'Test']];
putenv('TAVILY_API_KEY=fixture');
AI::$queue = ['{"search":true,"query":"fixture event","verify":false}', '{"answer":"اینم نتیجهٔ بررسی.","supported":true,"citations":[{"id":2,"quote":"The fixture source explicitly documents the fictional event"}]}'];
Http::$queue = [['code' => 200, 'data' => ['results' => [
    ['title' => 'Wrong first hit', 'url' => 'https://first.example/a', 'content' => 'An unrelated article with sufficient length, but not supporting this claim.'],
    ['title' => 'Correct & relevant', 'url' => 'https://second.example/b', 'content' => 'The fixture source explicitly documents the fictional event and its actual date.'],
]]]];
Assistant::handle($msg, 'دربارهٔ این خبر بگو');
check(count(App::$telegram->sent) === 1, 'one final answer, no duplicate live message');
check(str_contains(App::$telegram->sent[0], 'https://second.example/b') && !str_contains(App::$telegram->sent[0], 'https://first.example/a'), 'only selected evidence cited');
check(str_contains(App::$telegram->sent[0], 'Correct &amp; relevant'), 'source title escaped once');
check(AI::$replyCalls === 0, 'no stale general model fallback');
check(Store::$writes === 1, 'supported answer remembered');

App::$telegram->sent = [];
putenv('TAVILY_API_KEY');
AI::$queue = ['{"search":true,"query":"fixture current status","verify":true}'];
Assistant::handle($msg, 'وضعیت الان چیه؟');
check(count(App::$telegram->sent) === 1 && str_contains(App::$telegram->sent[0], 'نتونستم'), 'missing key gives honest failure');
check(Store::$writes === 1 && AI::$replyCalls === 0, 'failed verification neither remembered nor answered from stale model');

App::$telegram->sent = [];
AI::$router = false;
AI::$queue = ['{"search":false}'];
Assistant::handle($msg, 'می‌شه وضعیت هوای تهران رو بهم بدی؟');
check(Intent::$ran === [['weather', 'تهران']], 'OFF router still runs model-selected tool');
check(count(App::$telegram->sent) === 0, 'tool decision not leaked as chat text');
check(AI::$replyCalls === 1, 'one ordinary main reply for embedded tool');
echo "Assistant flow: {$count} checks passed\n";
