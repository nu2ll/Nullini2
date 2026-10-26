<?php
// Real guest/inline handlers, real Intent and AI; all external boundaries stubbed.
declare(strict_types=1);

final class FakeTelegram
{
    public array $calls = [];
    public array $edits = [];
    public bool $failHtml = false;
    public function call(string $method, array $args, ...$extra): array
    {
        $this->calls[] = [$method, $args];
        return ['ok' => true, 'result' => ['inline_message_id' => 'inline-test']];
    }
    public function editInline(string $id, string $text, $kb = null, bool $html = true): array
    {
        $this->edits[] = [$id, $text, $html];
        return ['ok' => !($this->failHtml && $html)];
    }
}
final class App
{
    public static FakeTelegram $tg;
    public static function cfg(string $key, $default = null) { return $default; }
    public static function botUsername(): string { return 'NulliniTestBot'; }
    public static function tg(): FakeTelegram { return self::$tg; }
}
final class Store
{
    public static array $data = ['cfg' => ['ai_route' => 'offline:test', 'router_on' => false]];
    public static function get(string $bucket, string $key, $default = null) { return self::$data[$bucket][$key] ?? $default; }
    public static function set(string $bucket, string $key, $value): void { self::$data[$bucket][$key] = $value; }
}
final class FreeAI
{
    public static ?string $response = null;
    public static array $calls = [];
    public static function valid(string $route): bool { return $route === 'offline:test'; }
    public static function chat(string $route, array $messages, array $opt): ?string
    {
        self::$calls[] = [$messages, $opt];
        return self::$response;
    }
}
final class Jalali
{
    public static function fullDate(int $time): string { return 'تاریخ آزمایشی'; }
    public static function time(int $time): string { return '12:00'; }
}
final class WebSearch
{
    public static bool $on = true;
    public static ?array $next = null;
    public static array $plans = [];
    public static int $retrievals = 0;
    public static int $answers = 0;
    public static function enabled(): bool { return self::$on; }
    public static function plan(string $q, array $history): ?array { self::$plans[] = $q; return self::$on ? self::$next : null; }
    public static function retrieve(array $plan): array { self::$retrievals++; return ['status' => 'ok']; }
    public static function answer(string $q, array $ctx, array $history, array $plan, array $retrieved): array
    {
        self::$answers++;
        return ['answer' => 'پاسخ بررسی‌شده', 'sources' => [['title' => 'منبع', 'url' => 'https://example.com/source']]];
    }
    public static function format(array $web, int $limit): array
    {
        return ['html' => 'پاسخ بررسی‌شده <a href="https://example.com/source">منبع</a>', 'plain' => 'پاسخ بررسی‌شده https://example.com/source'];
    }
}
final class Users
{
    public static function hit(string $name): void {}
}
final class Assistant
{
    public static function hasMedia(array $m): bool { return isset($m['photo']); }
    public static function mediaPart(array $m): ?array { return isset($m['photo']) ? ['type' => 'media', 'mime' => 'image/jpeg', 'data' => 'dGVzdA=='] : null; }
    public static function fileInfo(array $m): ?string { return null; }
    public static function dataInfo(array $m): ?array { return null; }
    public static function isFrame(array $m): bool { return false; }
    public static function kind(array $m): string { return 'عکس'; }
    public static function withAlbum(string $s, array $m): string { return $s; }
    public static function routeContext(array $m): array
    {
        return ['attach' => isset($m['photo']) ? 'photo' : '', 'reply_kind' => isset($m['reply_to_message']['photo']) ? 'photo' : '', 'reply_text' => $m['reply_to_message']['text'] ?? ''];
    }
}
final class Downloader
{
    public static array $downloads = [];
    public static function match(string $q): ?array { return preg_match('~^https?://\S+$~u', $q) ? ['name' => 'test', 'url' => $q] : null; }
    public static function find(string $q): ?array { return preg_match('~https?://\S+~u', $q, $m) ? ['url' => $m[0]] : null; }
    public static function guest(array $from, array $link, string $imid): void { self::$downloads[] = [$from, $link, $imid]; }
}
final class Tools
{
    public static function hafezUrl(): string { return 'https://example.com/fal.gif'; }
    public static function hafezCaption(): string { return 'فال آزمایشی'; }
}
final class ImageGen
{
    public static array $prompts = [];
    public static function generate(string $prompt, callable $callback): ?string { self::$prompts[] = $prompt; return null; }
    public static function failText(): string { return 'image test failure'; }
    public static function englishPrompt(string $q): string { return $q; }
}
final class LiveReply
{
    public const DRAWING = 'drawing';
    public static int $instances = 0;
    public function __construct(...$args) { self::$instances++; }
    public function feed(string $s): void {}
}

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Core/AI.php';
require __DIR__ . '/../src/Features/Intent.php';
require __DIR__ . '/../src/Handlers/GuestHandler.php';
require __DIR__ . '/../src/Handlers/InlineHandler.php';

