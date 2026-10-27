<?php

final class MessageHandler
{
    private const TRIGGER = 'نالینی';

    public static function handle(array $msg): void
    {
        if (!empty($msg['via_bot'])) {
            return;
        }

        $from     = $msg['from'] ?? [];
        $userId   = (int) ($from['id'] ?? 0);
        $chatType = (string) ($msg['chat']['type'] ?? '');
        $text     = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        $private  = $chatType === 'private';
        $group    = in_array($chatType, ['group', 'supergroup'], true);

        if (!$userId || (!$private && !$group)) {
            return;
        }

        if ($private) {
            Users::touch($from, str_starts_with($text, '/start'));
        } else {
            Roast::see($from);
        }
        if (isset($msg['reply_to_message']['from'])) {
            Roast::see($msg['reply_to_message']['from']);
        }

        if ($text !== '' && $text[0] === '/' && self::command($msg, $text, $private)) {
            return;
        }

        if ($private && Giveaway::input($msg)) {
            return;
        }

        $hasMedia = Assistant::hasMedia($msg);
        if ($text === '' && !$hasMedia) {
            return;
        }

        $startsWithName = preg_match('/^' . self::TRIGGER . '(?:[\s،,:!؟?.]|$)/u', $text) === 1;
        $mentioned      = self::mentionsBot($text);
        $link = null;
        if ($text !== '' && empty($msg['is_automatic_forward']) && ($private || Downloader::groupsEnabled() || $mentioned || $startsWithName)) {
            $bare = preg_replace('/@' . preg_quote(App::botUsername(), '/') . '\b/iu', '', $text) ?? $text;
            $bare = preg_replace('/^' . self::TRIGGER . '[\s،,:]*/u', '', mbTrim($bare)) ?? $bare;
            $link = Downloader::match($bare);
        }
        // ریپلای روی پیام ابزارها (دانلودر، تاس، هوا، روست و...) به هوش مصنوعی نره؛ مگه اسم/منشنِ ربات توش باشه
        $toolReply = self::isReplyToBot($msg)
            && !AI::isAiMsg($msg['chat']['id'], (int) ($msg['reply_to_message']['message_id'] ?? 0))
            && !$mentioned && !$startsWithName;
        if ($link !== null && !Switches::on('download')) {
            return;
        }
        $shouldRespond  = $link !== null
            || (!$toolReply && (
                $private
                || ($group && !empty($msg['is_automatic_forward']))
                || ($group && self::isReplyToBot($msg))
                || ($group && $mentioned)
                || ($group && is_array($msg['dice'] ?? null))
                || ($group && mb_strpos($text, self::TRIGGER) !== false)
            ));

        if (!$shouldRespond) {
            return;
        }

        if ($private) {
            $missing = Membership::missing($userId);
            if ($missing) {
                App::tg()->send($msg['chat']['id'], Membership::text(), ['reply_markup' => Membership::keyboard($missing)]);
                return;
            }
        }

        if ($link !== null) {
            Downloader::offer($msg, $link);
            return;
        }

        if (is_array($msg['dice'] ?? null)) {
            App::tg()->reply($msg, Ui::diceHello((string) ($msg['dice']['emoji'] ?? '🎲'), (int) ($msg['dice']['value'] ?? 0)));
            return;
        }

        $question = $text;
        if ($mentioned) {
            $question = mbTrim(preg_replace('/@' . preg_quote(App::botUsername(), '/') . '\b/iu', '', $question) ?? $question, " \t\n،,:");
        }
        if ($startsWithName) {
            $question = mbTrim(mb_substr($question, mb_strlen(self::TRIGGER)), " \t\n،,:");
        }

        if ($group && $question === '' && !$hasMedia && !isset($msg['reply_to_message']) && ($mentioned || $startsWithName)) {
            App::tg()->reply($msg, Ui::hello(), ['reply_markup' => Ui::groupKeyboard()]);
            return;
        }

        if ($question === '' && !$hasMedia) {
            $question = $text;
        }
        Assistant::handle($msg, $question);
    }

    private static function mentionsBot(string $text): bool
    {
        $bot = App::botUsername();
        return $bot !== '' && preg_match('/@' . preg_quote($bot, '/') . '\b/iu', $text) === 1;
    }

    private static function isReplyToBot(array $msg): bool
    {
        $r = $msg['reply_to_message']['from'] ?? null;
        return $r && !empty($r['is_bot']) && strcasecmp((string) ($r['username'] ?? ''), App::botUsername()) === 0;
    }

