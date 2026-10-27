<?php

final class Weather
{
    private const GEO      = 'https://geocoding-api.open-meteo.com/v1/search';
    private const OSM      = 'https://nominatim.openstreetmap.org/search';
    private const FORECAST = 'https://api.open-meteo.com/v1/forecast';
    private const AIR      = 'https://air-quality-api.open-meteo.com/v1/air-quality';

    private const CODES = [
        0  => ['صاف', '☀️', '🌙'],
        1  => ['تقریباً صاف', '🌤', '🌙'],
        2  => ['نیمه‌ابری', '⛅', '☁️'],
        3  => ['ابری', '☁️', '☁️'],
        45 => ['مه', '🌫', '🌫'],
        48 => ['مه یخ‌زده', '🌫', '🌫'],
        51 => ['نم‌نم باران', '🌦', '🌧'],
        53 => ['نم‌نم باران', '🌦', '🌧'],
        55 => ['نم‌نم باران شدید', '🌧', '🌧'],
        56 => ['نم‌نم یخ‌زده', '🌧', '🌧'],
        57 => ['نم‌نم یخ‌زده', '🌧', '🌧'],
        61 => ['باران سبک', '🌦', '🌧'],
        63 => ['باران', '🌧', '🌧'],
        65 => ['باران شدید', '🌧', '🌧'],
        66 => ['باران یخ‌زده', '🌧', '🌧'],
        67 => ['باران یخ‌زده‌ی شدید', '🌧', '🌧'],
        71 => ['برف سبک', '🌨', '🌨'],
        73 => ['برف', '🌨', '🌨'],
        75 => ['برف سنگین', '❄️', '❄️'],
        77 => ['دونه‌های برف', '🌨', '🌨'],
        80 => ['رگبار سبک', '🌦', '🌧'],
        81 => ['رگبار', '🌧', '🌧'],
        82 => ['رگبار شدید', '⛈', '⛈'],
        85 => ['بارش برف', '🌨', '🌨'],
        86 => ['بارش برف شدید', '❄️', '❄️'],
        95 => ['رعدوبرق', '⛈', '⛈'],
        96 => ['رعدوبرق و تگرگ', '⛈', '⛈'],
        99 => ['رعدوبرق و تگرگ شدید', '⛈', '⛈'],
    ];

    private static function norm(string $city): string
    {
        $c = str_replace(['ي', 'ك', 'ة'], ['ی', 'ک', 'ه'], $city);
        $c = preg_replace('/^(شهر|استان)\s+/u', '', mbTrim($c)) ?? $c;
        return mbTrim(preg_replace('/\s+/u', ' ', $c) ?? $c);
    }

    private static function locate(string $city): ?array
    {
        $city = self::norm($city);
        return Store::remember('cache', 'geo_' . md5(mb_strtolower($city)), 86400 * 30, static function () use ($city) {
            $g = Http::getJson(self::GEO . '?' . http_build_query(['name' => $city, 'count' => 1, 'language' => 'fa', 'format' => 'json']), [], 8);
            if (!empty($g['results'][0])) {
                $r = $g['results'][0];
                return ['name' => $r['name'] ?? $city, 'country' => $r['country'] ?? '', 'lat' => (float) $r['latitude'], 'lon' => (float) $r['longitude']];
            }
            if (mb_strlen($city) < 3) {
                return null;
            }
            $o = Http::getJson(self::OSM . '?' . http_build_query(['q' => $city, 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1, 'accept-language' => 'fa']),
                ['User-Agent: NulliniBot/3.8 (Telegram bot)'], 8);
            if (!empty($o[0]['lat'])) {
                $r = $o[0];
                $n = $r['name'] ?? '';
                return [
                    'name'    => $n !== '' ? $n : $city,
                    'country' => $r['address']['country'] ?? '',
                    'lat'     => (float) $r['lat'],
                    'lon'     => (float) $r['lon'],
                ];
            }
            return null;
        });
    }

    private static function cacheKey(array $loc): string
    {
        return 'wt2_' . round($loc['lat'], 2) . '_' . round($loc['lon'], 2);
    }

    public static function fetch(string $city): ?array
    {
        if (mbTrim($city) === '') {
            return null;
        }
        $loc = self::locate($city);
        if ($loc === null) {
            return null;
        }
        return Store::remember('cache', self::cacheKey($loc), 600, static function () use ($loc) {
            $w = Http::getJson(self::FORECAST . '?' . http_build_query([
                'latitude'      => $loc['lat'],
                'longitude'     => $loc['lon'],
                'current'       => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,surface_pressure,is_day',
                'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
                'timezone'      => 'auto',
                'forecast_days' => 3,
            ]), [], 10);
            if (empty($w['current']) || empty($w['daily']['time'])) {
                return null;
            }
            $a   = Http::getJson(self::AIR . '?' . http_build_query(['latitude' => $loc['lat'], 'longitude' => $loc['lon'], 'current' => 'us_aqi,pm2_5']), [], 6);
            $cur = $w['current'];
            return [
                'location' => ['name' => $loc['name'], 'country' => $loc['country']],
                'current'  => [
                    'temp_c'    => $cur['temperature_2m'] ?? 0,
                    'condition' => ['text' => self::codeText((int) ($cur['weather_code'] ?? 0))],
                ],
                'raw'      => $w,
                'air'      => $a['current'] ?? null,
            ];
        });
    }

    private static function codeText(int $code): string
    {
        return self::CODES[$code][0] ?? '—';
    }

    private static function codeEmoji(int $code, bool $day = true): string
    {
        return self::CODES[$code][$day ? 1 : 2] ?? '🌡';
    }

    private static function deg($v): string
    {
        return fa((int) round((float) $v)) . '°';
    }

