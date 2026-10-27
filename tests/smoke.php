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
check('commands admin', count(Ui::commands('admin')), 16);
check('commands private', count(Ui::commands('private')), 7);
check('switch default on', Switches::on('chess'), true);
Switches::toggle('image');
check('switch off', Switches::on('image'), false);
check('commands hide img', in_array('img', array_column(Ui::commands('private'), 'command'), true), false);
check('commands private off', count(Ui::commands('private')), 6);
Switches::toggle('image');
check('switch back on', Switches::on('image'), true);
check('commands show img', in_array('img', array_column(Ui::commands('private'), 'command'), true), true);
Switches::toggle('chess');
check('games independent', [Switches::on('chess'), Switches::on('ttt')], [false, true]);
Switches::toggle('chess');
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
check('photo unescape', Scrape::unescape('https:\\/\\/x.com\\/a.jpg?b=1\\u0026c=2'), 'https://x.com/a.jpg?b=1&c=2');
check('intent download', Intent::parse('[[download | https://youtu.be/x]]'), ['download', 'https://youtu.be/x']);
check('help page 1', Ui::helpPage('ai'), 1);
check('help page 2', Ui::helpPage('group'), 2);
check('help menu split', count(Ui::helpMenu(true, 1)['inline_keyboard']) + count(Ui::helpMenu(true, 2)['inline_keyboard']), 14);
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
check('help prev arrow', Ui::helpMenu(true, 2)['inline_keyboard'][5][0]['text'], 'صفحه‌ی قبل ⬅️');
check('roast token user', Roast::token('نالینی @ali_reza رو روست کن'), '@ali_reza');
check('roast token id', Roast::token('روست کن ۱۲۳۴۵۶۷۸۹'), '123456789');
check('roast token me', Roast::token('me'), 'me');
check('roast token reply', Roast::token('اینو روست کن'), '');
check('intent roast', Intent::parse('[[roast | reply]]'), ['roast', 'reply']);
check('intent love', Intent::parse('[[love | علی | سارا]]'), ['love', 'علی | سارا']);
check('dl bar', Downloader::bar(40), '▰▰▰▰▱▱▱▱▱▱');
check('dl clock', Downloader::clock(3725), '1:02:05');
check('dl explain bot', str_contains(Downloader::explain("ERROR: Sign in to confirm you're not a bot"), 'ربات'), true);
check('dl explain size', str_contains(Downloader::explain('File is larger than max-filesize'), Downloader::limitText()), true);

$gd = json_encode([[2, ['category' => 'instagram']], [3, 'https://cdn.x/1.jpg?a=1', ['extension' => 'jpg']], [3, 'https://cdn.x/2.png', ['extension' => 'png']], [3, 'https://cdn.x/1.jpg?a=2', ['extension' => 'jpg']]]);
check('gdl photos', GalleryDl::parse((string) $gd), [['https://cdn.x/1.jpg?a=1'], ['https://cdn.x/2.png']]);
check('gdl video left to ytdlp', GalleryDl::parse((string) json_encode([[3, 'https://cdn.x/1.jpg', ['extension' => 'jpg']], [3, 'https://cdn.x/v.mp4', ['extension' => 'mp4']]])), []);
check('gdl ytdl prefix', GalleryDl::parse((string) json_encode([[3, 'ytdl:https://x.com/a', []]])), []);
check('gdl ext from url', GalleryDl::parse((string) json_encode([[3, 'https://i.pinimg.com/originals/a/1.jpg', []]])), [['https://i.pinimg.com/originals/a/1.jpg']]);
check('gdl garbage', GalleryDl::parse('not json'), []);
check('gdl queued', GalleryDl::queued((string) json_encode([[6, 'https://www.pinterest.com/pin/123/', []]])), 'https://www.pinterest.com/pin/123/');
check('gdl no queue', GalleryDl::queued('[]'), null);
check('gdl supports', [GalleryDl::supports('instagram'), GalleryDl::supports('pinterest'), GalleryDl::supports('reddit')], [true, true, false]);
check('entry kind photo', Downloader::entryKind(['ext' => 'jpg']), 'p');
check('entry kind video', Downloader::entryKind(['ext' => 'mp4']), 'v');
check('entry kind formats', Downloader::entryKind(['formats' => [['vcodec' => 'h264']]]), 'v');
$ad = sys_get_temp_dir() . '/nullini_album_' . getmypid();
@mkdir($ad);
foreach (['10_c.jpg', '02_b.mp4', '01_a.jpg', '03_x.part', '04_y.txt'] as $f) {
    file_put_contents("{$ad}/{$f}", 'x');
}
check('album files order', array_map(static fn($i) => basename($i[1]), Downloader::albumFiles($ad)), ['01_a.jpg', '02_b.mp4', '10_c.jpg']);
check('album files kinds', array_column(Downloader::albumFiles($ad), 0), ['photo', 'video', 'photo']);
array_map('unlink', glob("{$ad}/*"));
@rmdir($ad);

