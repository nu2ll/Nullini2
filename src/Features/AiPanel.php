<?php

final class AiPanel
{
    private const COLS     = ['gemini' => 2];
    private const PER_PAGE = ['gemini' => 12];

    public static function providers(): array
    {
        return (AI::keys() ? ['gemini' => 'Gemini'] : []) + FreeAI::active();
    }

    public static function label(string $route): string
    {
        [$prov, $model] = explode(':', $route, 2) + [1 => ''];
        $name = $prov === 'gemini' ? 'Gemini' : (FreeAI::PROVIDERS[$prov]['name'] ?? $prov);
        if ($model === '') {
            return $name . ' · خودکار';
        }
        return $name . ' · ' . ($prov === 'gemini' ? AI::shortModel($model) : FreeAI::title($prov, $model));
    }

    private static function catalog(string $prov): array
    {
        $out = [];
        if ($prov === 'gemini') {
            foreach (AI::allModels() as $m) {
                $out[] = ['route' => 'gemini:' . $m, 'name' => AI::shortModel($m)];
            }
            return $out;
        }
        if (!isset(self::providers()[$prov])) {
            return [];
        }
        foreach (FreeAI::ordered($prov) as $id) {
            $out[] = ['route' => $prov . ':' . $id, 'name' => FreeAI::title($prov, $id)];
        }
        return $out;
    }

    public static function text(): string
    {
        $cur = AI::route();
        return "<b>🤖 پنل هوش مصنوعی</b>\n\n"
            . "مدل فعال روی همه‌ی بخش‌ها:\n<b>" . bidi(h(self::label($cur))) . "</b>\n\n"
            . "🧭 روتر (تشخیص ابزار از روی حرف): <b>" . (AI::routerOn() ? 'روشن' : 'خاموش') . "</b>";
    }

    public static function keyboard(): array
    {
        $cur  = explode(':', AI::route(), 2)[0];
        $rows = [];
        foreach (self::providers() as $id => $name) {
            $on     = $cur === $id;
            $rows[] = [btn(($on ? '✅ ' : '') . $name . ' ▸', "ai:p:{$id}:0", $on ? 'success' : 'primary')];
        }
        $rows[] = [btn('🧭 روتر ▸', 'ai:r', null)];
        return ikb($rows);
    }

    public static function routerText(): string
    {
        $set = AI::routerSetting();
        return "وضعیت: " . (AI::routerOn() ? 'روشن ✅' : 'خاموش ❌') . "\n"
            . "مدل روتر: ⁨<b>" . ($set === '' ? 'هم‌مدلِ چت' : bidi(h(self::label($set)))) . "</b>⁩"
            . "\n\n💡 روتر برای هر پیام یه تماس اضافه می‌زنه؛\nیه مدل سبک و سریع براش بهتره.";
    }

    public static function routerKeyboard(): array
    {
        $on   = AI::routerOn();
        $set  = AI::routerSetting();
        $cur  = $set === '' ? '' : explode(':', $set, 2)[0];
        $rows = [[btn($on ? 'خاموش' : 'روشن', 'ai:rt', $on ? 'danger' : 'success')]];
        $rows[] = [btn(($set === '' ? '✅ ' : '') . '🔗 هم‌مدلِ چت', 'ai:rset:-:c:0', $set === '' ? 'success' : 'primary')];
        foreach (self::providers() as $id => $name) {
            $is     = $cur === $id;
            $rows[] = [btn(($is ? '✅ ' : '') . $name . ' ▸', "ai:rp:{$id}:0", $is ? 'success' : 'primary')];
        }
        $rows[] = [btn('🔙 برگشت', 'ai:home', null)];
        return ikb($rows);
    }

    public static function providerText(string $prov, int $page = 0, bool $router = false): string
    {
        $all   = self::catalog($prov);
        $per   = self::PER_PAGE[$prov] ?? 8;
        $pages = max(1, (int) ceil(count($all) / $per));
        $page  = max(0, min($page, $pages - 1));

        $l   = [];
        $l[] = '<b>' . h(self::providers()[$prov] ?? $prov) . '</b>' . ($pages > 1 ? ' · <i>صفحه‌ی ' . fa($page + 1) . ' از ' . fa($pages) . '</i>' : '');
        $l[] = $router
            ? '🧭 مدل روتر: <b>' . (AI::routerSetting() === '' ? 'هم‌مدلِ چت' : bidi(h(self::label(AI::routerSetting())))) . '</b>'
            : 'مدل فعال روی همه‌ی بخش‌ها: <b>' . bidi(h(self::label(AI::route()))) . '</b>';
        $l[] = '⚡ <b>خودکار</b>: مدل‌ها به ترتیب امتحان می‌شن' . "\n" . 'و اگه سهمیه‌ی یکی تموم شد، خودش می‌ره سراغ بعدی.';
        $l[] = $router
            ? '📌 <b>انتخاب یه مدل</b>: فقط همون مدل کارِ روتر رو می‌کنه' . "\n" . '(چت و بقیه‌ی بخش‌ها دست نمی‌خورن).'
            : '📌 <b>انتخاب یه مدل</b>: فقط همون مدل،' . "\n" . 'روی همه‌ی بخش‌ها، ثابت می‌مونه (تا خودت عوضش کنی).';
        if (!$all) {
            $l[] = 'الان لیست مدل‌ها در دسترس نیست؛ چند دقیقه دیگه دوباره امتحان کن.';
        }
        return implode("\n\n", $l);
    }

