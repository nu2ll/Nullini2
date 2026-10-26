<?php
// Offline fixtures only. No keys, network calls or real-world news assumptions.
declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
final class AI {
    public static array $queue = [];
    public static array $calls = [];
    public static function chat(array $messages, array $opt = []): ?string {
        self::$calls[] = ['messages' => $messages, 'options' => $opt];
        if (!self::$queue) { throw new RuntimeException('Unexpected model call'); }
        return array_shift(self::$queue);
    }
    public static function systemPrompt(array $ctx, bool $reaction): string { return 'لحن خودمونی نالینی'; }
}
final class Http {
    public static array $queue = [];
    public static array $calls = [];
    public static function postJson(string $url, array $data, array $headers, int $timeout): array {
        self::$calls[] = $data;
        if (!self::$queue) { throw new RuntimeException('Unexpected HTTP call'); }
        return array_shift(self::$queue);
    }
}
final class Store {
    public static $value = true;
    public static function get(...$args) { return self::$value; }
    public static function set($ns, $key, $value): void { self::$value = $value; }
    public static function remember($ns, $key, $ttl, $fn) { return $fn(); }
}
function env(string $key, string $default = ''): string { return getenv($key) === false ? $default : (string) getenv($key); }
function logWarn(string $scope, string $message): void {}
require dirname(__DIR__) . '/src/Features/WebSearch.php';
require dirname(__DIR__) . '/src/Features/Search.php';
$passed = 0;
function check(bool $ok, string $message): void {
    global $passed;
    if (!$ok) { throw new RuntimeException($message); }
    $passed++;
}

check(WebSearch::plan('') === null, 'empty question');
AI::$queue = ['{"search":false}'];
check(WebSearch::plan('سلام خوبی؟') === null, 'ordinary chat does not search');
AI::$queue = ['{"search":true,"query":"وضعیت فعلی یک رویداد","verify":true,"alternate":"current event status"}'];
$plan = WebSearch::plan('وضعیت این خبر چیه؟');
check($plan['verify'] && $plan['alternate'] !== '', 'sensitive bilingual plan');
AI::$queue = [null];
check(WebSearch::plan('فلانی مرده؟')['verify'] === true, 'failed planner does not cause stale denial');
AI::$queue = ['not json'];
check(WebSearch::plan('یه متن تولد بنویس') === null, 'failed planner stable task');

putenv('TAVILY_API_KEY');
check(WebSearch::retrieve($plan)['status'] === 'unavailable', 'missing API key explicit');
putenv('TAVILY_API_KEY=fixture-not-a-real-key');
Http::$queue = [['code' => 429, 'data' => null], ['code' => 503, 'data' => null]];
check(WebSearch::retrieve($plan)['status'] === 'unavailable', 'API errors explicit');
Http::$queue = [['code' => 200, 'data' => ['results' => []]]];
check(WebSearch::retrieve(['query' => 'fixture', 'verify' => false])['status'] === 'empty', 'empty results explicit');

$fixture = ['url' => 'https://news.example/story', 'title' => '<b>Fixture report</b>', 'content' => 'A fictional event occurred on a documented date according to the official statement.', 'raw_content' => ''];
$sources = WebSearch::normalize([$fixture, ['url' => 'javascript:alert(1)', 'content' => str_repeat('x', 80)], ['url' => 'https://test.example', 'content' => 'tiny']]);
check(count($sources) === 1, 'unsafe URLs and empty bodies filtered');
check($sources[0]['content'] === $fixture['content'], 'empty raw content does not erase snippet');
check($sources[0]['title'] === 'Fixture report', 'HTML removed from title');
check(!WebSearch::validUrl('https://user:pass@example.com/a'), 'credentials URL rejected');
Http::$queue = [
    ['code' => 200, 'data' => ['results' => [$fixture]]],
    ['code' => 200, 'data' => ['results' => [$fixture, array_merge($fixture, ['url' => 'https://other.example/news'])]]],
];
$retrieved = WebSearch::retrieve($plan);
check(count($retrieved['sources']) === 2, 'duplicate URLs removed across queries');
check($retrieved['sources'][1]['id'] === 2, 'stable source IDs');