check('gw guide', str_contains(Ui::guide('giveaway'), 'قرعه‌کشی'), true);
Db::run('INSERT INTO gw_entries (gid, uid, name, at) VALUES (?, ?, ?, ?) ON CONFLICT (gid, uid) DO NOTHING', ['abc', 1, 'a', time()]);
$dup = Db::run('INSERT INTO gw_entries (gid, uid, name, at) VALUES (?, ?, ?, ?) ON CONFLICT (gid, uid) DO NOTHING', ['abc', 1, 'a', time()])->rowCount();
check('gw dup', $dup, 0);
check('gw count', Giveaway::count('abc'), 1);
check('gw text', str_contains(Giveaway::publicText(['prize' => 'P', 'winners' => 2, 'ends' => 0], 5), '۵'), true);
check('gw pending', Giveaway::pending(), false);
check('intent loose', Intent::parse('**[[wiki | Hafez]]**'), ['wiki', 'Hafez']);
check('intent parse', Intent::parse('[[weather | Tehran]]'), ['weather', 'Tehran']);
check('intent translate', Intent::parse("[[translate | English | سلام\nخوبی]]"), ['translate', "English | سلام\nخوبی"]);
check('intent parse bad', Intent::parse('[[hack]]'), null);
check('intent parse none', Intent::parse('سلام'), null);

$buf = '';
check('sse partial', Http::sseData($buf, "data: {\"a\":1}\r\ndata: {\"b\""), ['{"a":1}']);
check('sse rest', Http::sseData($buf, ":2}\n\ndata: [DONE]\n"), ['{"b":2}', '[DONE]']);

$live = new LiveReply(['chat' => ['id' => 1, 'type' => 'private'], 'message_id' => 1]);
$body = new ReflectionMethod($live, 'body');
check('live hold reaction', $body->invoke($live, '[[reaction | 🔥]]', false), null);
check('live strip reaction', $body->invoke($live, "[[reaction | 🔥]]\n\nسلام، خوبی؟", false), 'سلام، خوبی؟');
check('live persian first', $body->invoke($live, 'سلام', false), 'سلام');
check('live image drawing', $body->invoke($live, "[[reaction | 🎨]]\n[[image | a cat in space]]", false), LiveReply::DRAWING);
check('live action hidden', $body->invoke($live, "[[reaction | 👍]]\n[[weather | Tehran]]", false), false);
check('live hold english', $body->invoke($live, "[[reaction | 👍]]\nACT", false), null);
check('live hold command word', $body->invoke($live, "[[reaction | 👍]]\nACTION", false), null);
check('live english ok', $body->invoke($live, "[[reaction | 👍]]\nHello there, how can I help you today?", false), 'Hello there, how can I help you today?');
check('live think hidden', $body->invoke($live, "<think>hmm", false), null);