    public static function providerKeyboard(string $prov, int $page = 0, bool $router = false): array
    {
        $all   = self::catalog($prov);
        $per   = self::PER_PAGE[$prov] ?? 8;
        $cols  = self::COLS[$prov] ?? 1;
        $pages = max(1, (int) ceil(count($all) / $per));
        $page  = max(0, min($page, $pages - 1));
        $cur   = $router ? AI::routerSetting() : AI::route();
        $pg    = $router ? "ai:rp:" : "ai:p:";
        $set   = $router ? "ai:rset:" : "ai:set:";

        $auto = $cur === $prov;
        $rows = [[btn(($auto ? '✅ ' : '') . '⚡ خودکار', "{$set}{$prov}:a:{$page}", $auto ? 'success' : 'primary')]];

        $row = [];
        foreach (array_slice($all, $page * $per, $per) as $m) {
            $on    = $m['route'] === $cur;
            $row[] = btn(($on ? '✅ ' : '') . mb_strimwidth($m['name'], 0, $cols > 1 ? 18 : 40, '…'), "{$set}{$prov}:" . substr(md5($m['route']), 0, 8) . ":{$page}", $on ? 'success' : 'primary');
            if (count($row) === $cols) {
                $rows[] = $row;
                $row    = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = Ui::navButton(false, "{$pg}{$prov}:" . ($page - 1));
            }
            if ($page < $pages - 1) {
                $nav[] = Ui::navButton(true, "{$pg}{$prov}:" . ($page + 1));
            }
            $rows[] = $nav;
        }
        $rows[] = [btn('🔙 برگشت', $router ? 'ai:r' : 'ai:home', null)];
        return ikb($rows);
    }

    public static function onCallback(array $cq, string $data): void
    {
        $tg = App::tg();
        if (!App::isAdmin((int) ($cq['from']['id'] ?? 0))) {
            $tg->alert($cq, '🙅 این پنل مخصوص ادمینه.');
            return;
        }
        $chatId = $cq['message']['chat']['id'] ?? null;
        $msgId  = $cq['message']['message_id'] ?? null;
        if ($chatId === null || $msgId === null) {
            $tg->answer($cq);
            return;
        }

        $p     = explode(':', $data);
        $act   = $p[1] ?? '';
        $prov  = $p[2] ?? '';
        $known = isset(self::providers()[$prov]);
        $page  = 0;
        $saved = false;

        if ($act === 'rt') {
            AI::setRouterOn(!AI::routerOn());
            $tg->editMessage($chatId, $msgId, self::routerText(), self::routerKeyboard());
            $tg->answer($cq, AI::routerOn() ? '✅ روتر روشن شد' : '⛔ روتر خاموش شد');
            return;
        }
        if ($act === 'r') {
            $tg->editMessage($chatId, $msgId, self::routerText(), self::routerKeyboard());
            $tg->answer($cq);
            return;
        }
        if ($act === 'rset' && ($p[3] ?? '') === 'c') {
            AI::setRouterRoute('');
            $tg->editMessage($chatId, $msgId, self::routerText(), self::routerKeyboard());
            $tg->answer($cq, '✅ ذخیره شد');
            return;
        }

        $router = in_array($act, ['rp', 'rset'], true);
        if ($known && ($act === 'p' || $act === 'rp')) {
            $page = (int) ($p[3] ?? 0);
        } elseif ($known && ($act === 'set' || $act === 'rset')) {
            $pick  = $p[3] ?? '';
            $page  = (int) ($p[4] ?? 0);
            $route = $pick === 'a' ? $prov : null;
            if ($route === null) {
                foreach (self::catalog($prov) as $m) {
                    if (substr(md5($m['route']), 0, 8) === $pick) {
                        $route = $m['route'];
                        break;
                    }
                }
            }
            if ($route !== null && AI::validRoute($route)) {
                $router ? AI::setRouterRoute($route) : AI::setRoute($route);
                $saved = true;
            }
        }

        if ($known && in_array($act, ['p', 'set', 'rp', 'rset'], true)) {
            $tg->editMessage($chatId, $msgId, self::providerText($prov, $page, $router), self::providerKeyboard($prov, $page, $router));
        } else {
            $tg->editMessage($chatId, $msgId, self::text(), self::keyboard());
        }
        $tg->answer($cq, $saved ? '✅ ذخیره شد' : null);
    }
}
