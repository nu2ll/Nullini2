<?php

/**
 * نمایش زنده‌ی جواب هوش مصنوعی: همین‌طور که متن می‌رسه، یه پیام می‌فرسته و مدام ویرایشش می‌کنه.
 * متن خام از AI::reply (گزینه‌ی on_text) می‌رسه؛ خط REACTION و دستورهای ACTION/IMAGE نمایش داده نمی‌شن.
 */
final class LiveReply
{
    private ?int $mid     = null;
    private string $mode  = 'wait';   // wait | live | silent
    private float $last   = 0.0;
    private int $lastLen  = 0;
    private int $failures = 0;
    private float $every;

    public function __construct(private array $msg)
    {
        $this->every = 2.0;
    }

    public function feed(string $raw): void
    {
        if ($this->mode === 'silent' || $this->failures >= 3) {
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
        $this->show($body, false);
    }

    public function finish(string $answer): bool
    {
        if ($this->mid === null) {
            return false;
        }
        $html = AI::toHtml($answer);
        $r    = App::tg()->editMessage($this->msg['chat']['id'], $this->mid, $html);
        if (!empty($r['ok']) || stripos((string) ($r['description'] ?? ''), 'not modified') !== false) {
            return true;
        }
        $this->discard();
        return false;
    }

    public function discard(): void
    {
        if ($this->mid !== null) {
            App::tg()->call('deleteMessage', ['chat_id' => $this->msg['chat']['id'], 'message_id' => $this->mid], 8);
        }
        $this->mid      = null;
        $this->mode     = 'wait';
        $this->last     = 0.0;
        $this->lastLen  = 0;
        $this->failures = 0;
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
        if ($nl === false) {
            if (!$final && mb_strlen($t) < 48 && !preg_match('/^\p{Arabic}/u', $t)) {
                return null;
            }
        } elseif (AI::isGroqRoute()) {
            $t = AI::splitReaction($t, true)[1];
        } else {
            $parts = preg_split('/\r?\n/', $t, 2) ?: [$t];
            if (preg_match('/^\s*reaction\s*:?\s*(.*)$/iu', $parts[0])) {
                $t = ltrim($parts[1] ?? '');
            }
        }

        $t = ltrim($t);
        if ($t === '') {
            return null;
        }

        if (preg_match('/^[\s*`«»]*[A-Za-z]/u', $t)) {
            if (!$final && mb_strlen($t) < 16) {
                return null;
            }
            if (preg_match('/^[\s*`«»]*(ACTION|IMAGE)\s*[:：]/iu', $t)
                || preg_match('/^[\s*`«»]*(' . implode('|', Intent::NAMES) . ')\s*[|｜]/iu', $t)) {
                return false;
            }
        }
        return $t;
    }

    private function show(string $body, bool $final): void
    {
        $text = AI::clean($body);
        if ($text === '') {
            return;
        }
        $len = mb_strlen($text);
        $now = microtime(true);

        if ($this->mid === null) {
            if ($len < 12) {
                return;
            }
        } elseif ($now - $this->last < $this->every || $len - $this->lastLen < 10) {
            return;
        }

        if (substr_count($text, '```') % 2 === 1) {
            $text .= "\n```";
        }
        $html = AI::toHtml($text) . ' ▍';
        $tg   = App::tg();

        if ($this->mid === null) {
            $r = $tg->reply($this->msg, $html);
            if (!empty($r['ok']) && isset($r['result']['message_id'])) {
                $this->mid = (int) $r['result']['message_id'];
            } else {
                $this->failures++;
            }
        } else {
            $r = $tg->editMessage($this->msg['chat']['id'], $this->mid, $html);
            if (empty($r['ok']) && stripos((string) ($r['description'] ?? ''), 'not modified') === false) {
                $this->failures++;
            }
        }
        $this->last    = microtime(true);
        $this->lastLen = $len;
    }
}