$ct = ['contact' => ['phone_number' => '+989120000000', 'first_name' => 'علی', 'last_name' => 'رضایی', 'user_id' => 123, 'vcard' => "BEGIN:VCARD\nORG:شرکت نمونه\nEND:VCARD"]];
check('contact detected', Assistant::hasMedia($ct), true);
check('contact kind', Assistant::kind($ct), 'مخاطب');
check('contact info', Assistant::dataInfo($ct), ['مخاطب', 'نام: علی رضایی | شماره: +989120000000 | آیدی عددی تلگرام: 123 (عضو تلگرامه) | سازمان: شرکت نمونه']);
check('dice plain', Assistant::dataInfo(['dice' => ['emoji' => '🎲', 'value' => 4]]), ['تاس', 'ایموجی: 🎲 | نتیجه: 4']);
check('dart kind', Assistant::kind(['dice' => ['emoji' => '🎯', 'value' => 6]]), 'دارت');
check('dart name', Assistant::dataInfo(['dice' => ['emoji' => '🎯', 'value' => 6]])[0], 'دارت');
check('slot jackpot', Assistant::dataInfo(['dice' => ['emoji' => '🎰', 'value' => 64]])[1], 'ایموجی: 🎰 | نتیجه: 64 | قرقره‌ها از چپ به راست: ۷ - ۷ - ۷ | سه نماد یکسان یعنی برد و سه تا ۷ یعنی جک‌پات');
check('slot grapes', str_contains(Assistant::dataInfo(['dice' => ['emoji' => '🎰', 'value' => 22]])[1], 'انگور - انگور - انگور'), true);
check('slot bars', str_contains(Assistant::dataInfo(['dice' => ['emoji' => '🎰', 'value' => 1]])[1], 'بار - بار - بار'), true);
check('basketball name', Assistant::dataInfo(['dice' => ['emoji' => '🏀', 'value' => 5]])[0], 'بسکتبال');
check('football goal rule', str_contains(Assistant::dataInfo(['dice' => ['emoji' => '⚽', 'value' => 3]])[1], 'عدد ۳ و ۴ و ۵ یعنی گل شد'), true);

Switches::setAll(true);
check('switch all on', array_filter(array_keys(Switches::TOOLS), static fn($k) => !Switches::on($k)), []);
check('switch tools count', count(Switches::TOOLS), 26);
check('switch no old games key', isset(Switches::TOOLS['games']), false);
foreach (['chess', 'ttt', 'c4', 'rps', 'math'] as $g) {
    check("switch game key {$g}", isset(Switches::TOOLS[$g]), true);
}
check('switch unknown key stays on', Switches::on('no_such_tool'), true);
Switches::toggle('no_such_tool');
check('switch unknown toggle ignored', Switches::on('no_such_tool'), true);
Switches::setAll(false);
check('switch all off', array_filter(array_keys(Switches::TOOLS), static fn($k) => Switches::on($k)), []);
check('switch guard off', Switches::guard('ai'), false);
check('commands all off private', array_column(Ui::commands('private'), 'command'), ['start', 'help']);
Switches::setAll(true);
check('switch guard on', Switches::guard('ai'), true);
check('switch text no off line', str_contains(Switches::text(), 'خاموش:'), false);
Switches::toggle('qr');
check('switch text counts off', str_contains(Switches::text(), 'خاموش: ۱ از ' . fa((string) count(Switches::TOOLS))), true);
Switches::toggle('qr');
$kbButtons = array_merge(...Switches::keyboard()['inline_keyboard']);
check('switch keyboard buttons', count($kbButtons), count(Switches::TOOLS) + 2);
check('switch keyboard callbacks short', array_filter($kbButtons, static fn($b) => strlen($b['callback_data']) > 64), []);
check('switch keyboard toggles', count(array_filter($kbButtons, static fn($b) => str_starts_with($b['callback_data'], 'tl:t:'))), count(Switches::TOOLS));
check('switch keyboard all buttons', array_values(array_map(static fn($b) => $b['callback_data'], array_slice($kbButtons, -2))), ['tl:all:1', 'tl:all:0']);
Switches::toggle('chess');
$kbOff = array_values(array_filter(array_merge(...Switches::keyboard()['inline_keyboard']), static fn($b) => $b['callback_data'] === 'tl:t:chess'));
check('switch keyboard shows off', $kbOff[0]['style'] ?? null, 'danger');
Switches::toggle('chess');
Store::set('cfg', 'tools_off', ['games', 'qr']);
check('legacy games chess', Switches::on('chess'), false);
check('legacy games ttt', Switches::on('ttt'), false);
check('legacy games c4', Switches::on('c4'), false);
check('legacy games rps', Switches::on('rps'), false);
check('legacy games math', Switches::on('math'), false);
check('legacy keeps others', [Switches::on('qr'), Switches::on('ai')], [false, true]);
Switches::setAll(true);
check('switch persisted on', Switches::on('qr'), true);
Switches::toggle('qr');
check('switch persists across reload', Switches::on('qr'), false);
Switches::setAll(true);
foreach (Switches::COMMAND_SWITCH as $cmd => $sw) {
    check("command switch {$cmd}", isset(Switches::TOOLS[$sw]), true);
}
foreach (Intent::NAMES as $n) {
    check("intent switch {$n}", isset(Switches::TOOLS[$n]), true);
}
foreach (array_keys((new ReflectionClass('CallbackHandler'))->getConstant('GAMES')) as $g) {
    check("callback game switch {$g}", isset(Switches::TOOLS[$g]), true);
}
foreach (['private', 'group', 'admin'] as $scope) {
    $names = array_column(Ui::commands($scope), 'command');
    check("commands unique {$scope}", count($names), count(array_unique($names)));
    check("commands described {$scope}", array_filter(Ui::commands($scope), static fn($c) => $c['description'] === '' || !preg_match('/^[a-z_]{1,32}$/', $c['command'])), []);
}
check('commands admin has tools', in_array('tools', array_column(Ui::commands('admin'), 'command'), true), true);
check('commands private no tools', in_array('tools', array_column(Ui::commands('private'), 'command'), true), false);
foreach (['tr' => 'translate', 'ft' => 'font', 'gw' => 'giveaway', 'wk' => 'wiki'] as $cmd => $sw) {
    Switches::toggle($sw);
    check("commands hide {$cmd}", in_array($cmd, array_column(Ui::commands('admin'), 'command'), true), false);
    Switches::toggle($sw);
    check("commands show {$cmd}", in_array($cmd, array_column(Ui::commands('admin'), 'command'), true), true);
}