    private static function command(array $msg, string $text, bool $private): bool
    {
        $tg     = App::tg();
        $chatId = $msg['chat']['id'];
        $from   = $msg['from'];

        if (!preg_match('/^\/([a-zA-Z_]+)(?:@(\w+))?(?:\s+(.*))?$/su', $text, $m)) {
            return false;
        }
        $cmd  = strtolower($m[1]);
        $to   = $m[2] ?? '';
        $args = trim($m[3] ?? '');
        if ($to !== '' && strcasecmp($to, App::botUsername()) !== 0) {
            return true;
        }
        Users::hit("cmd:{$cmd}");

        $gate = ['wk' => 'wiki', 'wiki' => 'wiki', 'giveaway' => 'giveaway', 'gw' => 'giveaway', 'image' => 'image', 'img' => 'image', 'font' => 'font', 'ft' => 'font', 'tr' => 'translate', 'translate' => 'translate'][$cmd] ?? null;
        if ($gate !== null && !Switches::guard($gate)) {
            return true;
        }

        switch ($cmd) {
            case 'start':
                if ($private) {
                    $missing = Membership::missing((int) $from['id']);
                    if ($missing) {
                        $tg->send($chatId, Membership::text(), ['reply_markup' => Membership::keyboard($missing)]);
                        return true;
                    }
                }
                if (str_starts_with($args, 'gw_') || $args === 'giveaway') {
                    if ($private) {
                        Giveaway::start($msg, $args);
                    }
                    return true;
                }
                if ($private) {
                    Giveaway::clear((int) $from['id']);
                }
                if ($args === 'help') {
                    $tg->send($chatId, Ui::helpText(), ['reply_markup' => Ui::helpMenu($private)]);
                    return true;
                }
                if (!$private) {
                    $tg->send($chatId, Ui::groupText(), ['reply_markup' => Ui::groupKeyboard()]);
                    return true;
                }
                $tg->send($chatId, Ui::startText($from), ['reply_markup' => Ui::startKeyboard()]);
                return true;

            case 'help':
                $tg->send($chatId, Ui::helpText(), ['reply_markup' => Ui::helpMenu($private)]);
                return true;

            case 'wk':
            case 'wiki':
                $args === '' ? $tg->reply($msg, Ui::guide('wiki')) : Wiki::reply($msg, $args);
                return true;

            case 'giveaway':
            case 'gw':
                $private ? Giveaway::start($msg) : Giveaway::fromGroup($msg);
                return true;

            case 'image':
            case 'img':
                if ($args === '') {
                    $tg->reply($msg, Ui::guide('image'));
                } else {
                    ImageGen::handle($msg, $args);
                }
                return true;

            case 'font':
            case 'ft':
                $tg->reply($msg, $args === '' ? Ui::guide('font') : Fonts::listText($args));
                return true;

            case 'tr':
            case 'translate':
                Assistant::translate($msg, $args);
                return true;

            case 'stats':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                $u   = Users::summary();
                $n   = static fn($v) => fa(number_format((int) $v));
                $top = '';
                foreach (Users::topEvents(1, 8) as $e) {
                    $top .= "\n• <code>" . h($e['event']) . '</code>: ' . $n($e['hits']);
                }
                $tg->reply($msg, "📊 <b>آمار نالینی</b>\n\n"
                    . '👥 کل کاربرها: ' . $n($u['total'] ?? 0) . "\n"
                    . '🆕 جدید امروز: ' . $n($u['new_today'] ?? 0) . "\n"
                    . '🔥 فعال ۲۴ ساعت: ' . $n($u['day'] ?? 0) . ' · ۷ روز: ' . $n($u['week'] ?? 0) . "\n"
                    . '📬 قابل پیام: ' . $n($u['reachable'] ?? 0) . ' · 💎 پریمیوم: ' . $n($u['premium'] ?? 0) . "\n"
                    . '🎮 بازی‌های ذخیره‌شده: ' . $n(Store::count('games'))
                    . ($top !== '' ? "\n\n<b>⭐ پرکاربردهای امروز:</b>{$top}" : '')
                    . "\n\n🗄 دیتابیس: <code>" . (Db::driver() === 'sqlite' ? 'Nullini.db' : 'PostgreSQL') . '</code>'
                    . "\n💾 آخرین بکاپ: " . (($at = Backup::lastPushAt()) ? Jalali::numericDate($at) . ' ' . Jalali::time($at) : (Backup::enabled() ? 'هنوز نه' : 'خاموش')));
                return true;

            case 'ai':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                $tg->reply($msg, AiPanel::text(), ['reply_markup' => AiPanel::keyboard()]);
                return true;

            case 'quota':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                $tg->reply($msg, AI::quotaReport());
                return true;

            case 'backup':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                $r = Backup::push('manual');
                $tg->reply($msg, str_starts_with($r, 'ok') ? '✅ بکاپ تو گیت‌هاب ذخیره شد.' : "❌ بکاپ نشد.\n<code>" . h($r) . '</code>');
                return true;

            case 'tools':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                $tg->reply($msg, Switches::text(), ['reply_markup' => Switches::keyboard()]);
                return true;

            case 'ban':
            case 'unban':
            case 'banned':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                if ($cmd === 'banned') {
                    $ids = Ban::all();
                    $tg->reply($msg, $ids
                        ? '⛔ <b>بن‌شده‌ها (' . fa(count($ids)) . "):</b>\n" . implode("\n", array_map(static fn($i) => '• <code>' . $i . '</code>', array_slice($ids, 0, 50)))
                        : 'هیچ‌کس بن نیست 👌');
                    return true;
                }
                $r   = $msg['reply_to_message']['from'] ?? null;
                if (ctype_digit($args)) {
                    $tid = (int) $args;
                } elseif ($args !== '' && $args[0] === '@') {
                    $tid = Ban::byUsername($args);
                    if ($tid <= 0) {
                        $tg->reply($msg, 'این یوزرنیم پیدا نشد 😕' . "\n" . 'فقط کسایی که ربات رو استارت کرده باشن (یا تو گروه دیده شده باشن) پیدا می‌شن؛ با ریپلای یا آیدی عددی امتحان کن.');
                        return true;
                    }
                } else {
                    $tid = ($r && empty($r['is_bot'])) ? (int) $r['id'] : 0;
                }
                if ($tid <= 0) {
                    $tg->reply($msg, "روی پیام کاربر ریپلای کن و /{$cmd} بزن،\nیا آیدی عددی یا یوزرنیمش رو بنویس:\n<code>/{$cmd} 123456</code>\n<code>/{$cmd} @username</code>");
                    return true;
                }
                if ($cmd === 'ban') {
                    $tg->reply($msg, Ban::add($tid, (int) $from['id']) ? "⛔ کاربر <code>{$tid}</code> بن شد." : '🙅 ادمین رو نمی‌شه بن کرد.');
                } else {
                    $tg->reply($msg, Ban::remove($tid) ? "✅ کاربر <code>{$tid}</code> آنبن شد." : 'این کاربر بن نبود.');
                }
                return true;

            case 'broadcast':
                if (!App::isAdmin((int) $from['id'])) {
                    return false;
                }
                $src = $msg['reply_to_message'] ?? null;
                if (!$src) {
                    $tg->reply($msg, "روی پیامی که می‌خوای برای همه بره ریپلای کن\nو /broadcast بزن.");
                    return true;
                }
                $st   = $tg->reply($msg, '📣 ارسال شروع شد...');
                $stId = (int) ($st['result']['message_id'] ?? 0);
                $ok   = 0;
                $fail = 0;
                Users::eachReachable(static function (int $id) use ($tg, $chatId, $src, &$ok, &$fail) {
                    $r = $tg->call('copyMessage', ['chat_id' => $id, 'from_chat_id' => $chatId, 'message_id' => $src['message_id']], 10);
                    if (!empty($r['ok'])) {
                        $ok++;
                    } else {
                        $fail++;
                        if ((int) ($r['error_code'] ?? 0) === 403) {
                            Users::markBlocked($id);
                        }
                    }
                    usleep(40000);
                });
                $done = '✅ تموم شد. موفق: ' . fa($ok) . ' · ناموفق: ' . fa($fail);
                $stId ? $tg->editMessage($chatId, $stId, $done) : $tg->reply($msg, $done);
                return true;
        }
        return false;
    }

    public static function onMyChatMember(array $u): void
    {
        $new  = $u['new_chat_member']['status'] ?? '';
        $old  = $u['old_chat_member']['status'] ?? '';
        $type = $u['chat']['type'] ?? '';
        if (!in_array($type, ['group', 'supergroup'], true)) {
            return;
        }
        if (in_array($new, ['member', 'administrator'], true) && in_array($old, ['left', 'kicked'], true)) {
            App::tg()->send($u['chat']['id'], Ui::groupText(), ['reply_markup' => Ui::groupKeyboard()]);
        }
    }
}
