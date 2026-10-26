<?php

final class AiDetect
{
    private const MAX_BYTES = 10485760;

    private const GENERATORS = [
        'Midjourney'                => '/midjourney/i',
        'DALL-E / OpenAI'           => '/\bdall[\s_.-]?e\b|openai|chatgpt|gpt-image/i',
        'Adobe Firefly'             => '/adobe[\s_-]?firefly/i',
        'Stable Diffusion'          => '/stable[\s_-]?diffusion|sd-metadata|automatic1111|comfyui|invokeai|fooocus|negative prompt:|sampler:\s*[\w+ ]+,\s*cfg scale|"class_type"/i',
        'NovelAI'                   => '/novelai/i',
        'Leonardo.Ai'               => '/leonardo\.ai/i',
        'Ideogram'                  => '/ideogram/i',
        'Flux'                      => '/black[\s_-]?forest[\s_-]?labs|\bflux[\s._-]?(?:1|dev|schnell|pro)\b/i',
        'Google Imagen / Gemini'    => '/google imagen|imagen[\s_-]?\d|gemini[\s_-]?(?:\d|image|flash|pro)|synthid|nano[\s_-]?banana/i',
        'Bing / Microsoft Designer' => '/bing image creator|microsoft designer/i',
    ];

    private const PROMPT = 'You are a forensic image analyst. Decide whether the image was generated or heavily synthesized by AI '
        . '(diffusion models, GANs, AI image editors) or is a genuine photograph, human-made artwork or screenshot. '
        . 'Inspect anatomy (hands, fingers, teeth, ears, eyes and their reflections, asymmetric accessories), text and signage (garbled or meaningless glyphs), '
        . 'physics (shadow direction, reflections, lighting consistency), textures (plastic or waxy skin, over-smooth surfaces, uniform fake bokeh), '
        . 'melted or repeated patterns, background coherence (impossible geometry, warped objects, merged items), clothing and jewelry continuity, '
        . 'and the typical over-polished cinematic AI look. Also look for real-photo evidence: natural sensor noise, motion blur, imperfect framing, mundane details. '
        . 'Be calibrated: never call an image AI only because it is beautiful, filtered, edited, compressed or drawn by a human. '
        . 'Reply with ONLY a JSON object: {"ai_probability": <integer 0-100>, "kind": "photo"|"illustration"|"screenshot"|"other", '
        . '"signs": [<every observation that supports your verdict, in casual Persian, as many and as detailed as you see fit>]}';

    public static function report(array $msg): string
    {
        $src = self::source($msg);
        if ($src === null || $src['id'] === '') {
            return Ui::guide('aicheck');
        }
        $bytes = App::tg()->download($src['id']);
        $info  = $bytes !== null ? @getimagesizefromstring($bytes) : false;
        if ($bytes === null || $info === false || strlen($bytes) > self::MAX_BYTES) {
            return '😕 نتونستم عکس رو بخونم؛' . "\n" . 'دوباره بفرستش.';
        }
        $vis = self::vision($bytes, (string) ($info['mime'] ?? 'image/jpeg'));
        return self::format(self::metadata($bytes), $vis, $src['file']);
    }

    public static function pages(string $text): array
    {
        $pages = [];
        $cur   = '';
        foreach (explode("\n", $text) as $line) {
            foreach (mb_strlen($line) > 4000 ? AI::chunks($line, 4000) : [$line] as $piece) {
                if ($cur !== '' && mb_strlen($cur) + mb_strlen($piece) + 1 > 4000) {
                    $pages[] = $cur;
                    $cur     = $piece;
                } else {
                    $cur = $cur === '' ? $piece : $cur . "\n" . $piece;
                }
            }
        }
        $pages[] = $cur;
        return $pages;
    }

    public static function metadata(string $bytes): array
    {
        $blob  = self::segments($bytes);
        $tools = [];
        foreach (self::GENERATORS as $name => $re) {
            if (preg_match($re, $blob) === 1) {
                $tools[] = $name;
            }
        }
        $edited  = preg_match('/compositeWithTrainedAlgorithmicMedia|compositeSynthetic/i', $blob) === 1;
        $trained = !$edited && preg_match('/trainedAlgorithmicMedia/i', $blob) === 1;
        return [
            'ai'      => $trained || ($tools !== [] && !$edited),
            'trained' => $trained,
            'edited'  => $edited,
            'tools'   => $tools,
            'camera'  => self::camera($bytes),
        ];
    }