check('h escapes', h("<a href='x'>&"), '&lt;a href=&#039;x&#039;&gt;&amp;');
check('h null', h(null), '');
check('fa digits', fa('2026/10/27'), '۲۰۲۶/۱۰/۲۷');
check('en digits', en('۱۲۳٤٥'), '12345');
check('fa en roundtrip', en(fa('9876543210')), '9876543210');
check('btn default style', btn('a', 'b'), ['text' => 'a', 'callback_data' => 'b', 'style' => 'primary']);
check('btn no style', btn('a', 'b', null), ['text' => 'a', 'callback_data' => 'b']);
check('urlBtn', urlBtn('a', 'https://x.y', 'success'), ['text' => 'a', 'url' => 'https://x.y', 'style' => 'success']);
check('ikb drops empty rows', ikb([[btn('a', 'b')], [], [btn('c', 'd')]])['inline_keyboard'] === [[btn('a', 'b')], [btn('c', 'd')]], true);
check('userName both', userName(['first_name' => 'علی', 'last_name' => 'رضایی']), 'علی رضایی');
check('userName first only', userName(['first_name' => 'Ali']), 'Ali');
check('userName fallback', userName([]), 'کاربر');
check('safeName escapes', safeName(['first_name' => '<b>']), bidi('&lt;b&gt;'));
check('safeName max', safeName(['first_name' => 'abcdef'], 3), bidi('abc'));
check('userTag id', userTag(5, 'Ali'), '<a href="tg://user?id=5">' . bidi('Ali') . '</a>');
check('userTag username only', userTag(null, '', 'bob'), '@bob');
check('userTag name only', userTag(null, 'Ali'), bidi('Ali'));
check('env default', env('NULLINI_NO_SUCH_ENV', 'dflt'), 'dflt');
putenv('NULLINI_TEST_LIST=a, b  c,,d');
check('envList split', envList('NULLINI_TEST_LIST'), ['a', 'b', 'c', 'd']);
check('envList default', envList('NULLINI_NO_SUCH_LIST', ['x']), ['x']);
putenv('NULLINI_TEST_LIST');

