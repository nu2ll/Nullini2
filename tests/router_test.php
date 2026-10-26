<?php
// Offline regression suite: php tests/router_test.php (PHP 8+, ext-mbstring).
declare(strict_types=1);

if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "Tests require ext-mbstring, as does the application.\n");
    exit(1);
}

final class FakeTelegram
{
    public array $answers = [];
    public array $edits = [];
    public function editMessage(...$args): void { $this->edits[] = $args; }
    public function answer(...$args): void { $this->answers[] = $args; }
    public function alert(...$args): void { $this->answers[] = $args; }
}
final class App
{
    public static bool $admin = true;
    public static FakeTelegram $telegram;
    public static function tg(): FakeTelegram { return self::$telegram; }
    public static function isAdmin(int $id): bool { return self::$admin; }
    public static function cfg(string $key, $default = null) { return $default; }
    public static function botUsername(): string { return 'NulliniTestBot'; }
}
final class AI
{
    public static bool $on = true;
    public static int $calls = 0;
    public static ?string $raw = null;
    public static function routerOn(): bool { return self::$on; }
    public static function setRouterOn(bool $on): void { self::$on = $on; }
    public static function keys(): array { return []; }
    public static function routerSetting(): string { return ''; }
    public static function routerRoute(): string { return 'test'; }
    public static function chatVia(...$args): ?string { self::$calls++; return self::$raw; }
    public static function route(): string { return 'gemini'; }
}
final class FreeAI
{
    public static function active(): array { return []; }
}
final class WebSearch
{
    public static bool $on = true;
    public static function enabled(): bool { return self::$on; }
    public static function setEnabled(bool $on): void { self::$on = $on; }
}
final class Search
{
    public static int $calls = 0;
    public static function results(string $arg, int $limit): array { self::$calls++; return []; }
    public static function keyboard(string $arg): array { return ['query' => $arg]; }
}

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Features/Intent.php';
require __DIR__ . '/../src/Features/AiPanel.php';