    private static function windDir(float $deg): string
    {
        $dirs = ['شمال', 'شمال‌شرق', 'شرق', 'جنوب‌شرق', 'جنوب', 'جنوب‌غرب', 'غرب', 'شمال‌غرب'];
        return $dirs[(int) round(fmod($deg + 360, 360) / 45) % 8];
    }

    private static function aqi(int $v): string
    {
        return match (true) {
            $v <= 50  => '🟢 پاک',
            $v <= 100 => '🟡 قابل قبول',
            $v <= 150 => '🟠 ناسالم برای حساس‌ها',
            $v <= 200 => '🔴 ناسالم',
            $v <= 300 => '🟣 خیلی ناسالم',
            default   => '🟤 خطرناک',
        };
    }

    public static function format(array $d): string
    {
        $w     = $d['raw'];
        $cur   = $w['current'];
        $daily = $w['daily'];
        $code  = (int) ($cur['weather_code'] ?? 0);
        $isDay = !empty($cur['is_day']);
        $local = isset($cur['time']) ? fa(substr((string) $cur['time'], 11, 5)) : Jalali::time(time());
        $place = h($d['location']['name']) . ($d['location']['country'] !== '' ? ' · ' . h($d['location']['country']) : '');

        $l   = [];
        $l[] = self::codeEmoji($code, $isDay) . " <b>آب‌وهوای {$place}</b>";
        $l[] = '🕒 ' . Jalali::fullDate(time()) . " · ساعت محلی {$local}";
        $l[] = '';
        $l[] = '🌡 دما: <b>' . self::deg($cur['temperature_2m'] ?? 0) . '</b> (حس می‌شه ' . self::deg($cur['apparent_temperature'] ?? 0) . ')';
        $l[] = '⬆️ بیشینه ' . self::deg($daily['temperature_2m_max'][0] ?? 0) . ' · ⬇️ کمینه ' . self::deg($daily['temperature_2m_min'][0] ?? 0);
        $l[] = '☁️ وضعیت: ' . self::codeText($code);
        $l[] = '💧 رطوبت: ' . fa((int) ($cur['relative_humidity_2m'] ?? 0)) . '٪ · ابر: ' . fa((int) ($cur['cloud_cover'] ?? 0)) . '٪';
        $l[] = '💨 باد: ' . fa((int) round((float) ($cur['wind_speed_10m'] ?? 0))) . ' کیلومتر/ساعت (از ' . self::windDir((float) ($cur['wind_direction_10m'] ?? 0)) . ')';
        $l[] = '🧭 فشار: ' . fa((int) round((float) ($cur['surface_pressure'] ?? 0))) . ' میلی‌بار';
        if (!empty($daily['precipitation_probability_max'][0])) {
            $l[] = '☔ احتمال بارش امروز: ' . fa((int) $daily['precipitation_probability_max'][0]) . '٪';
        }
        if (!empty($daily['sunrise'][0]) && !empty($daily['sunset'][0])) {
            $l[] = '🌅 طلوع ' . fa(substr((string) $daily['sunrise'][0], 11, 5)) . ' · 🌇 غروب ' . fa(substr((string) $daily['sunset'][0], 11, 5));
        }
        if (isset($d['air']['us_aqi'])) {
            $pm  = $d['air']['pm2_5'] ?? null;
            $l[] = '🫁 کیفیت هوا: ' . self::aqi((int) $d['air']['us_aqi']) . ($pm !== null ? ' (PM2.5: ' . fa(round((float) $pm, 1)) . ')' : '');
        }

        $future = [];
        foreach ([1 => 'فردا', 2 => 'پس‌فردا'] as $i => $name) {
            if (isset($daily['temperature_2m_max'][$i])) {
                $c        = (int) ($daily['weather_code'][$i] ?? 0);
                $rain     = (int) ($daily['precipitation_probability_max'][$i] ?? 0);
                $future[] = "📆 {$name}: " . self::codeEmoji($c) . ' ' . self::deg($daily['temperature_2m_max'][$i]) . ' / ' . self::deg($daily['temperature_2m_min'][$i])
                    . ' · ' . self::codeText($c) . ($rain > 0 ? ' · ☔ ' . fa($rain) . '٪' : '');
            }
        }
        if ($future) {
            $l[] = '';
            array_push($l, ...$future);
        }
        return implode("\n", $l);
    }

    public static function keyboard(string $hash = ''): array
    {
        return ikb([[btn('🔄 بروزرسانی', $hash === '' ? 'weather:refresh' : 'weather:refresh:' . $hash)]]);
    }

    public static function onCallback(array $cq, string $imid, string $data = ''): void
    {
        $tg   = App::tg();
        $city = Store::get('weather', $imid);
        $hash = preg_match('/^weather:refresh:(\w{16})$/', $data, $m) ? $m[1] : '';
        if ((!is_string($city) || $city === '') && $hash !== '') {
            $city = Store::get('weather_pending', $hash);
            if (is_string($city) && $city !== '') {
                Store::set('weather', $imid, $city);
            }
        }
        if (!is_string($city) || $city === '') {
            $tg->alert($cq, '⚠️ اطلاعات این پیام پیدا نشد؛' . "\n" . 'دوباره از منو بگیرش.');
            return;
        }
        if ($loc = self::locate($city)) {
            Store::delete('cache', self::cacheKey($loc));
        }
        $data = self::fetch($city);
        if ($data === null) {
            $tg->alert($cq, '⚠️ الان نتونستم آب‌وهوا رو بگیرم؛' . "\n" . 'یه‌کم دیگه امتحان کن.');
            return;
        }
        $tg->editInline($imid, self::format($data), self::keyboard($hash !== '' ? $hash : substr(md5(mb_strtolower($city)), 0, 16)));
        $tg->answer($cq, '🔄 به‌روز شد');
    }
}
