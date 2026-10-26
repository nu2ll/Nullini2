<?php

$db = sys_get_temp_dir() . '/nullini_test_' . getmypid() . '.db';
putenv("SQLITE_PATH={$db}");
putenv('DATABASE_URL=');
putenv('GITHUB_TOKEN=');
putenv('GEMINI_KEYS=');
putenv('BOT_USERNAME=TestBot');
putenv('LOG_TO_STDERR=1');

require dirname(__DIR__) . '/src/bootstrap.php';
App::boot();

$failed = 0;
$passed = 0;
function check(string $name, $got, $want): void
{
    global $failed, $passed;
    if ($got === $want) {
        $passed++;
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n");
}

foreach (glob(dirname(__DIR__) . '/src/*/*.php') as $f) {
    check('load ' . basename($f), class_exists(basename($f, '.php')), true);
}

Db::migrate();
Db::migrate();
Store::set('t', 'a', ['x' => 1, 'list' => [3, 2, 1], 'fa' => 'سلام']);
check('store get', Store::get('t', 'a'), ['x' => 1, 'list' => [3, 2, 1], 'fa' => 'سلام']);
check('store default', Store::get('t', 'nope', 'd'), 'd');
check('store update', Store::update('t', 'a', fn($s) => ['x' => $s['x'] + 1] + $s)['x'], 2);
check('store update null', Store::update('t', 'a', fn($s) => null), null);
check('store unchanged', Store::get('t', 'a')['x'], 2);
check('store new via update', Store::update('t', 'b', fn($s) => ($s ?? 0) + 5), 5);
check('store count', Store::count('t'), 2);
check('remember', Store::remember('t', 'r', 60, fn() => 'v1'), 'v1');
check('remember cached', Store::remember('t', 'r', 60, fn() => 'v2'), 'v1');
Store::delete('t', 'b');
check('store delete', Store::get('t', 'b'), null);
check('cleanup none', Store::cleanup(['t' => 3600]), 0);

Users::touch(['id' => 42, 'first_name' => 'Ali', 'username' => 'ali'], true);
Users::touch(['id' => 43, 'first_name' => 'Sara']);
Users::touch(['id' => 42, 'first_name' => 'Ali2']);
$sum = Users::summary();
check('users total', (int) $sum['total'], 2);
check('users reachable', (int) $sum['reachable'], 1);
$ids = [];
Users::eachReachable(function (int $id) use (&$ids) { $ids[] = $id; });
check('users reachable ids', $ids, [42]);
Users::hit('test');
Users::hit('test');
check('stats hit', (int) Users::topEvents(1)[0]['hits'], 2);

check('sync off without token', Backup::enabled(), false);
$snap = Backup::snapshot();
check('snapshot is sqlite', str_starts_with($snap, "SQLite format 3\0"), true);
$copy = sys_get_temp_dir() . '/nullini_copy_' . getmypid() . '.db';
file_put_contents($copy, $snap);
check('snapshot healthy', Backup::verify($copy), true);
check('snapshot has users', (int) (new PDO('sqlite:' . $copy))->query('SELECT COUNT(*) FROM users')->fetchColumn(), 2);
file_put_contents($copy, 'not a database');
check('broken file detected', Backup::verify($copy), false);
@unlink($copy);
Backup::markPushed(Backup::fingerprint());
check('clean after push', Backup::isDirty(), false);
Store::set('t', 'dirty', 1);
check('dirty after write', Backup::isDirty(), true);
check('no-op write stays clean', (function () { Backup::markPushed(Backup::fingerprint()); Db::run('DELETE FROM kv WHERE ns = ?', ['nothing']); return Backup::isDirty(); })(), false);
@unlink(NULLINI_DATA . '/.last_push');
@unlink(Db::changeMarker());

[$sys, $gc] = AI::toGemini([
    ['role' => 'system', 'content' => 'S'],
    ['role' => 'user', 'content' => 'hi'],
    ['role' => 'assistant', 'content' => 'yo'],
    ['role' => 'user', 'content' => [['type' => 'media', 'mime' => 'audio/ogg', 'data' => 'AA=='], ['type' => 'text', 'text' => 'q']]],
]);
check('gemini system', $sys, 'S');
check('gemini roles', array_column($gc, 'role'), ['user', 'model', 'user']);
check('gemini media', $gc[2]['parts'][0]['inline_data']['mime_type'] ?? null, 'audio/ogg');
check('ai no key', AI::keys() ? null : AI::chat([['role' => 'user', 'content' => 'x']]), null);
check('quota reset future', AI::quotaResetAt() > time(), true);
check('models chain', count(AI::models('smart')) > 0 && count(AI::models('lite')) > 0, true);

check('ai route default', AI::route(), 'gemini');
check('ai route gemini tiers', AI::hasTiers(), true);
$pin = AI::allModels()[0];
AI::setRoute('gemini:' . $pin);
check('ai route pinned gemini', AI::route(), 'gemini:' . $pin);
check('ai pinned no tiers', AI::hasTiers(), false);
AI::setRoute('gemini:no-such-model');
check('ai route bad model ignored', AI::route(), 'gemini:' . $pin);
AI::setRoute('groq');
check('ai route groq auto', AI::route(), 'groq');
AI::setRoute('groq:some-model');
check('ai route groq pinned', AI::route(), 'groq:some-model');
check('ai route label groq auto', AiPanel::label('groq'), 'Groq · خودکار');
check('ai route label gemini', AiPanel::label('gemini'), 'Gemini · خودکار');
AI::setRoute('gemini');
check('ai route back to auto', AI::route(), 'gemini');
check('react prefix', AI::splitReaction("[[reaction | 🤔]]\n\nسلام"), ['🤔', 'سلام']);
check('react alias', AI::splitReaction("[[reaction | 😂]]\n\nx")[0], '🤣');
check('react plain text kept', AI::splitReaction('Reaction time is short'), [null, 'Reaction time is short']);
check('bare emoji reaction', AI::splitReaction("😘\n\nبیا، اینم یه بوس", true), ['😘', 'بیا، اینم یه بوس']);
check('bare emoji alias', AI::splitReaction("😂\nخنده‌دار بود", true)[0], '🤣');
check('bare emoji off when disabled', AI::splitReaction("😘\n\nسلام", false), [null, "😘\n\nسلام"]);
check('bare emoji alone kept as text', AI::splitReaction('😘', true), [null, '😘']);
check('emoji + text same line kept', AI::splitReaction("😘 سلام\nخوبی", true), [null, "😘 سلام\nخوبی"]);
check('react colon', AI::splitReaction("reaction: 😘\nسلام"), ['😘', 'سلام']);
check('isEmojiLine', [AI::isEmojiLine('😘'), AI::isEmojiLine('❤‍🔥'), AI::isEmojiLine('hi 😘'), AI::isEmojiLine('')], [true, true, false, false]);
$panel = AiPanel::providerKeyboard('gemini', 0)['inline_keyboard'];
check('panel auto first', $panel[0][0]['callback_data'], 'ai:set:gemini:a:0');
check('panel gemini models listed', count(AI::allModels()) > 0 && count($panel) >= 3, true);
foreach (AiPanel::keyboard()['inline_keyboard'] as $r) {
    check('panel cb short', strlen($r[0]['callback_data']) <= 64, true);
}
foreach ($panel as $r) {
    foreach ($r as $b) {
        check('panel model cb short', strlen($b['callback_data']) <= 64, true);
    }
}

foreach (['2+3*4' => '14', '(1+2)^2' => '9', '2^3^2' => '512', '10/4' => '2.5', '1/0' => 'خطا', '-3+5' => '2', '۲×۳' => '6', '(2+3' => '5'] as $e => $r) {
    check("calc {$e}", Calculator::evaluate($e), $r);
}
$x = '0';
foreach (['1', '2', '+', '3', '*', '(', '2', ')', '='] as $k) {
    $x = Calculator::press($x, $k);
}
check('calc press', $x, '18');
check('calc detect', (bool) Calculator::looksLikeExpression('12*3'), true);
check('calc detect text', (bool) Calculator::looksLikeExpression('salam'), false);

check('jalali 1', Jalali::fromGregorian(2026, 10, 20), [1405, 7, 28]);
check('jalali nowruz', Jalali::fromGregorian(2026, 3, 21), [1405, 1, 1]);
check('jalali esfand', Jalali::fromGregorian(2026, 3, 20), [1404, 12, 29]);
check('nid valid', Tools::isValidNid('1234567891'), true);
check('nid invalid', Tools::isValidNid('1234567890'), false);
check('nid same', Tools::isValidNid('1111111111'), false);
check('password len', strlen(Tools::password(16)), 16);

function fen(string $f): array
{
    [$pos, $turn, $castle, $ep] = explode(' ', $f);
    $b = '';
    foreach (str_split(str_replace('/', '', $pos)) as $ch) {
        $b .= ctype_digit($ch) ? str_repeat('.', (int) $ch) : $ch;
    }
    $epSq = $ep === '-' ? -1 : (8 - (int) $ep[1]) * 8 + (ord($ep[0]) - 97);
    return ['b' => $b, 't' => $turn, 'c' => $castle === '-' ? '' : $castle, 'ep' => $epSq, 'h' => 0, 'f' => 1];
}
function perft(array $s, int $d): int
{
    $moves = ChessEngine::legal($s);
    if ($d === 1) {
        return count($moves);
    }
    $n = 0;
    foreach ($moves as $m) {
        $n += perft(ChessEngine::make($s, $m), $d - 1);
    }
    return $n;
}
$start = ChessEngine::newState();
check('perft start 3', perft($start, 3), 8902);
check('perft kiwipete 2', perft(fen('r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq -'), 2), 2039);
check('perft pos3 3', perft(fen('8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - -'), 3), 2812);
check('perft pos4 2', perft(fen('r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq -'), 2), 264);
check('perft pos5 2', perft(fen('rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ -'), 2), 1486);
check('san e4', ChessEngine::san($start, ChessEngine::move(52, 36)), 'e4');
check('san Nf3', ChessEngine::san($start, ChessEngine::move(62, 45)), 'Nf3');
$mate = fen('r1bqkbnr/pppp1ppp/2n5/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR w KQkq -');
$bm   = ChessEngine::bestMove($mate, 3);
check('bot finds mate', $bm !== null ? ChessEngine::san($mate, $bm) : null, 'Qxf7#');
check('mate detected', ChessEngine::outcome(ChessEngine::make($mate, $bm)), 'mate');
check('stalemate', ChessEngine::outcome(fen('7k/5Q2/6K1/8/8/8/8/8 b - -')), 'stalemate');
check('insufficient', ChessEngine::outcome(fen('8/8/4k3/8/8/3NK3/8/8 w - -')), 'material');

foreach (array_keys(Ui::GUIDES) as $g) {
    check("guide {$g}", Ui::guide($g) !== '', true);
}
check('start text', str_contains(Ui::startText(['id' => 1, 'first_name' => '<b>x</b>']), '&lt;b&gt;'), true);
check('icon url', str_starts_with(Ui::icon('chess'), 'https://raw.githubusercontent.com/'), true);
check('calc done', Calculator::render('8', true), "<b>🧮 ماشین‌حساب نالینی</b>\n\n= <b>8</b>");
check('calc typing no preview', str_contains(Calculator::render('2+3'), '='), false);
check('commands admin', count(Ui::commands('admin')), 12);
check('commands private', count(Ui::commands('private')), 7);
$gs = ['mode' => 'pvp', 'p1' => ['id' => 1, 'name' => 'a'], 'p2' => null, 'status' => 'playing'];
check('control cancel', GameKit::controlRow($gs, 'ttt')[0]['callback_data'], 'ttt:cancel');
$gs['p2'] = ['id' => 2, 'name' => 'b'];
check('control resign', GameKit::controlRow($gs, 'ttt')[0]['callback_data'], 'ttt:resign');
$gs['resign_ask'] = ['2' => time()];
check('resign note', GameKit::resignNote($gs), '');
$gs['resigned'] = 2;
check('resign line', str_contains(GameKit::resignLine($gs), 'تسلیم شد'), true);
check('google url', Search::googleUrl('سلام دنیا'), 'https://www.google.com/search?q=%D8%B3%D9%84%D8%A7%D9%85+%D8%AF%D9%86%DB%8C%D8%A7&hl=fa');
check('hello varies', str_contains(Ui::hello(), 'منو'), true);
check('mbTrim fa', mbTrim(' اینو برسی کن،', " \t\n،,:"), 'اینو برسی کن');
check('mbTrim keeps ی', mbTrim('«سلام علی»', '«»'), 'سلام علی');
check('mbTrim utf8 ok', mb_check_encoding(mbTrim(' سلام', " ،"), 'UTF-8'), true);
$fonts = Fonts::all('Arown');
check('font bold', $fonts[0][1], '𝐀𝐫𝐨𝐰𝐧');
check('font many', count($fonts) > 25, true);
check('font fa stretch', Fonts::all('سلام')[0][1], 'ســـلـــام');
check('ai clean', AI::clean('**سلام** دنیا'), 'سلام دنیا');
check('ai html', AI::toHtml("a<b\n```php\necho 1;\n```"), "a&lt;b\n<pre>echo 1;</pre>");

check('whisper kb token', Whisper::pendingKeyboard('0123456789abcdef')['inline_keyboard'][0][0]['callback_data'], 'wh:show:0123456789abcdef');
check('weather kb hash', Weather::keyboard('0123456789abcdef')['inline_keyboard'][0][0]['callback_data'], 'weather:refresh:0123456789abcdef');

check('dl yt', Downloader::match('https://youtu.be/dQw4w9WgXcQ')['site'] ?? '', 'youtube');
check('dl insta no scheme', Downloader::match('www.instagram.com/reel/abc123/')['url'] ?? '', 'https://www.instagram.com/reel/abc123/');
check('dl with word ignored', Downloader::match('دانلود https://www.tiktok.com/@a/video/1'), null);
check('dl x.com', Downloader::match('https://x.com/user/status/123')['name'] ?? '', 'توییتر');
check('dl mobile yt', Downloader::match('https://m.youtube.com/watch?v=abc')['site'] ?? '', 'youtube');
check('dl ignore chat', Downloader::match('این لینک رو ببین https://youtu.be/x'), null);
check('dl ignore other site', Downloader::match('https://google.com/search?q=a'), null);
check('dl fake host', Downloader::match('https://notyoutube.com/x'), null);
check('dl spotify', Downloader::match('https://open.spotify.com/intl-fa/track/4uLU6hMCjMI75M1A2tKUQC?si=x')['site'] ?? '', 'spotify');
check('dl snapchat', Downloader::match('https://www.snapchat.com/spotlight/W7_EDlXWTBiXAEEniNoMPwAAYYWtidGhudGZpAX1TKn0JAX1TKnXJAAAAAA')['site'] ?? '', 'snapchat');
check('dl aparat gone', Downloader::match('https://www.aparat.com/v/abc'), null);
check('dl find in text', Downloader::find('اینو برام دانلود کن https://youtu.be/dQw4w9WgXcQ لطفا')['site'] ?? '', 'youtube');
check('photo insta code', Photos::igCode('https://www.instagram.com/p/C1a2B3c4D5e/?img_index=1'), 'C1a2B3c4D5e');
check('photo unescape', Photos::unescape('https:\\/\\/x.com\\/a.jpg?b=1\\u0026c=2'), 'https://x.com/a.jpg?b=1&c=2');
check('intent download', Intent::interpret('{"tool":"download","arg":"https://youtu.be/x","evidence":"دانلود کن"}', 'اینو دانلود کن https://youtu.be/x'), ['download', 'https://youtu.be/x']);
check('help page 1', Ui::helpPage('ai'), 1);
check('help page 2', Ui::helpPage('group'), 2);
check('help menu split', count(Ui::helpMenu(true, 1)['inline_keyboard']) + count(Ui::helpMenu(true, 2)['inline_keyboard']), 15);
$hm = static function (int $page): array {
    $out = [];
    foreach (Ui::helpMenu(true, $page)['inline_keyboard'] as $row) {
        $out[] = array_map(static fn($b) => $b['callback_data'], $row);
    }
    return $out;
};
$seenKeys = [];
foreach ([1, 2] as $pg) {
    foreach ($hm($pg) as $row) {
        foreach ($row as $cb) {
            if (str_starts_with($cb, 'help:') && isset(Ui::GUIDES[substr($cb, 5)])) {
                $seenKeys[] = substr($cb, 5);
            }
        }
    }
}
sort($seenKeys);
$allKeys = array_keys(Ui::GUIDES);
sort($allKeys);
check('help layout covers all guides once', $seenKeys, $allKeys);
$p1 = $hm(1);
$p2 = $hm(2);
check('help whisper single above font', [$p1[2], $p1[3]], [['help:whisper'], ['help:font', 'help:download']]);
check('help group single under love', [$p2[1], $p2[2]], [['help:qr', 'help:love'], ['help:group']]);
check('help next arrow', Ui::helpMenu(true, 1)['inline_keyboard'][5][0]['text'], '➡️ صفحه‌ی بعد');
check('help prev arrow', Ui::helpMenu(true, 2)['inline_keyboard'][6][0]['text'], 'صفحه‌ی قبل ⬅️');
check('intent aicheck', Intent::interpret('{"tool":"aicheck","arg":"","evidence":"AI هست"}', 'این عکس AI هست؟'), ['aicheck', '']);
check('aicheck guide no try button', count(Ui::guideKeyboard('aicheck', true)['inline_keyboard']), 1);
$pngChunk = static fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . "\0\0\0\0";
$pngSig   = "\x89PNG\r\n\x1A\n";
$aiSd     = AiDetect::metadata($pngSig . $pngChunk('tEXt', "parameters\0a cat\nSteps: 20, Sampler: Euler a, CFG scale: 7") . $pngChunk('IEND', ''));
check('aidetect sd tools', $aiSd['tools'], ['Stable Diffusion']);
check('aidetect sd flag', $aiSd['ai'], true);
$aiIptc = AiDetect::metadata($pngSig . $pngChunk('tEXt', "Comment\0http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia") . $pngChunk('IEND', ''));
check('aidetect trained', [$aiIptc['ai'], $aiIptc['trained'], $aiIptc['edited']], [true, true, false]);
$aiMix = AiDetect::metadata($pngSig . $pngChunk('tEXt', "Comment\0compositeWithTrainedAlgorithmicMedia") . $pngChunk('IEND', ''));
check('aidetect composite', [$aiMix['ai'], $aiMix['edited']], [false, true]);
$aiZ = AiDetect::metadata($pngSig . $pngChunk('zTXt', "parameters\0\0" . gzcompress('Midjourney job')) . $pngChunk('IEND', ''));
check('aidetect ztxt', $aiZ['tools'], ['Midjourney']);
$aiClean = AiDetect::metadata($pngSig . $pngChunk('tEXt', "Software\0GIMP 2.10") . $pngChunk('IEND', ''));
check('aidetect clean', [$aiClean['ai'], $aiClean['edited'], $aiClean['tools']], [false, false, []]);
$jpgSeg = "Exif\0\0Software\0Midjourney V6";
$aiJpg  = AiDetect::metadata("\xFF\xD8\xFF\xE1" . pack('n', strlen($jpgSeg) + 2) . $jpgSeg . "\xFF\xDA\x00\x02");
check('aidetect jpeg', $aiJpg['tools'], ['Midjourney']);
$webpData = 'XMP ' . pack('V', 10) . 'Ideogram 1';
$aiWebp   = AiDetect::metadata('RIFF' . pack('V', strlen($webpData) + 4) . 'WEBP' . $webpData);
check('aidetect webp', $aiWebp['tools'], ['Ideogram']);
check('aidetect pages short', AiDetect::pages("a\nb"), ["a\nb"]);
check('aidetect pages split', array_map('mb_strlen', AiDetect::pages(implode("\n", array_fill(0, 3, str_repeat('x', 1900))))), [3801, 1900]);
check('aidetect junk', AiDetect::metadata('not an image')['ai'], false);
check('roast token user', Roast::token('نالینی @ali_reza رو روست کن'), '@ali_reza');
check('roast token id', Roast::token('روست کن ۱۲۳۴۵۶۷۸۹'), '123456789');
check('roast token me', Roast::token('me'), 'me');
check('roast token reply', Roast::token('اینو روست کن'), '');
check('intent roast', Intent::interpret('{"tool":"roast","arg":"reply","evidence":"روست کن"}', 'اینو روست کن'), ['roast', 'reply']);
check('intent love', Intent::interpret('{"tool":"love","arg":"علی | سارا","evidence":"درصد عشق"}', 'درصد عشق علی و سارا'), ['love', 'علی | سارا']);
check('dl bar', Downloader::bar(40), '▰▰▰▰▱▱▱▱▱▱');
check('dl clock', Downloader::clock(3725), '1:02:05');
check('dl explain bot', str_contains(Downloader::explain("ERROR: Sign in to confirm you're not a bot"), 'ربات'), true);
check('dl explain size', str_contains(Downloader::explain('File is larger than max-filesize'), Downloader::limitText()), true);

$wx = Weather::toArray([
    'location' => ['name' => 'تهران', 'country' => 'ایران'],
    'raw'      => ['current' => ['temperature_2m' => 21.6, 'apparent_temperature' => 20, 'weather_code' => 0, 'is_day' => 1, 'time' => '2026-10-20T12:30'],
                   'daily' => ['time' => ['a', 'b'], 'temperature_2m_max' => [25, 24], 'temperature_2m_min' => [12, 11], 'weather_code' => [0, 3]]],
    'air'      => ['us_aqi' => 42],
]);
check('wx temp', $wx['temp'], 22);
check('wx days', count($wx['days']), 2);
check('wx aqi', $wx['aqi']['v'], 42);
check('wx local', $wx['local'], '12:30');

check('gw guide', str_contains(Ui::guide('giveaway'), 'قرعه‌کشی'), true);
Db::run('INSERT INTO gw_entries (gid, uid, name, at) VALUES (?, ?, ?, ?) ON CONFLICT (gid, uid) DO NOTHING', ['abc', 1, 'a', time()]);
$dup = Db::run('INSERT INTO gw_entries (gid, uid, name, at) VALUES (?, ?, ?, ?) ON CONFLICT (gid, uid) DO NOTHING', ['abc', 1, 'a', time()])->rowCount();
check('gw dup', $dup, 0);
check('gw count', Giveaway::count('abc'), 1);
check('gw text', str_contains(Giveaway::publicText(['prize' => 'P', 'winners' => 2, 'ends' => 0], 5), '۵'), true);
check('gw pending', Giveaway::pending(), false);
check('intent fenced json', Intent::interpret("```json\n{\"tool\":\"wiki\",\"arg\":\"Hafez\",\"evidence\":\"ویکی\"}\n```", 'ویکی حافظ'), ['wiki', 'Hafez']);
check('intent weather', Intent::interpret('{"tool":"weather","arg":"Tehran","evidence":"هوای تهران"}', 'هوای تهران چطوره؟'), ['weather', 'Tehran']);
check('intent translate keeps lines', Intent::interpret('{"tool":"translate","arg":"English | سلام\nخوبی","evidence":"ترجمه کن"}', 'ترجمه کن'), ['translate', "English | سلام\nخوبی"]);
check('intent evidence not in message', Intent::interpret('{"tool":"wiki","arg":"Mourinho","evidence":"درباره‌ی مورینیو"}', 'اینو اصلا مال فنرباغچه هم نبود'), null);
check('intent evidence missing', Intent::interpret('{"tool":"date","arg":"","evidence":""}', 'ساعت چنده'), null);
check('intent evidence arabic letters', Intent::grounded('كجاست', 'کجاست؟'), true);
check('intent image', Intent::interpret('{"tool":"image","arg":"گربه تو فضا","evidence":"عکس بساز"}', 'یه عکس بساز گربه تو فضا'), ['image', 'گربه تو فضا']);
check('intent image no subject', Intent::interpret('{"tool":"image","arg":"","evidence":"بکش"}', 'یه چیز باحال بکش'), ['image', '']);
check('intent image evidence not in message', Intent::interpret('{"tool":"image","arg":"cat","evidence":"عکس بساز"}', 'این عکس قشنگه'), null);
check('intent none', Intent::interpret('{"tool":"none","arg":"","evidence":""}', 'سلام'), null);
check('intent unknown tool', Intent::interpret('{"tool":"hack","arg":"","evidence":"سلام"}', 'سلام'), null);
check('intent not json', Intent::interpret('سلام', 'سلام'), null);
check('intent null raw', Intent::interpret(null, 'سلام'), null);
check('intent empty text', Intent::decide('   '), null);
check('route ctx photo reply', Assistant::routeContext(['photo' => [[]], 'reply_to_message' => ['text' => 'x', 'from' => ['is_bot' => true]]]), ['attach' => 'photo', 'reply_kind' => '', 'reply_text' => 'x', 'reply_bot' => true, 'history' => []]);

$buf = '';
check('sse partial', Http::sseData($buf, "data: {\"a\":1}\r\ndata: {\"b\""), ['{"a":1}']);
check('sse rest', Http::sseData($buf, ":2}\n\ndata: [DONE]\n"), ['{"b":2}', '[DONE]']);

$live = new LiveReply(['chat' => ['id' => 1, 'type' => 'private'], 'message_id' => 1]);
$body = new ReflectionMethod($live, 'body');
check('live hold reaction', $body->invoke($live, '[[reaction | 🔥]]', false), null);
check('live strip reaction', $body->invoke($live, "[[reaction | 🔥]]\n\nسلام، خوبی؟", false), 'سلام، خوبی؟');
check('live persian first', $body->invoke($live, 'سلام', false), 'سلام');
check('live image tag hidden', $body->invoke($live, "[[reaction | 🎨]]\n[[image | a cat in space]]", false), false);
check('live action hidden', $body->invoke($live, "[[reaction | 👍]]\n[[weather | Tehran]]", false), false);
check('live hold english', $body->invoke($live, "[[reaction | 👍]]\nACT", false), null);
check('live hold command word', $body->invoke($live, "[[reaction | 👍]]\nACTION", false), null);
check('live english ok', $body->invoke($live, "[[reaction | 👍]]\nHello there, how can I help you today?", false), 'Hello there, how can I help you today?');
check('live think hidden', $body->invoke($live, "<think>hmm", false), null);

@unlink($db);
@unlink($db . '-wal');
@unlink($db . '-shm');

echo $failed ? "tests: {$failed} failed, {$passed} passed\n" : "tests: all {$passed} passed\n";
exit($failed ? 1 : 0);
