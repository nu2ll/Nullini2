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
            . "مدل فعال روی همه‌ی بخش‌ها:\n<b>" . bidi(h(self::label($cur))) . "</b>"
            . "\n\n🔎 سرچ وب: <b>" . (AI::searchOn() ? 'روشن' : 'خاموش') . '</b>';
    }

    public static function keyboard(): array
    {
        $cur  = explode(':', AI::route(), 2)[0];
        $rows = [];
        foreach (self::providers() as $id => $name) {
            $on     = $cur === $id;
            $rows[] = [btn(($on ? '✅ ' : '') . $name . ' ▸', "ai:p:{$id}:0", $on ? 'success' : 'primary')];
        }
        if (self::thinkProviders()) {
            $rows[] = [btn('🧠 سطح فکر کردن ▸', 'ai:th', 'primary')];
        }
        $rows[] = [btn(AI::searchOn() ? '🔎 سرچ: روشن ✅' : '🔎 سرچ: خاموش ⛔', 'ai:search', AI::searchOn() ? 'success' : null)];
        return ikb($rows);
    }

    private static function thinkProviders(): array
    {
        return array_intersect_key(self::providers(), ['gemini' => 1, 'groq' => 1]);
    }

    private static function thinkCatalog(string $prov): array
    {
        return array_values(array_filter(self::catalog($prov), static fn($m) => !str_starts_with(substr($m['route'], strlen($prov) + 1), 'gemma')));
    }

    private static function thinkModel(string $prov, string $hash): ?array
    {
        foreach (self::thinkCatalog($prov) as $m) {
            if (substr(md5($m['route']), 0, 8) === $hash) {
                return $m + ['id' => substr($m['route'], strlen($prov) + 1)];
            }
        }
        return null;
    }

    public static function thinkText(?string $prov = null, bool $list = false, ?array $model = null): string
    {
        $l = ['<b>🧠 سطح فکر کردن مدل</b>'];
        if ($prov === null) {
            foreach (self::thinkProviders() as $id => $name) {
                $l[] = $name . ': <b>' . AI::THINK_LEVELS[AI::thinkLevel($id)] . '</b>';
            }
            $l[] = 'فقط روی جواب‌های چت اعمال می‌شه.';
        } elseif ($model !== null) {
            $raw = AI::modelThinkLevel($prov, $model['id']);
            $l[] = bidi(h($model['name'])) . ': <b>' . ($raw === 'auto' ? 'همون تنظیم کلی' : AI::THINK_LEVELS[$raw]) . '</b>';
            $l[] = 'این سطح فقط وقتی اعمال می‌شه که همین مدل رو ثابت انتخاب کرده باشی.';
        } elseif ($list) {
            $l[] = '📌 سطح هر مدل جدا' . "\n" . 'وقتی یه مدل رو ثابت انتخاب کنی (نه خودکار)، سطح خودش اعمال می‌شه؛ برای بقیه‌ی حالت‌ها تنظیم کلی.';
        } else {
            $l[] = (self::providers()[$prov] ?? $prov) . ': <b>' . AI::THINK_LEVELS[AI::thinkLevel($prov)] . '</b>';
            $l[] = '⚡ روی حالت خودکار، این سطح روی همه‌ی مدل‌ها اعمال می‌شه.' . "\n" . 'هرچی بالاتر، دقیق‌تر ولی کندتر.' . "\n" . 'پیش‌فرض: همون تنظیم قبلیِ ربات.';
        }
        return implode("\n\n", $l);
    }

    public static function thinkKeyboard(?string $prov = null, bool $list = false, ?array $model = null): array
    {
        $rows = [];
        if ($prov === null) {
            foreach (self::thinkProviders() as $id => $name) {
                $rows[] = [btn($name . ' · ' . AI::THINK_LEVELS[AI::thinkLevel($id)] . ' ▸', "ai:thp:{$id}", 'primary')];
            }
            $rows[] = [btn('🔙 برگشت', 'ai:home', null)];
            return ikb($rows);
        }
        if ($list) {
            foreach (self::thinkCatalog($prov) as $m) {
                $raw    = AI::modelThinkLevel($prov, substr($m['route'], strlen($prov) + 1));
                $rows[] = [btn(mb_strimwidth($m['name'], 0, 28, '…') . ' · ' . ($raw === 'auto' ? 'کلی' : AI::THINK_LEVELS[$raw]), "ai:thl:{$prov}:" . substr(md5($m['route']), 0, 8), $raw === 'auto' ? 'primary' : 'success')];
            }
            $rows[] = [btn('🔙 برگشت', "ai:thp:{$prov}", null)];
            return ikb($rows);
        }
        $cur = $model !== null ? AI::modelThinkLevel($prov, $model['id']) : AI::thinkLevel($prov);
        $row = [];
        foreach (AI::THINK_LEVELS as $k => $label) {
            $on    = $k === $cur;
            $label = ($model !== null && $k === 'auto') ? 'تنظیم کلی' : $label;
            $data  = $model !== null ? "ai:thv:{$prov}:" . substr(md5($model['route']), 0, 8) . ":{$k}" : "ai:ths:{$prov}:{$k}";
            $row[] = btn(($on ? '✅ ' : '') . $label, $data, $on ? 'success' : 'primary');
            if (count($row) === 2) {
                $rows[] = $row;
                $row    = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        if ($model === null) {
            $rows[] = [btn('📌 هر مدل جدا ▸', "ai:thm:{$prov}", 'primary')];
        }
        $rows[] = [btn('🔙 برگشت', $model !== null ? "ai:thm:{$prov}" : 'ai:th', null)];
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

        if (in_array($act, ['th', 'thp', 'ths', 'thm', 'thl', 'thv'], true) && ($act === 'th' || isset(self::thinkProviders()[$prov]))) {
            $model = in_array($act, ['thl', 'thv'], true) ? self::thinkModel($prov, $p[3] ?? '') : null;
            if ($act === 'ths' && isset(AI::THINK_LEVELS[$p[3] ?? ''])) {
                AI::setThinkLevel($prov, $p[3]);
                $saved = true;
            }
            if ($act === 'thv' && $model !== null && isset(AI::THINK_LEVELS[$p[4] ?? ''])) {
                AI::setModelThinkLevel($prov, $model['id'], $p[4]);
                $saved = true;
            }
            $tp = $act === 'th' ? null : $prov;
            $tg->editMessage($chatId, $msgId, self::thinkText($tp, $act === 'thm', $model), self::thinkKeyboard($tp, $act === 'thm', $model));
            $tg->answer($cq, $saved ? '✅ ذخیره شد' : null);
            return;
        }

        if ($act === 'search') {
            AI::setSearch(!AI::searchOn());
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

        if ($known && ($act === 'p' || $act === 'set')) {
            $tg->editMessage($chatId, $msgId, self::providerText($prov, $page), self::providerKeyboard($prov, $page));
        } else {
            $tg->editMessage($chatId, $msgId, self::text(), self::keyboard());
        }
        $tg->answer($cq, $saved ? '✅ ذخیره شد' : null);
    }
}