$count = 0;
function same($actual, $expected, string $label): void
{
    global $count;
    $count++;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function envelope(string $name, string $arg, string $evidence): string
{
    return json_encode(['tool' => $name, 'arg' => $arg, 'evidence' => $evidence], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

App::$telegram = new FakeTelegram();
$cq = ['from' => ['id' => 1], 'message' => ['chat' => ['id' => 2], 'message_id' => 3]];
foreach ([false, true] as $initial) {
    AI::$on = $initial;
    AiPanel::onCallback($cq, 'ai:rt:on');
    same(AI::$on, true, 'explicit on from either state');
    AiPanel::onCallback($cq, 'ai:rt:on');
    same(AI::$on, true, 'duplicate on is idempotent');
    same(AiPanel::routerKeyboard()['inline_keyboard'][0][0]['callback_data'], 'ai:rt:off', 'on state offers off action');
    same(str_contains(AiPanel::routerKeyboard()['inline_keyboard'][0][0]['text'], 'خاموش کردن'), true, 'off label explicitly an action');
    same(str_contains(AiPanel::routerText(), 'روشن ✅'), true, 'text shows actual on state');
    AiPanel::onCallback($cq, 'ai:rt:off');
    AiPanel::onCallback($cq, 'ai:rt:off');
    same(AI::$on, false, 'duplicate off is idempotent');
    same(AiPanel::routerKeyboard()['inline_keyboard'][0][0]['callback_data'], 'ai:rt:on', 'off state offers on action');
    same(str_contains(AiPanel::routerKeyboard()['inline_keyboard'][0][0]['text'], 'روشن کردن'), true, 'on label explicitly an action');
    same(str_contains(AiPanel::routerText(), 'خاموش ❌'), true, 'text shows actual off state');
    foreach (['ai:rt', 'ai:rt:toggle', 'ai:rt:invalid'] as $data) {
        AiPanel::onCallback($cq, $data);
        same(AI::$on, false, 'malformed/legacy callbacks never toggle');
    }
}
foreach ([false, true] as $initial) {
    WebSearch::$on = $initial;
    AiPanel::onCallback($cq, 'ai:web:1');
    AiPanel::onCallback($cq, 'ai:web:1');
    same(WebSearch::enabled(), true, 'web on duplicate idempotent');
    same(str_contains(AiPanel::text(), 'وضعیت فعلی سرچ وب: <b>روشن ✅'), true, 'panel shows web actual on');
    $rows = AiPanel::keyboard()['inline_keyboard'];
    same($rows[array_key_last($rows)][0]['callback_data'], 'ai:web:0', 'web on offers off action');
    same(str_contains($rows[array_key_last($rows)][0]['text'], 'خاموش کردن'), true, 'web button action unambiguous');
    AiPanel::onCallback($cq, 'ai:web:0');
    AiPanel::onCallback($cq, 'ai:web:0');
    same(WebSearch::enabled(), false, 'web off duplicate idempotent');
    same(str_contains(AiPanel::text(), 'وضعیت فعلی سرچ وب: <b>خاموش ❌'), true, 'panel shows web actual off');
    foreach (['ai:web', 'ai:web:toggle', 'ai:web:2'] as $data) {
        AiPanel::onCallback($cq, $data);
        same(WebSearch::enabled(), false, 'invalid web callback never toggles');
    }
}
same(Intent::output(['chat' => ['type' => 'inline']], 'search', 'PHP'), [
    'text' => '🔎 سرچ وب خاموشه؛ از دکمه‌ها می‌تونی مستقیم تو گوگل بگردی 👇',
    'kb' => ['query' => 'PHP'],
], 'disabled search yields clear message and manual links');
same(Search::$calls, 0, 'disabled intent search never calls results API');
$oldKey = getenv('TAVILY_API_KEY');
$oldEnvKey = $_ENV['TAVILY_API_KEY'] ?? null;
unset($_ENV['TAVILY_API_KEY']);
putenv('TAVILY_API_KEY');
same(str_contains(AiPanel::text(), 'تنظیم نشده'), true, 'missing API key visible in panel');
putenv('TAVILY_API_KEY=offline-test-secret');
same(str_contains(AiPanel::text(), 'تنظیم شده ✅'), true, 'configured API key status visible');
same(str_contains(AiPanel::text(), 'offline-test-secret'), false, 'API key never exposed');
putenv($oldKey === false ? 'TAVILY_API_KEY' : 'TAVILY_API_KEY=' . $oldKey);
if ($oldEnvKey !== null) {
    $_ENV['TAVILY_API_KEY'] = $oldEnvKey;
}
App::$admin = false;
AiPanel::onCallback($cq, 'ai:rt:on');
same(AI::$on, false, 'non-admin cannot change state');
AiPanel::onCallback($cq, 'ai:web:1');
same(WebSearch::enabled(), false, 'non-admin cannot enable search');
App::$admin = true;

$cases = [
    ['لطفاً یه فال حافظ بگیر', [], ['fal', '']],
    ['یه رمز عبور قوی بساز', [], ['password', '']],
    ['شیر یا خط', [], ['coin', '']],
    ['امروز چندمه؟', [], ['date', '']],
    ['قیمت دلار الان چنده؟', [], ['rates', '']],
    ['هوای تهران امروز چطوره؟', [], ['weather', 'تهران']],
    ['محاسبه کن: ۲ + ۳ * (۴ - ۱)', [], ['calc', '۲ + ۳ * (۴ - ۱)']],
    ['منو روست کن', [], ['roast', 'me']],
    ['اینو روست کن', ['reply_text' => 'سلام'], ['roast', 'reply']],
    ['@SomeUser رو روست کن', [], ['roast', '@someuser']],
    ['این عکس هوش مصنوعیه؟', ['attach' => 'photo'], ['aicheck', '']],
    ['این تصویر با هوش مصنوعی ساخته شده؟', ['reply_kind' => 'photo'], ['aicheck', '']],
    ['اینو دانلود کن', ['reply_text' => 'https://example.com/CaseSensitive'], ['download', 'https://example.com/CaseSensitive']],
    ['دانلود کن https://example.com/AbCd', [], ['download', 'https://example.com/AbCd']],
    ['https://example.com/AbCd رو دانلود کن', [], ['download', 'https://example.com/AbCd']],
    ['سرچ کن آموزش PHP', [], ['search', 'آموزش PHP']],
    ['فونت بساز Hello', [], ['font', 'Hello']],
    ['qr بساز https://example.com/AbCd', [], ['qr', 'https://example.com/AbCd']],
    ['اینو به انگلیسی ترجمه کن', ['reply_text' => 'سلام'], ['translate', 'English | ']],
    ['به فارسی ترجمه کن: Hello WORLD', [], ['translate', 'Persian | Hello WORLD']],
    ['یه قرعه‌کشی بساز', [], ['giveaway', '']],
];
AI::$on = false;
foreach ($cases as [$text, $ctx, $expected]) {
    same(Intent::decide($text, $ctx), $expected, 'offline direct request: ' . $text);
}
same(AI::$calls, 0, 'router off performs zero separate AI calls');

$conversation = [
    'روتر روشنه؟', 'روتر خاموشه؟', 'روشن کن', 'روست', 'من از روست خوشم نمیاد',
    'قیمت دلار خیلی بالاست', 'به نظرت قیمت دلار میره بالا؟', 'سکه خریدی؟',
    'هوا خیلی خوبه', 'هوای تهران چطوره به نظرت فردا میرم', 'فال حافظ خرافاته؟',
    'درباره ترجمه حرف بزن', 'این ترجمه اشتباهه', 'مرسی', 'باشه', 'انجامش بده',
    'این عکس هوش مصنوعیه؟', 'اینو ترجمه کن', 'اینو دانلود کن', 'اینو روست کن',
    'مثلاً منو روست کن', 'اگر سکه بنداز', '«منو روست کن»', 'منو روست نکن',
    'دانلود کن https://example.com نکن', 'سرچ کن چیزی نکن',
];
foreach ($conversation as $text) {
    same(Intent::decide($text), null, 'ordinary/vague/negated/unsupported text: ' . $text);
}
foreach (['باشه', 'مرسی', 'این اشتباهه'] as $text) {
    same(Intent::decide($text, ['reply_text' => 'منو روست کن؛ قیمت دلار چنده؛ سرچ کن PHP', 'history' => [['role' => 'user', 'content' => 'فال بگیر']]]), null, 'context alone cannot create a request');
}
same(AI::$calls, 0, 'all off-mode cases avoid AI calls');

$raw = envelope('weather', 'تهران', 'هوای تهران چطوره');
same(Intent::embeddedDecision($raw, 'هوای تهران چطوره؟'), ['weather', 'تهران'], 'main-response tool envelope accepted');
same(Intent::embeddedDecision('هوا خوبه', 'هوای تهران چطوره؟'), null, 'normal answer remains conversation');
same(Intent::embeddedDecision('نمونه: ' . $raw, 'هوای تهران چطوره؟'), null, 'JSON in prose not executable');
same(Intent::embeddedDecision($raw . ' متن اضافه', 'هوای تهران چطوره؟'), null, 'trailing prose not executable');
same(Intent::embeddedDecision('```json' . "\n" . $raw . "\n```", 'هوای تهران چطوره؟'), null, 'fenced example not executable');
same(Intent::embeddedDecision($raw, 'مرسی'), null, 'evidence must be from current message, not reply');
same(Intent::embeddedDecision(envelope('none', '', ''), 'مرسی'), null, 'none is not a tool');
same(Intent::embeddedDecision(envelope('unknown', '', 'بگیر'), 'فال بگیر'), null, 'unknown tool rejected');
same(Intent::embeddedDecision('{"tool":[],"arg":{},"evidence":[]}', 'فال بگیر'), null, 'malformed field types rejected without warnings');
same(Intent::embeddedDecision(envelope('fal', '', 'ف'), 'فال بگیر'), null, 'one-letter evidence rejected');
same(Intent::embeddedDecision(envelope('aicheck', '', 'هوش مصنوعیه'), 'این عکس هوش مصنوعیه؟'), null, 'image inspection requires image');
same(Intent::embeddedDecision(envelope('aicheck', '', 'هوش مصنوعیه'), 'این عکس هوش مصنوعیه؟', ['reply_kind' => 'photo']), ['aicheck', ''], 'replied image permits inspection');
same(Intent::embeddedDecision(envelope('translate', 'English | ', 'ترجمه کن'), 'اینو ترجمه کن'), null, 'empty translation requires reply');
same(Intent::embeddedDecision(envelope('translate', 'English | ', 'ترجمه کن'), 'اینو ترجمه کن', ['reply_text' => 'سلام']), ['translate', 'English |'], 'translation uses explicit reply context');
same(Intent::embeddedDecision(envelope('translate', 'English | سلام', 'ترجمه کن'), 'ترجمه کن: سلام'), ['translate', 'English | سلام'], 'inline translation accepted');
same(str_contains(Intent::embeddedPrompt(), 'Otherwise answer the user normally'), true, 'main prompt allows normal answers');
same(str_contains(Intent::embeddedPrompt(), 'روشن or خاموش are NOT roast'), true, 'prompt disambiguates router status and roast');

AI::$on = true;
AI::$raw = $raw;
same(Intent::decide('هوای تهران چطوره؟'), ['weather', 'تهران'], 'router on uses model decision');
same(AI::$calls, 1, 'router on calls the configured router exactly once');
AI::$raw = null;
same(Intent::decide('سلام'), null, 'router failure falls back to normal response');
same(AI::$calls, 2, 'failed router counted once');
same(Intent::decide(''), null, 'empty input never calls router');
same(AI::$calls, 2, 'empty input makes no AI call');

echo "PASS {$count} router assertions (offline, no network).\n";