check('premium packs', App::cfg('premium_packs'), ['RestrictedEmoji', 'NewsEmoji']);

check('ban admin config', is_array(App::cfg('admins', [])), true);
$uid = 987654321;
check('ban initially not', Ban::is($uid), false);
check('ban add', Ban::add($uid, 1), true);
check('ban is', Ban::is($uid), true);
check('ban all lists', in_array($uid, Ban::all(), true), true);
check('ban add invalid id', Ban::add(0, 1), false);
check('ban add negative', Ban::add(-5, 1), false);
check('ban is invalid id', Ban::is(0), false);
check('ban remove', Ban::remove($uid), true);
check('ban removed', Ban::is($uid), false);
check('ban remove twice', Ban::remove($uid), false);
check('ban username invalid', Ban::byUsername('a'), 0);
check('ban username unknown', Ban::byUsername('@definitely_not_a_user_xyz'), 0);
check('ban sender message', Ban::senderId(['message' => ['from' => ['id' => 11]]]), 11);
check('ban sender inline', Ban::senderId(['inline_query' => ['from' => ['id' => 12]]]), 12);
check('ban sender chosen', Ban::senderId(['chosen_inline_result' => ['from' => ['id' => 13]]]), 13);
check('ban sender callback', Ban::senderId(['callback_query' => ['from' => ['id' => 14]]]), 14);
check('ban sender guest caller', Ban::senderId(['guest_message' => ['guest_bot_caller_user' => ['id' => 15], 'from' => ['id' => 99]]]), 15);
check('ban sender guest from', Ban::senderId(['guest_message' => ['from' => ['id' => 16]]]), 16);
check('ban sender unknown', Ban::senderId(['poll' => []]), 0);

check('nid valid sample', Tools::isValidNid('0499370899'), true);
check('nid bad check digit', Tools::isValidNid('0499370898'), false);
check('nid all same', Tools::isValidNid('1111111111'), false);
check('nid short', Tools::isValidNid('123'), false);
check('nid letters', Tools::isValidNid('04993708ab'), false);
check('nid normalize pad', Tools::normalizeNid('12345678'), '0012345678');
check('nid normalize persian', Tools::normalizeNid('۰۴۹۹۳۷۰۸۹۹'), '0499370899');
check('nid normalize dashes', Tools::normalizeNid('049-937-0899'), '0499370899');
check('nid normalize too short', Tools::normalizeNid('123'), null);
check('nid normalize too long', Tools::normalizeNid('12345678901'), null);
check('nid display', Tools::nidDisplay('0012345678'), "\u{2066}001-234567-8\u{2069}");
check('nid text ok', str_contains(Tools::nidText('0499370899'), 'درسته'), true);
check('nid text bad', str_contains(Tools::nidText('0499370898'), 'اشتباهه'), true);
check('qr url host', str_starts_with(Tools::qrUrl('hello'), 'https://api.qrserver.com/v1/create-qr-code/?'), true);
check('qr url data', str_contains(Tools::qrUrl('hello'), 'data=hello'), true);
check('qr url size', str_contains(Tools::qrUrl('hello', 256), 'size=256x256'), true);
check('qr url encodes', str_contains(Tools::qrUrl('a&b=c'), 'data=a%26b%3Dc'), true);
check('coin result', (bool) preg_match('/شیر|خط/u', Tools::coinResult()), true);
check('coin keyboard', Tools::coinKeyboard()['inline_keyboard'][0][0]['callback_data'], 'coin:flip');
check('hafez url', (bool) preg_match('#^https://api\.codebazan\.ir/fal/hafez/(\d{1,3})\.gif$#', Tools::hafezUrl(), $hm) && (int) $hm[1] >= 1 && (int) $hm[1] <= 149, true);
check('password lengths', [strlen(Tools::password(8)), strlen(Tools::password(24))], [8, 24]);
check('password clamp', [strlen(Tools::password(1)), strlen(Tools::password(500))], [6, 64]);
check('password differs', Tools::password(16) !== Tools::password(16), true);
check('date text nonempty', Tools::dateText() !== '', true);

