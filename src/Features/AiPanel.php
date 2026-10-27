<?php

final class AiPanel
{
    private const COLS     = ['gemini' => 2];
    private const PER_PAGE = ['gemini' => 12];
    private const LEVELS   = ['auto' => 'خودکار', 'none' => 'بدون تفکر', 'minimal' => 'حداقل', 'low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];

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
            . "مدل فعال روی همه‌ی بخش‌ها:\n<b>" . bidi(h(self::label($cur))) . "</b>"
            . "\n\n🔎 سرچ وب: <b>" . (AI::searchOn() ? 'روشن' : 'خاموش') . '</b>'
            . "\n🧠 سطح تفکر: <b>" . self::thinkSummary() . '</b>';
    }

    private static function thinkSummary(): string
    {
        $l = [];
        foreach (self::providers() as $id => $name) {
            if (isset(AI::THINK_LEVELS[$id])) {
                $l[] = h($name) . ' ' . self::LEVELS[AI::thinkLevel($id)];
            }
        }
        return implode(' · ', $l);
    }

    public static function thinkText(): string
    {
        $l = ['<b>🧠 سطح تفکر</b>'];
        foreach (self::providers() as $id => $name) {
            if (isset(AI::THINK_LEVELS[$id])) {
                $l[] = h($name) . ': <b>' . self::LEVELS[AI::thinkLevel($id)] . '</b>';
            }
        }
        $l[] = '⚡ <b>خودکار</b>: تنظیم پیش‌فرض خودِ ربات';
        $l[] = 'اگه مدلِ فعال اون سطح رو نداشته باشه، نزدیک‌ترین سطحِ پشتیبانی‌شده‌ی خودش اعمال می‌شه (مثلاً gpt-oss «بدون تفکر» نداره و می‌شه «کم»؛ gemma و Gemini 2.5 اصلاً سطح ندارن).';
        $l[] = 'فقط روی جواب‌های چت اعمال می‌شه؛ ترجمه، روست و بقیه همون سریع می‌مونن.';
        return implode("\n\n", $l);
    }

    public static function thinkKeyboard(): array
    {
        $rows = [];
        foreach (self::providers() as $id => $name) {
            if (!isset(AI::THINK_LEVELS[$id])) {
                continue;
            }
            $rows[] = [btn($name, 'ai:think', null)];
            $now    = AI::thinkLevel($id);
            $btns   = [];
            foreach (array_merge(['auto'], AI::THINK_LEVELS[$id]) as $lv) {
                $btns[] = btn(($now === $lv ? '✅ ' : '') . self::LEVELS[$lv], "ai:t:{$id}:{$lv}", $now === $lv ? 'success' : 'primary');
            }
            foreach (array_chunk($btns, 3) as $chunk) {
                $rows[] = $chunk;
            }
        }
        $rows[] = [btn('🔙 برگشت', 'ai:home', null)];
        return ikb($rows);
    }

    public static function keyboard(): array
    {
        $cur  = explode(':', AI::route(), 2)[0];
        $rows = [];
        foreach (self::providers() as $id => $name) {
            $on     = $cur === $id;
            $rows[] = [btn(($on ? '✅ ' : '') . $name . ' ▸', "ai:p:{$id}:0", $on ? 'success' : 'primary')];
        }
        $rows[] = [btn(AI::searchOn() ? '🔎 سرچ: روشن ✅' : '🔎 سرچ: خاموش ⛔', 'ai:search', AI::searchOn() ? 'success' : null)];
        $rows[] = [btn('🧠 سطح تفکر ▸', 'ai:think')];
        return ikb($rows);
    }

    public static function providerText(string $prov, int $page = 0): string
    {
        $all   = self::catalog($prov);
        $per   = self::PER_PAGE[$prov] ?? 8;
        $pages = max(1, (int) ceil(count($all) / $per));
        $page  = max(0, min($page, $pages - 1));

        $l   = [];
        $l[] = '<b>' . h(self::providers()[$prov] ?? $prov) . '</b>' . ($pages > 1 ? ' · <i>صفحه‌ی ' . fa($page + 1) . ' از ' . fa($pages) . '</i>' : '');
        $l[] = 'مدل فعال روی همه‌ی بخش‌ها: <b>' . bidi(h(self::label(AI::route()))) . '</b>';
        $l[] = '⚡ <b>خودکار</b>: مدل‌ها به ترتیب امتحان می‌شن' . "\n" . 'و اگه سهمیه‌ی یکی تموم شد، خودش می‌ره سراغ بعدی.';
        $l[] = '📌 <b>انتخاب یه مدل</b>: فقط همون مدل،' . "\n" . 'روی همه‌ی بخش‌ها، ثابت می‌مونه (تا خودت عوضش کنی).';
        if (!$all) {
            $l[] = 'الان لیست مدل‌ها در دسترس نیست؛ چند دقیقه دیگه دوباره امتحان کن.';
        }
        return implode("\n\n", $l);
    }

    public static function providerKeyboard(string $prov, int $page = 0): array
    {
        $all   = self::catalog($prov);
        $per   = self::PER_PAGE[$prov] ?? 8;
        $cols  = self::COLS[$prov] ?? 1;
        $pages = max(1, (int) ceil(count($all) / $per));
        $page  = max(0, min($page, $pages - 1));
        $cur   = AI::route();

        $auto = $cur === $prov;
        $rows = [[btn(($auto ? '✅ ' : '') . '⚡ خودکار', "ai:set:{$prov}:a:{$page}", $auto ? 'success' : 'primary')]];

        $row = [];
        foreach (array_slice($all, $page * $per, $per) as $m) {
            $on    = $m['route'] === $cur;
            $row[] = btn(($on ? '✅ ' : '') . mb_strimwidth($m['name'], 0, $cols > 1 ? 18 : 40, '…'), "ai:set:{$prov}:" . substr(md5($m['route']), 0, 8) . ":{$page}", $on ? 'success' : 'primary');
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
                $nav[] = Ui::navButton(false, "ai:p:{$prov}:" . ($page - 1));
            }
            if ($page < $pages - 1) {
                $nav[] = Ui::navButton(true, "ai:p:{$prov}:" . ($page + 1));
            }
            $rows[] = $nav;
        }
        $rows[] = [btn('🔙 برگشت', 'ai:home', null)];
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

        if ($act === 'search') {
            AI::setSearch(!AI::searchOn());
            $saved = true;
        }

        if ($act === 't' && $known) {
            AI::setThink($prov, $p[3] ?? '');
            $saved = true;
        }

        if ($known && $act === 'p') {
            $page = (int) ($p[3] ?? 0);
        } elseif ($known && $act === 'set') {
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
                AI::setRoute($route);
                $saved = true;
            }
        }

        if ($act === 'think' || $act === 't') {
            $tg->editMessage($chatId, $msgId, self::thinkText(), self::thinkKeyboard());
        } elseif ($known && ($act === 'p' || $act === 'set')) {
            $tg->editMessage($chatId, $msgId, self::providerText($prov, $page), self::providerKeyboard($prov, $page));
        } else {
            $tg->editMessage($chatId, $msgId, self::text(), self::keyboard());
        }
        $tg->answer($cq, $saved ? '✅ ذخیره شد' : null);
    }
}