    private static function source(array $msg): ?array
    {
        foreach ([$msg, $msg['reply_to_message'] ?? []] as $m) {
            if (!empty($m['photo']) && is_array($m['photo'])) {
                $sizes = $m['photo'];
                $best  = end($sizes);
                return ['id' => (string) ($best['file_id'] ?? ''), 'file' => false];
            }
            $d = $m['document'] ?? null;
            if (is_array($d) && str_starts_with((string) ($d['mime_type'] ?? ''), 'image/') && (int) ($d['file_size'] ?? 0) <= self::MAX_BYTES) {
                return ['id' => (string) ($d['file_id'] ?? ''), 'file' => true];
            }
        }
        return null;
    }

    private static function segments(string $b): string
    {
        $out = '';
        $n   = strlen($b);
        if (str_starts_with($b, "\xFF\xD8")) {
            $i = 2;
            while ($i + 4 <= $n && $b[$i] === "\xFF") {
                $m = ord($b[$i + 1]);
                if ($m === 0xDA || $m === 0xD9) {
                    break;
                }
                if ($m === 0xFF) {
                    $i++;
                    continue;
                }
                if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) {
                    $i += 2;
                    continue;
                }
                $len = (ord($b[$i + 2]) << 8) | ord($b[$i + 3]);
                if ($len < 2) {
                    break;
                }
                if (in_array($m, [0xE1, 0xEB, 0xED, 0xFE], true)) {
                    $out .= substr($b, $i + 4, $len - 2) . "\n";
                }
                $i += 2 + $len;
            }
        } elseif (str_starts_with($b, "\x89PNG\r\n\x1A\n")) {
            $i = 8;
            while ($i + 12 <= $n && strlen($out) < 2097152) {
                $len  = (int) unpack('N', substr($b, $i, 4))[1];
                $type = substr($b, $i + 4, 4);
                if ($type === 'IEND' || $len > $n) {
                    break;
                }
                if (in_array($type, ['tEXt', 'iTXt', 'zTXt', 'eXIf', 'caBX'], true)) {
                    $out .= self::pngChunk($type, substr($b, $i + 8, $len)) . "\n";
                }
                $i += 12 + $len;
            }
        } elseif (str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP') {
            $i = 12;
            while ($i + 8 <= $n && strlen($out) < 2097152) {
                $type = substr($b, $i, 4);
                $len  = (int) unpack('V', substr($b, $i + 4, 4))[1];
                if (in_array($type, ['EXIF', 'XMP ', 'C2PA'], true)) {
                    $out .= substr($b, $i + 8, $len) . "\n";
                }
                $i += 8 + $len + ($len & 1);
            }
        }
        return $out;
    }

    private static function pngChunk(string $type, string $data): string
    {
        if ($type !== 'iTXt' && $type !== 'zTXt') {
            return $data;
        }
        $p = strpos($data, "\0");
        if ($p === false || !isset($data[$p + 2])) {
            return '';
        }
        $key = substr($data, 0, $p);
        if ($type === 'zTXt') {
            return $key . ' ' . (string) @gzuncompress(substr($data, $p + 2));
        }
        $rest = substr($data, $p + 3);
        if (ord($data[$p + 1]) === 1) {
            $rest = (string) @gzuncompress(explode("\0", $rest, 3)[2] ?? '');
        }
        return $key . ' ' . $rest;
    }

    private static function camera(string $b): ?string
    {
        if (!function_exists('exif_read_data') || !str_starts_with($b, "\xFF\xD8")) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'nl_ex_');
        if ($tmp === false || file_put_contents($tmp, $b) === false) {
            return null;
        }
        try {
            $e = @exif_read_data($tmp);
        } finally {
            @unlink($tmp);
        }
        if (!is_array($e) || !isset($e['Model']) || (!isset($e['ExposureTime']) && !isset($e['FNumber']))) {
            return null;
        }
        $name = trim(trim((string) ($e['Make'] ?? '')) . ' ' . trim((string) $e['Model']));
        return $name !== '' ? mb_substr(preg_replace('/[^\x20-\x7E]/', '', $name) ?? $name, 0, 60) : null;
    }

    private static function vision(string $bytes, string $mime): ?array
    {
        $messages = [
            ['role' => 'system', 'content' => self::PROMPT],
            ['role' => 'user', 'content' => [
                ['type' => 'media', 'mime' => $mime, 'data' => base64_encode($bytes)],
                ['type' => 'text', 'text' => 'Analyze this image and answer with the JSON only.'],
            ]],
        ];
        $opt  = ['tier' => 'smart', 'temperature' => 0.2, 'section' => 'other'];
        $text = AI::chat($messages, ['deadline' => microtime(true) + 40] + $opt);
        if ($text === null && AI::hasTiers()) {
            $text = AI::chat($messages, ['tier' => 'lite', 'thinking' => null, 'deadline' => microtime(true) + 25] + $opt);
        }
        if ($text === null || !preg_match('/\{.*\}/s', $text, $m)) {
            return null;
        }
        $j = json_decode($m[0], true);
        if (!is_array($j) || !isset($j['ai_probability']) || !is_numeric($j['ai_probability'])) {
            return null;
        }
        $signs = array_filter((array) ($j['signs'] ?? []), 'is_string');
        $signs = array_map(static fn($s) => mbTrim($s), $signs);
        return [
            'score' => max(0, min(100, (int) round((float) $j['ai_probability']))),
            'signs' => array_values(array_filter($signs, static fn($s) => $s !== '')),
        ];
    }

    private static function format(array $meta, ?array $vis, bool $asFile): string
    {
        $score = $vis['score'] ?? null;
        $pct   = null;
        if ($meta['ai']) {
            $pct   = max(95, $score ?? 0);
            $label = '🤖 به‌احتمال خیلی زیاد ساخته‌ی هوش مصنوعیه';
        } elseif ($meta['edited']) {
            $pct   = max(70, $score ?? 0);
            $label = '🎨 با هوش مصنوعی ویرایش یا ترکیب شده';
        } elseif ($score !== null) {
            $pct   = $meta['camera'] !== null ? (int) round($score * 0.7) : $score;
            $label = match (true) {
                $pct >= 70 => '🤖 به‌احتمال زیاد ساخته‌ی هوش مصنوعیه',
                $pct >= 40 => '🤔 مطمئن نیستم؛ ممکنه هر دو حالت باشه',
                default    => '📷 به‌احتمال زیاد واقعیه',
            };
        } else {
            $label = '😕 نتونستم تحلیل بصری انجام بدم؛ یه‌کم دیگه دوباره امتحان کن.';
        }

        $l = ['🔎 <b>بررسی عکس</b>', ''];
        if ($pct !== null) {
            $l[] = '📊 احتمال هوش مصنوعی بودن: <b>' . fa($pct) . '٪</b>';
        }
        $l[] = '<b>' . $label . '</b>';

        if (!empty($vis['signs'])) {
            $l[] = '';
            $l[] = '<b>🧐 چیزایی که دیدم:</b>';
            foreach ($vis['signs'] as $s) {
                $l[] = '• ' . h($s);
            }
        }

        $m = [];
        if ($meta['tools']) {
            $m[] = '• اسم ابزار تو متادیتای فایل هست: ' . h(implode('، ', $meta['tools']));
        }
        if ($meta['trained']) {
            $m[] = '• تو متادیتا ثبت شده که با هوش مصنوعی تولید شده';
        }
        if ($meta['edited']) {
            $m[] = '• تو متادیتا ثبت شده که بخشی از عکس با هوش مصنوعی ساخته یا ویرایش شده';
        }
        if ($meta['camera'] !== null) {
            $m[] = '• متادیتای دوربین (' . h($meta['camera']) . ') داره؛ البته قابل جعله';
        }
        if (!$m) {
            $m[] = $asFile
                ? '• متادیتای مشکوکی تو فایل نبود؛ البته این به‌تنهایی دلیل واقعی بودن نیست'
                : '• تلگرام متادیتای عکس رو پاک می‌کنه؛ برای بررسی دقیق‌تر عکس رو به‌صورت «فایل» (Send as File) بفرست';
        }
        $l[] = '';
        $l[] = '<b>📎 متادیتا:</b>';
        array_push($l, ...$m);
        $l[] = '';
        $l[] = '⚠️ <i>تشخیص هیچ ابزاری ۱۰۰٪ دقیق نیست؛ اینو فقط یه برآورد حساب کن.</i>';
        return implode("\n", $l);
    }
}