$count = 0;
function eq($actual, $expected, string $label): void
{
    global $count;
    $count++;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function resetFlow(?string $response): void
{
    App::$tg = new FakeTelegram();
    FreeAI::$response = $response;
    FreeAI::$calls = [];
    WebSearch::$on = true;
    WebSearch::$next = null;
    WebSearch::$plans = [];
    WebSearch::$retrievals = 0;
    WebSearch::$answers = 0;
    Downloader::$downloads = [];
    ImageGen::$prompts = [];
    LiveReply::$instances = 0;
    AI::setRouterOn(false);
}
function jsonTool(string $name, string $arg, string $evidence): string
{
    return json_encode(['tool' => $name, 'arg' => $arg, 'evidence' => $evidence], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function guest(string $q, array $extra = []): void
{
    GuestHandler::handle($extra + ['guest_query_id' => 'guest-test', 'guest_bot_caller_user' => ['id' => 42, 'first_name' => 'Test'], 'text' => '@NulliniTestBot ' . $q, 'chat' => ['id' => 1, 'type' => 'guest'], 'message_id' => 3]);
}
function inlineSmart(string $q): bool
{
    $method = new ReflectionMethod(InlineHandler::class, 'smart');
    return $method->invoke(null, $q, ['id' => 42, 'first_name' => 'Test'], 'inline-test');
}

$q = 'میشه لطف کنی یه فال حافظ برام بگیری؟';
resetFlow('unused');
guest('یه فال حافظ بگیر');
eq(count(FreeAI::$calls), 0, 'guest direct local command uses no model');
eq(count(WebSearch::$plans), 0, 'guest direct tool bypasses unrelated web planner');
resetFlow('سلام');
guest('', ['reply_to_message' => ['text' => 'https://example.com/context-only']]);
eq(count(Downloader::$downloads), 0, 'reply link alone never creates a download request');
resetFlow(jsonTool('fal', '', 'فال حافظ'));
guest($q);
eq(count(FreeAI::$calls), 1, 'guest embedded tool needs one main call');
eq(LiveReply::$instances, 0, 'guest embedded tool buffered');
eq(App::$tg->calls[array_key_last(App::$tg->calls)][0], 'editMessageMedia', 'guest uses existing media tool output path');
eq(App::$tg->calls[array_key_last(App::$tg->calls)][1]['media']['caption'], 'فال آزمایشی', 'guest tool content rendered');

resetFlow(jsonTool('fal', '', 'فال حافظ'));
guest($q, ['photo' => [['file_id' => 'photo']]]);
eq(count(FreeAI::$calls), 1, 'successful embedded media tool must not trigger text-only retry');

$q = 'میشه این ویدئو رو برام دانلود کنی؟';
resetFlow(jsonTool('download', 'https://example.com/VideoCase', 'دانلود کنی'));
guest($q);
eq(count(Downloader::$downloads), 1, 'guest embedded download dispatched');
eq(Downloader::$downloads[0][1]['url'], 'https://example.com/VideoCase', 'guest preserves download argument');

resetFlow('[[image | A playful space cat]]');
guest('یه تصویر گربه فضایی بساز');
eq(ImageGen::$prompts, ['A playful space cat'], 'guest preserves image protocol');
eq(count(FreeAI::$calls), 1, 'guest image not retried as null answer');

resetFlow('سلام!');
guest('سلام');
eq(App::$tg->edits[array_key_last(App::$tg->edits)][1], 'سلام!', 'guest ordinary response works');
eq(LiveReply::$instances, 0, 'guest ordinary response also buffered in embedded mode');

resetFlow('unused');
WebSearch::$next = ['query' => 'neutral test', 'verify' => true];
guest('این خبر درسته؟', ['reply_to_message' => ['text' => 'خبر مورد بررسی']]);
eq(count(FreeAI::$calls), 0, 'guest web path bypasses normal AI reply');
eq(WebSearch::$retrievals, 1, 'guest retrieves web evidence');
eq(WebSearch::$answers, 1, 'guest answers from web evidence');
eq(str_contains(WebSearch::$plans[0], 'خبر مورد بررسی'), true, 'guest web question includes reply context');
eq(str_contains(WebSearch::$plans[0], 'سؤال فعلی'), true, 'guest distinguishes current request');
eq(str_contains(App::$tg->edits[0][1], '<a href="https://example.com/source">'), true, 'guest citation is real HTML link');
eq(LiveReply::$instances, 0, 'guest web answer never streams');

resetFlow('unused');
WebSearch::$next = ['query' => 'neutral test'];
App::$tg->failHtml = true;
guest('آخرین خبر چیه؟');
eq(count(App::$tg->edits), 2, 'failed guest HTML edit retries plain');
eq(str_contains(App::$tg->edits[1][1], 'https://example.com/source'), true, 'guest plain fallback preserves source link');
eq(App::$tg->edits[1][2], false, 'guest source fallback is plain text');

resetFlow('سرچ وب خاموشه');
WebSearch::$on = false;
WebSearch::$next = ['query' => 'must not run'];
guest('آخرین خبر چیه؟');
eq(WebSearch::$retrievals, 0, 'guest global web off prevents retrieval');
eq(count(FreeAI::$calls), 1, 'guest web off uses ordinary model response');

resetFlow('سلام');
guest('سلام', ['guest_bot_caller_user' => ['id' => 777000]]);
eq(str_contains(FreeAI::$calls[0][0][0]['content'], 'MAIN RESPONSE MODE:'), false, 'service sender not granted embedded tools');
eq(count(WebSearch::$plans), 0, 'service sender not granted guest web lookup');
eq(LiveReply::$instances, 1, 'only non-embedded mode retains normal streaming');

resetFlow(jsonTool('fal', '', 'فال حافظ'));
eq(inlineSmart('میشه یه فال حافظ برام بگیری؟'), true, 'inline embedded action handled');
eq(count(FreeAI::$calls), 1, 'inline embedded tool one main call');
eq(LiveReply::$instances, 0, 'inline embedded action buffered');
eq(App::$tg->calls[array_key_last(App::$tg->calls)][0], 'editMessageMedia', 'inline shared tool path used');
eq(count(WebSearch::$plans), 0, 'no automatic inline web search added');

resetFlow('unused');
eq(inlineSmart('یه فال حافظ بگیر'), true, 'inline local tool handled');
eq(count(FreeAI::$calls), 0, 'inline direct tool uses no model');

resetFlow(jsonTool('download', 'https://example.com/VideoCase', 'دانلود کنی'));
eq(inlineSmart('میشه این ویدئو رو دانلود کنی؟'), true, 'inline embedded download handled');
eq(count(Downloader::$downloads), 1, 'inline download dispatch preserved');

resetFlow('[[image | A curious cat]]');
eq(inlineSmart('عکس گربه بساز'), true, 'inline image handled');
eq(ImageGen::$prompts, ['A curious cat'], 'inline uses image path');

resetFlow(null);
eq(inlineSmart('سلام'), true, 'embedded failure completes instead of invoking second main response');
eq(count(FreeAI::$calls), 1, 'inline embedded failure avoids duplicate calls');
eq(App::$tg->edits[0][1], AI::FAIL_TEXT, 'inline failure displayed safely');

echo "PASS {$count} guest/inline flow assertions (offline, no network).\n";