$j = ['answer' => 'این رویداد طبق گزارش ثبت شده.', 'supported' => true, 'citations' => [['id' => 2, 'quote' => 'A fictional event occurred on a documented date']]];
$v = WebSearch::validate($j, $retrieved['sources']);
check(count($v['sources']) === 1 && $v['sources'][0]['url'] === 'https://other.example/news', 'citations use supporting source not first hit');
$j['citations'][0]['id'] = 99;
check(WebSearch::validate($j, $retrieved['sources'])['sources'] === [], 'invented ID fails closed');
$j['citations'][0] = ['id' => 1, 'quote' => 'This is a completely fabricated supporting quotation'];
check(WebSearch::validate($j, $retrieved['sources'])['answer'] === WebSearch::INSUFFICIENT, 'invented quotation fails closed');
$j['citations'][0] = ['id' => 1, 'quote' => 'A fictional event occurred on a documented date'];
$j['supported'] = false;
check(WebSearch::validate($j, $retrieved['sources'])['sources'] === [], 'unsupported answer suppressed');
$j['supported'] = true;
$j['answer'] = '<b>پاسخ تست</b> https://invented.example [[reaction | 😎]]';
$v = WebSearch::validate($j, $retrieved['sources']);
check(!str_contains($v['answer'], '<b>') && !str_contains($v['answer'], 'http') && !str_contains($v['answer'], '[['), 'model markup and invented links removed');

AI::$queue = [json_encode($j, JSON_UNESCAPED_UNICODE), '{"supported":false}'];
check(WebSearch::answer('خبر حساس؟', [], [], $plan, $retrieved)['answer'] === WebSearch::INSUFFICIENT, 'independent check rejects reversed or unsupported claims');
AI::$queue = [json_encode($j, JSON_UNESCAPED_UNICODE), '{"supported":true}'];
check(count(WebSearch::answer('خبر حساس؟', [], [], $plan, $retrieved)['sources']) === 1, 'verified answer preserved');
AI::$queue = [json_encode($j, JSON_UNESCAPED_UNICODE), null];
check(WebSearch::answer('خبر حساس؟', [], [], $plan, $retrieved)['sources'] === [], 'checker timeout fails closed');
AI::$queue = ['invalid model output'];
check(WebSearch::answer('چی شد؟', [], [], ['verify' => false], $retrieved)['answer'] === WebSearch::INSUFFICIENT, 'malformed answer does not leak');
check(WebSearch::answer('چی شد؟', [], [], $plan, ['status' => 'unavailable'])['answer'] === WebSearch::UNAVAILABLE, 'search failure never answered from stale model');
WebSearch::setEnabled(false);
check(!WebSearch::enabled() && WebSearch::plan('آخرین خبر چیه؟') === null, 'OFF search skips planner');
check(WebSearch::retrieve($plan)['status'] === 'disabled' && Search::results('fixture') === [], 'OFF blocks both retrieval paths');
Store::$value = 'false';
check(!WebSearch::enabled(), 'legacy false string preserved');
WebSearch::setEnabled(true);
Http::$queue = [['code' => 200, 'data' => ['results' => [$fixture]]]];
$list = Search::results('fixture');
check(count($list) === 1 && $list[0]['url'] === $fixture['url'], 'explicit search shares Tavily retrieval');
$formatted = WebSearch::format(['answer' => '<tag> text & more', 'sources' => [['title' => 'News & source', 'url' => 'https://fixture.example/a?q=1&b=2']]]);
check(str_contains($formatted['html'], 'News &amp; source') && !str_contains($formatted['plain'], '<a '), 'guest HTML and plain fallback are separate');
echo "Web search: {$passed} checks passed\n";
