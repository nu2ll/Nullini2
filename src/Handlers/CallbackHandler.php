<?php

final class CallbackHandler
{
    private const GAMES = [
        'rps'   => [Rps::class, 'onCallback'],
        'ttt'   => [TicTacToe::class, 'onCallback'],
        'c4'    => [ConnectFour::class, 'onCallback'],
        'math'  => [MathDuel::class, 'onCallback'],
        'chess' => [Chess::class, 'onCallback'],
    ];

    public static function handle(array $cq): void
    {
        $tg   = App::tg();
        $data = (string) ($cq['data'] ?? '');
        $imid = $cq['inline_message_id'] ?? null;
        $uid  = (int) ($cq['from']['id'] ?? 0);

        if ($data === 'noop' || $data === 'love:noop' || $data === 'info:label') {
            $tg->answer($cq);
            return;
        }

        if (str_starts_with($data, 'dl:')) {
            Downloader::onCallback($cq, $data);
            return;
        }
        if (str_starts_with($data, 'gw:')) {
            Giveaway::onCallback($cq, $data);
            return;
        }
        if (str_starts_with($data, 'ai:')) {
            AiPanel::onCallback($cq, $data);
            return;
        }
        if (str_starts_with($data, 'tl:')) {
            Switches::onCallback($cq, $data);
            return;
        }

        if (!$imid) {
            self::privateButtons($cq, $data);
            return;
        }

        $prefix = strstr($data, ':', true) ?: $data;
        if (isset(self::GAMES[$prefix])) {
            if (!Switches::on($prefix)) {
                $tg->answer($cq);
                return;
            }
            call_user_func(self::GAMES[$prefix], $cq, $data, $imid);
            return;
        }

        if (preg_match('/^(ai|tr)q:(\w+)$/', $data, $m)) {
            $tg->answer($cq, '⏳ دارم می‌نویسم...');
            InlineHandler::resolveDeferred($m[2], $imid);
            return;
        }
        if (str_starts_with($data, 'wh:')) {
            Whisper::onCallback($cq, $data, $imid);
            return;
        }
        if (str_starts_with($data, 'rates:')) {
            Currency::onCallback($cq, $data, $imid);
            return;
        }
        if (str_starts_with($data, 'weather:refresh')) {
            Weather::onCallback($cq, $imid, $data);
            return;
        }

        $owner = Store::get('owner', $imid);
        if ($owner === null) {
            Store::set('owner', $imid, $uid);
            $owner = $uid;
        }
        if ((int) $owner !== $uid) {
            $tg->alert($cq, '🔒 این دکمه مال کسیه که این پیام رو فرستاده؛' . "\n" . 'خودت از منوی نالینی یکی بساز!');
            return;
        }

        if (str_starts_with($data, 'calc:')) {
            $expr = (string) Store::get('calc', $imid, '0');
            $key  = substr($data, 5);
            $new  = Calculator::press($expr, $key);
            if ($new === $expr && $key !== '=') {
                $tg->answer($cq);
                return;
            }
            Store::set('calc', $imid, $new);
            $tg->editInline($imid, Calculator::render($new, $key === '=' && $new !== Calculator::ERROR), Calculator::keyboard());
            $tg->answer($cq);
            return;
        }

        if (str_starts_with($data, 'pass:')) {
            $tg->editInline($imid, Tools::passwordText((int) substr($data, 5)), Tools::passwordKeyboard());
            $tg->answer($cq, '🔑 پسورد جدید ساخته شد');
            return;
        }

        switch ($data) {
            case 'coin:flip':
                $tg->editInline($imid, Tools::coinResult(), ikb([[btn('🔁 دوباره بنداز', 'coin:flip')]]));
                $tg->answer($cq);
                return;
            case 'fal:refresh':
                Tools::showHafez($imid);
                $tg->answer($cq, '🔮 یه فال تازه');
                return;
        }
        $tg->answer($cq);
    }

    private static function privateButtons(array $cq, string $data): void
    {
        $tg     = App::tg();
        $chatId = $cq['message']['chat']['id'] ?? null;
        $msgId  = $cq['message']['message_id'] ?? null;
        if ($chatId === null || $msgId === null) {
            $tg->answer($cq);
            return;
        }

        if ($data === 'joincheck') {
            Store::delete('join', (string) $cq['from']['id']);
            $missing = Membership::missing((int) $cq['from']['id']);
            if ($missing) {
                $tg->alert($cq, '⏳ هنوز عضو نشدی! اول عضو شو بعد دکمه رو بزن.');
                return;
            }
            $tg->editMessage($chatId, $msgId, Ui::startText($cq['from']), Ui::startKeyboard());
            $tg->answer($cq, '✅ خوش اومدی!');
            return;
        }

        if ($data === 'home') {
            $tg->editMessage($chatId, $msgId, Ui::startText($cq['from']), Ui::startKeyboard());
        } elseif ($data === 'about') {
            $tg->editMessage($chatId, $msgId, Ui::aboutText(), Ui::aboutKeyboard(true));
        } elseif ($data === 'help:menu' || $data === 'help:p1' || $data === 'help:p2') {
            $page = $data === 'help:p2' ? 2 : 1;
            $tg->editMessage($chatId, $msgId, Ui::helpText($page), Ui::helpMenu(($cq['message']['chat']['type'] ?? '') === 'private', $page));
        } elseif (str_starts_with($data, 'help:') && isset(Ui::GUIDES[substr($data, 5)])) {
            $tg->editMessage($chatId, $msgId, Ui::guide(substr($data, 5)), Ui::guideKeyboard(substr($data, 5), ($cq['message']['chat']['type'] ?? '') === 'private'));
        }
        $tg->answer($cq);
    }
}
