<?php

final class Currency
{
    private const FEEDS = [
        'https://call4.tgju.org/ajax.json',
        'https://call1.tgju.org/ajax.json',
        'https://call5.tgju.org/ajax.json',
    ];

    private const FIAT = [
        '🇺🇸 دلار'       => 'price_dollar_rl',
        '🇪🇺 یورو'       => 'price_eur',
        '🇬🇧 پوند'       => 'price_gbp',
        '🇦🇪 درهم'       => 'price_aed',
        '🇹🇷 لیر'        => 'price_try',
        '🇨🇭 فرانک'      => 'price_chf',
        '🇨🇦 دلار کانادا' => 'price_cad',
        '🇨🇳 یوان'       => 'price_cny',
        '🇷🇺 روبل'       => 'price_rub',
        '🇮🇳 روپیه'      => 'price_inr',
        '🇮🇶 دینار'      => 'price_iqd',
        '🇦🇫 افغانی'     => 'price_afn',
    ];

    private const CRYPTO = [
        '₿ بیت‌کوین'   => 'crypto-bitcoin',
        '💠 اتریوم'    => 'crypto-ethereum',
        '🟡 بایننس'    => 'crypto-binance-coin',
        '🟣 سولانا'    => 'crypto-solana',
        '💎 تون‌کوین'  => 'crypto-toncoin',
        '⚪ ریپل'      => 'crypto-ripple',
        '🔴 ترون'      => 'crypto-tron',
        '🐕 دوج‌کوین'  => 'crypto-dogecoin',
        '🥈 لایت‌کوین' => 'crypto-litecoin',
    ];

    private const MESGHAL_GRAMS = 4.3318;

    private static function fetchFeed(): ?array
    {
        foreach (self::FEEDS as $url) {
            $data = Http::getJson($url, [], 8);
            if (isset($data['current']) && is_array($data['current'])) {
                return $data['current'];
            }
        }
        return null;
    }

    private static function num(array $feed, string $key): ?float
    {
        $raw = $feed[$key]['p'] ?? null;
        if (!is_scalar($raw)) {
            return null;
        }
        $clean = str_replace([',', ' '], '', (string) $raw);
        return is_numeric($clean) ? (float) $clean : null;
    }

    public static function rates(): ?array
    {
        return Store::remember('cache', 'rates', 120, static function () {
            $feed = self::fetchFeed();
            if ($feed === null) {
                return null;
            }
            $out = ['fiat' => [], 'crypto' => [], 'gold' => []];
            foreach (self::FIAT as $label => $key) {
                $rial                = self::num($feed, $key);
                $out['fiat'][$label] = $rial !== null ? $rial / 10 : null;
            }
            foreach (self::CRYPTO as $label => $key) {
                $out['crypto'][$label] = self::num($feed, $key);
            }
            $tether          = self::num($feed, 'crypto-tether-irr');
            $gram            = self::num($feed, 'geram18');
            $coin            = self::num($feed, 'sekee');
            $out['tether']   = $tether !== null ? $tether / 10 : null;
            $out['gold']     = [
                'coin'    => $coin !== null ? $coin / 10 : null,
                'gram'    => $gram !== null ? $gram / 10 : null,
                'mesghal' => $gram !== null ? $gram / 10 * self::MESGHAL_GRAMS : null,
                'ounce'   => self::num($feed, 'ons'),
            ];
            return $out;
        });
    }

    private static function toman(?float $v): string
    {
        return $v === null ? '—' : number_format($v) . ' ت';
    }

    private static function usd(?float $v): string
    {
        if ($v === null) {
            return '—';
        }
        return '$' . ($v >= 1 ? number_format($v, 2) : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'));
    }

    public static function message(): string
    {
        $now  = time();
        $r    = self::rates();
        $head = "<b>💱 نرخ لحظه‌ای بازار</b>\n🕒 " . Jalali::fullDate($now) . ' · ' . Jalali::time($now);

        if ($r === null) {
            return $head . "\n\n⚠️ الان به منبع قیمت‌ها دسترسی ندارم؛\nچند لحظه دیگه دوباره بزن.";
        }

        $l   = [$head, '', '<b>💵 ارزها</b>'];
        foreach ($r['fiat'] as $label => $v) {
            $l[] = "{$label}: <code>" . self::toman($v) . '</code>';
        }
        $g   = $r['gold'];
        $l[] = '';
        $l[] = '<b>🥇 طلا و سکه</b>';
        $l[] = '🪙 سکه امامی: <code>' . self::toman($g['coin']) . '</code>';
        $l[] = '🔸 مثقال طلا: <code>' . self::toman($g['mesghal']) . '</code>';
        $l[] = '🔹 گرم ۱۸ عیار: <code>' . self::toman($g['gram']) . '</code>';
        $l[] = '🌕 انس جهانی: <code>' . self::usd($g['ounce']) . '</code>';
        $l[] = '';
        $l[] = '<b>🪙 ارز دیجیتال</b>';
        $l[] = '💵 تتر: <code>' . self::toman($r['tether']) . '</code>';
        foreach ($r['crypto'] as $label => $v) {
            $l[] = "{$label}: <code>" . self::usd($v) . '</code>';
        }
        $l[] = '';
        $l[] = '<i>ت = تومان · منبع: بازار آزاد</i>';
        return implode("\n", $l);
    }

    public static function keyboard(): array
    {
        return ikb([[btn('🔄 بروزرسانی', 'rates:refresh')]]);
    }

    public static function pendingKeyboard(): array
    {
        return ikb([[btn('📊 نمایش نرخ‌ها', 'rates:show', 'success')]]);
    }

    public static function onCallback(array $cq, string $data, string $imid): void
    {
        $tg = App::tg();
        if (self::rates() === null) {
            $tg->alert($cq, '⚠️ الان نتونستم نرخ‌ها رو بگیرم؛' . "\n" . 'یه‌کم دیگه امتحان کن.');
            return;
        }
        $tg->editInline($imid, self::message(), self::keyboard());
        $tg->answer($cq, $data === 'rates:refresh' ? '🔄 به‌روز شد' : null);
    }
}