check('wiki lang fa', Wiki::lang('سلام'), 'fa');
check('wiki lang en', Wiki::lang('hello'), 'en');
check('age tiny', UserInfo::ageText(time() - 5), 'کمتر از یک ماه');
check('age one month', UserInfo::ageText(time() - 45 * 86400), '۱ ماه');
check('age one year', UserInfo::ageText(time() - 370 * 86400), '۱ سال');
check('age year and month', UserInfo::ageText(time() - 400 * 86400), '۱ سال و ۱ ماه');
check('dc bad fileid', UserInfo::dcFromFileId('***'), null);

check('ttt row win', TicTacToe::winner(['X', 'X', 'X', '', '', '', '', '', '']), 'X');
check('ttt col win', TicTacToe::winner(['O', '', '', 'O', 'X', '', 'O', '', 'X']), 'O');
check('ttt diag win', TicTacToe::winner(['X', 'O', '', 'O', 'X', '', '', '', 'X']), 'X');
check('ttt anti diag win', TicTacToe::winner(['', '', 'O', 'X', 'O', '', 'O', 'X', '']), 'O');
check('ttt draw', TicTacToe::winner(['X', 'O', 'X', 'X', 'O', 'O', 'O', 'X', 'X']), 'draw');
check('ttt ongoing', TicTacToe::winner(['X', 'O', '', '', '', '', '', '', '']), null);
check('ttt empty', TicTacToe::winner(array_fill(0, 9, '')), null);
foreach (['TicTacToe', 'ConnectFour', 'Rps', 'MathDuel'] as $cls) {
    check("{$cls} intro", $cls::intro() !== '', true);
    check("{$cls} mode keyboard", count($cls::modeKeyboard()['inline_keyboard']) > 0, true);
}
check('gamekit player', GameKit::player(['id' => '7', 'first_name' => 'Ali']), ['id' => 7, 'name' => 'Ali']);
check('gamekit name null', GameKit::name(null), 'حریف');
check('gamekit name escapes', GameKit::name(['name' => '<b>']), bidi('&lt;b&gt;'));
$gk = GameKit::fresh('ttt', GameKit::player(['id' => 1, 'first_name' => 'A']), ['mode' => 'pvp']);
check('gamekit fresh', [$gk['game'], $gk['status'], $gk['p2'], $gk['mode']], ['ttt', 'mode', null, 'pvp']);
check('gamekit isPlayer', [GameKit::isPlayer($gk, 1), GameKit::isPlayer($gk, 2)], [true, false]);
check('gamekit isCreator', [GameKit::isCreator($gk, 1), GameKit::isCreator($gk, 2)], [true, false]);
check('gamekit seat creator', GameKit::seat($gk, ['id' => 1], 1), 1);
check('gamekit seat early joiner', GameKit::seat($gk, ['id' => 2, 'first_name' => 'B'], 1), 0);
check('gamekit seat joins', GameKit::seat($gk, ['id' => 2, 'first_name' => 'B'], 2), 2);
check('gamekit p2 saved', $gk['p2']['id'] ?? null, 2);
check('gamekit seat third wheel', GameKit::seat($gk, ['id' => 3, 'first_name' => 'C'], 2), 0);
check('gamekit seat second again', GameKit::seat($gk, ['id' => 2], 1), 2);
GameKit::save('smoke_imid', $gk);
check('gamekit save/load', GameKit::load('smoke_imid')['p2']['id'] ?? null, 2);
check('gamekit load missing', GameKit::load('smoke_missing'), null);
check('gamekit ensure keeps same game', GameKit::ensure('smoke_imid', 'ttt', ['id' => 9])['p1']['id'], 1);
check('gamekit ensure new game', GameKit::ensure('smoke_imid', 'c4', ['id' => 9, 'first_name' => 'Z'])['p1']['id'], 9);
Store::delete(GameKit::NS, 'smoke_imid');

@unlink($db);
@unlink($db . '-wal');
@unlink($db . '-shm');

echo $failed ? "tests: {$failed} failed, {$passed} passed\n" : "tests: all {$passed} passed\n";
exit($failed ? 1 : 0);
