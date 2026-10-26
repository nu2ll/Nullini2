<?php

/**
 * نمایش زنده‌ی جواب هوش مصنوعی: همین‌طور که متن می‌رسه، یه پیام می‌فرسته و مدام ویرایشش می‌کنه.
 * متن خام از AI::reply (گزینه‌ی on_text) می‌رسه؛ خط REACTION و دستورهای ACTION/IMAGE نمایش داده نمی‌شن.
 */
final class LiveReply
{
    public const DRAWING = '🎨 دارم می‌کشمش، چند لحظه صبر کن...';

    private array $ids    = [];
    private array $sent   = [];
    private string $mode  = 'wait';   // wait | live | silent
    private int $lastLen  = 0;

    public function __construct(private array $msg, private ?string $imid = null, private string $head = '', private bool $reaction = true)
    {
    }

    public function feed(string $raw): void
    {
        if ($this->mode === 'silent') {
            return;
        }
        $body = $this->body($raw, false);
        if ($body === false) {
            $this->mode = 'silent';
            return;
        }
        if ($body === null) {
            return;
        }
        $this->mode = 'live';
        $this->show($body);
    }

    public function finish(string $answer): bool
    {
        if ($this->ids === []) {
            return false;
        }
        if ($this->sync($answer, true)) {
            return true;
        }
        $this->discard();
        return false;
    }

    public function finishHtml(string $html): bool
    {
        if (!isset($this->ids[0])) {
            return false;
        }
        $r = App::tg()->editMessage($this->msg['chat']['id'], $this->ids[0], $html);
        if (!empty($r['ok']) || stripos((string) ($r['description'] ?? ''), 'not modified') !== false) {
            return true;
        }
        $this->discard();
        return false;
    }

    public function active(): bool
    {
        return $this->ids !== [];
    }

    public function discard(): void
    {
        foreach ($this->ids as $id) {
            App::tg()->call('deleteMessage', ['chat_id' => $this->msg['chat']['id'], 'message_id' => $id], 8);
        }
        $this->ids     = [];
        $this->sent    = [];
        $this->mode    = 'wait';
        $this->lastLen = 0;
    }

    /** @return string|null|false  متن قابل نمایش، null = هنوز معلوم نیست، false = این جواب نمایش داده نمی‌شه */
    private function body(string $raw, bool $final)
    {
        $t = preg_replace('#<think>.*?</think>#si', '', $raw) ?? $raw;
        $t = ltrim(preg_replace('#<think>.*$#si', '', $t) ?? $t);
        if ($t === '') {
            return null;
        }

        $nl = strpos($t, "\n");
        if ($this->reaction) {
            if ($nl === false) {
                if (!$final && (preg_match('/^[\s*`«»\[]*reaction(?![A-Za-z])/iu', $t) || AI::isEmojiLine($t))) {
                    return null;
                }
            } else {
                $t = AI::splitReaction($t, true)[1];
                if (!$final && AI::isEmojiLine($t)) {
                    return null;
                }
            }
        }

        $t = ltrim($t);
        if ($t === '') {
            return null;
        }

        // Image-generation tags are actions, not user-facing streamed text.
        // Show the drawing status while the image-generation handler works.
        if (preg_match('/^\s*\[\[\s*image\s*[|｜:：]/iu', $t)) {
            return self::DRAWING;
        }

        if (preg_match('/^\s*\[\[\s*(' . implode('|', Intent::NAMES) . ')(?![A-Za-z])/iu', $t)) {
            return false;
        }
        if (!$final && preg_match('/^\s*\[{1,2}\s*[A-Za-z]*$/u', $t)) {
            return null;
        }

        if (preg_match('/^[\s*`«»]*([A-Za-z]+)(.*)$/su', $t, $w)) {
            if (!$final && trim($w[2]) === '') {
                return null;
            }
        }
        return $t;
    }

    private function show(string $body): void
    {
        $chunked = $this->imid === null && $this->head === '';
        $text    = AI::clean($body, $chunked ? PHP_INT_MAX : 4094 - AI::visible($this->head));
        if ($text === '') {
            return;
        }
        $len = mb_strlen($text);
        if ($len === $this->lastLen) {
            return;
        }
        $this->lastLen = $len;

        if ($chunked) {
            $this->sync($text, false);
            return;
        }

        if (substr_count($text, '```') % 2 === 1) {
            $text .= "\n```";
        }
        $html = $this->head . AI::toHtml($text) . ' ▍';
        $tg   = App::tg();

        if ($this->imid !== null) {
            $tg->editInline($this->imid, $html);
        } elseif (!isset($this->ids[0])) {
            $r = $tg->reply($this->msg, $html);
            if (!empty($r['ok']) && isset($r['result']['message_id'])) {
                $this->ids[0] = (int) $r['result']['message_id'];
            }
        } else {
            $tg->editMessage($this->msg['chat']['id'], $this->ids[0], $html);
        }
    }

    private function sync(string $text, bool $final): bool
    {
        $tg    = App::tg();
        $parts = AI::chunks($text);
        $last  = count($parts) - 1;

        foreach ($parts as $i => $part) {
            if (substr_count($part, '```') % 2 === 1) {
                $part .= "\n```";
            }
            $html = AI::toHtml($part) . ($i === $last && !$final && mb_strlen($part) <= 4094 ? ' ▍' : '');
            if (($this->sent[$i] ?? null) === $html) {
                continue;
            }
            if (!isset($this->ids[$i])) {
                $r = $tg->reply($this->msg, $html);
                if (empty($r['ok']) || !isset($r['result']['message_id'])) {
                    return false;
                }
                $this->ids[$i] = (int) $r['result']['message_id'];
            } else {
                $r = $tg->editMessage($this->msg['chat']['id'], $this->ids[$i], $html);
                if (empty($r['ok']) && stripos((string) ($r['description'] ?? ''), 'not modified') === false) {
                    return false;
                }
            }
            $this->sent[$i] = $html;
        }
        return true;
    }
}
