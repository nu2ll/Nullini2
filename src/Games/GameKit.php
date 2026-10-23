<?php

final class GameKit
{
    public const NS = 'games';

    public static function load(string $imid): ?array
    {
        $s = Store::get(self::NS, $imid);
        return is_array($s) ? $s : null;
    }

    public static function save(string $imid, array $s): void
    {
        $s['bot'] = App::botIndex();
        Store::set(self::NS, $imid, $s);
    }

    public static function player(array $from): array
    {
        return ['id' => (int) $from['id'], 'name' => mb_substr(userName($from), 0, 28)];
    }

    public static function fresh(string $game, array $creator, array $extra = []): array
    {
        return ['game' => $game, 'status' => 'mode', 'p1' => $creator, 'p2' => null] + $extra;
    }

    public static function ensure(string $imid, string $game, array $from, array $extra = []): array
    {
        $s = self::load($imid);
        if ($s === null || ($s['game'] ?? '') !== $game) {
            $s = self::fresh($game, self::player($from), $extra);
        }
        return $s;
    }

    public static function name(?array $p, string $fallback = 'حریف'): string
    {
        return $p ? bidi(h($p['name'])) : $fallback;
    }

    public static function isPlayer(array $s, int $uid): bool
    {
        return (int) ($s['p1']['id'] ?? 0) === $uid || (int) ($s['p2']['id'] ?? 0) === $uid;
    }

    public static function isCreator(array $s, int $uid): bool
    {
        return (int) ($s['p1']['id'] ?? 0) === $uid;
    }

    public static function seat(array &$s, array $from, int $turn): int
    {
        $uid = (int) $from['id'];
        if ((int) $s['p1']['id'] === $uid) {
            return 1;
        }
        if (!empty($s['p2'])) {
            return (int) $s['p2']['id'] === $uid ? 2 : 0;
        }
        if ($turn === 2 && ($s['mode'] ?? '') === 'pvp') {
            $s['p2'] = self::player($from);
            return 2;
        }
        return 0;
    }

    public static function modeKeyboard(string $prefix, string $pvpLabel = '👥 دو نفره'): array
    {
        return ikb([[btn('🤖 با ربات', "{$prefix}:mode:bot"), btn($pvpLabel, "{$prefix}:mode:pvp")]]);
    }

    public static function replayKeyboard(string $prefix): array
    {
        return ikb([[btn('🔁 بازی دوباره', "{$prefix}:replay", 'success')]]);
    }

    public static function cancelRow(string $prefix): array
    {
        return [btn('✖️ لغو بازی', "{$prefix}:cancel", 'danger')];
    }

    public static function controlRow(array $s, string $prefix): array
    {
        if (($s['mode'] ?? '') === 'pvp' && empty($s['p2'])) {
            return self::cancelRow($prefix);
        }
        return [btn('🏳 تسلیم', "{$prefix}:resign", 'danger')];
    }

    public static function resignNote(array $s): string
    {
        return '';
    }

    public static function resignLine(array $s): string
    {
        $loser  = (int) ($s['resigned'] ?? 0) === 1 ? $s['p1'] : ($s['p2'] ?? null);
        $winner = (int) ($s['resigned'] ?? 0) === 1 ? ($s['p2'] ?? null) : $s['p1'];
        return '🏳 ' . self::name($loser) . ' تسلیم شد؛ 🏆 <b>' . self::name($winner) . ' برد!</b>';
    }

    public static function common(array &$s, array $cq, string $data, string $prefix, callable $onMode, callable $onReplay, callable $renderCancel, ?callable $onResign = null): bool
    {
        $tg  = App::tg();
        $uid = (int) $cq['from']['id'];

        if ($data === "{$prefix}:resign" && $onResign !== null) {
            if (!self::isPlayer($s, $uid)) {
                $tg->alert($cq, '🚫 تو این بازی نیستی.');
                return true;
            }
            if (($s['status'] ?? '') !== 'playing') {
                $tg->answer($cq, '⌛️ این بازی تموم شده');
                return true;
            }
            $asked = (int) ($s['resign_ask'][(string) $uid] ?? 0);
            if (time() - $asked > 15) {
                $s['resign_ask'] = [(string) $uid => time()];
                $onResign(false);
                $tg->alert($cq, "🏳 مطمئنی؟\nبرای تسلیم تا ۱۵ ثانیه دیگه دوباره بزن.");
                return true;
            }
            unset($s['resign_ask']);
            $s['status']   = 'over';
            $s['resigned'] = self::isCreator($s, $uid) ? 1 : 2;
            $onResign(true);
            $tg->answer($cq, '🏳 تسلیم شدی');
            return true;
        }

        if ($data === "{$prefix}:replay") {
            if (!self::isPlayer($s, $uid)) {
                $tg->alert($cq, "🔒 فقط بازیکن‌های همین بازی\nمی‌تونن دوباره شروعش کنن.");
                return true;
            }
            $onReplay();
            $tg->answer($cq, '🔁 بزن بریم!');
            return true;
        }

        if (str_starts_with($data, "{$prefix}:mode:")) {
            if (($s['status'] ?? '') !== 'mode') {
                $tg->answer($cq, '⏳ بازی شروع شده');
                return true;
            }
            if (!self::isCreator($s, $uid)) {
                $tg->alert($cq, '🔒 حالت بازی رو فقط سازنده انتخاب می‌کنه.');
                return true;
            }
            $onMode(substr($data, strlen("{$prefix}:mode:")));
            $tg->answer($cq);
            return true;
        }

        if ($data === "{$prefix}:cancel") {
            if (!self::isPlayer($s, $uid)) {
                $tg->alert($cq, '🚫 فقط بازیکن‌ها می‌تونن بازی رو لغو کنن.');
                return true;
            }
            if (($s['status'] ?? '') === 'over') {
                $tg->answer($cq);
                return true;
            }
            $s['status'] = 'over';
            $renderCancel(bidi(h(userName($cq['from']))));
            $tg->answer($cq, 'بازی لغو شد');
            return true;
        }
        return false;
    }
}
