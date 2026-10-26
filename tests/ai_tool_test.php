<?php
// Integration-level offline tests of real AI::reply + Intent protocol.
declare(strict_types=1);

final class App
{
    public static function cfg(string $key, $default = null) { return $default; }
    public static function botUsername(): string { return 'NulliniTestBot'; }
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
        self::$calls[] = [$route, $messages, $opt];
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
    public static function enabled(): bool { return true; }
}

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Features/Intent.php';
require __DIR__ . '/../src/Core/AI.php';

$count = 0;
function check($actual, $expected, string $label): void
{
    global $count;
    $count++;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function toolJson(string $name, string $arg, string $evidence): string
{
    return json_encode(['tool' => $name, 'arg' => $arg, 'evidence' => $evidence], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function mainReply(?string $response, string $request, array $context = [], bool $embedded = true): array
{
    FreeAI::$response = $response;
    $before = count(FreeAI::$calls);
    $result = AI::reply($request, [
        'chat_type' => 'private',
        'embedded_tools' => $embedded,
        'tool_current_message' => $request,
        'tool_context' => $context,
    ]);
    check(count(FreeAI::$calls) - $before, 1, 'one main-response call only');
    return $result;
}

foreach ([false, 'false', 'off', '0', 0] as $legacy) {
    Store::$data['cfg']['router_on'] = $legacy;
    check(AI::routerOn(), false, 'legacy off state normalized: ' . var_export($legacy, true));
}
foreach ([true, 'true', 'on', '1', 1] as $legacy) {
    Store::$data['cfg']['router_on'] = $legacy;
    check(AI::routerOn(), true, 'legacy on state normalized: ' . var_export($legacy, true));
}
AI::setRouterOn(false);
check(AI::routerOn(), false, 'bool off persists');
AI::setRouterOn(true);
check(AI::routerOn(), true, 'bool on persists');

$request = 'میشه لطف کنی برای من فال حافظ بگیری؟';
$r = mainReply(toolJson('fal', '', 'فال حافظ بگیری'), $request);
check($r['tool'] ?? null, ['fal', ''], 'natural language main response dispatch');
check($r['answer'], null, 'tool envelope never presented as answer');
$messages = FreeAI::$calls[array_key_last(FreeAI::$calls)][1];
check(str_contains($messages[0]['content'], Intent::embeddedPrompt()), true, 'main system prompt contains tool protocol');
check(str_contains($messages[0]['content'], 'CURRENT_MESSAGE: ' . json_encode($request, JSON_UNESCAPED_UNICODE)), true, 'current words separately delimited');
check($messages[array_key_last($messages)]['content'], $request, 'original request preserved');

$r = mainReply('[[reaction | 👍]]' . "\n\n" . toolJson('fal', '', 'فال حافظ بگیری'), $request);
check($r['tool'] ?? null, ['fal', ''], 'reaction stripped before envelope parser');
check($r['reaction'], '👍', 'reaction retained separately');

$r = mainReply('[[reaction | 👍]]' . "\n\n" . 'سلام! خوبی؟', 'سلام');
check($r['answer'], 'سلام! خوبی؟', 'normal response still supported');
check(isset($r['tool']), false, 'normal response never invents action');

$r = mainReply(toolJson('fal', '', 'فال حافظ بگیری'), 'مرسی', ['reply_text' => $request]);
check(isset($r['tool']), false, 'reply cannot supply evidence');
check($r['answer'], null, 'rejected tool JSON not leaked');

$r = mainReply('{"tool":"fal","arg":', $request);
check(isset($r['tool']), false, 'truncated tool JSON not executed');
check($r['answer'], null, 'truncated tool JSON not leaked');

$r = mainReply("```json\n" . toolJson('fal', '', 'فال حافظ بگیری') . "\n```", $request);
check(isset($r['tool']), false, 'fenced envelope rejected');
check($r['answer'], null, 'fenced internal envelope not leaked');

$r = mainReply(toolJson('aicheck', '', 'هوش مصنوعیه'), 'این عکس هوش مصنوعیه؟');
check(isset($r['tool']), false, 'aicheck rejected without image');
$r = mainReply(toolJson('aicheck', '', 'هوش مصنوعیه'), 'این عکس هوش مصنوعیه؟', ['reply_kind' => 'photo']);
check($r['tool'] ?? null, ['aicheck', ''], 'aicheck uses reply context');

$r = mainReply('[[image | A playful cat in space]]', 'یه عکس گربه بساز');
check($r['image'], 'A playful cat in space', 'image protocol remains independent');
check(isset($r['tool']), false, 'image does not become an Intent tool');

$r = mainReply(null, 'سلام');
check($r['answer'], null, 'model failure handled');
check(isset($r['tool']), false, 'model failure never runs a tool');

$r = mainReply('سلام!', 'سلام', [], false);
check($r['answer'], 'سلام!', 'ordinary mode unchanged');
$messages = FreeAI::$calls[array_key_last(FreeAI::$calls)][1];
check(str_contains($messages[0]['content'], 'MAIN RESPONSE MODE:'), false, 'ordinary mode omits embedded protocol');

echo "PASS {$count} AI/tool integration assertions (real AI::reply, offline provider stub).\n";
