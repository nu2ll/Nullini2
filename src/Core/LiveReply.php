<?php

/**
 * نمایش زنده‌ی جواب هوش مصنوعی: همین‌طور که متن می‌رسه، یه پیام می‌فرسته و مدام ویرایشش می‌کنه.
 * متن خام از AI::reply (گزینه‌ی on_text) می‌رسه؛ خط REACTION و دستورهای ACTION/IMAGE نمایش داده نمی‌شن.
 */
final class LiveReply
{
    private ?int $mid     = null;
    private string $mode  = 'wait';   // wait | live | silent
    private int $lastLen  = 0;
    private int $failures = 0;

    public function __construct(private array $msg, private ?string $imid = null, private string $head = '', private bool $reaction = true)
    {
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
        if ($this->reaction) {
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

        $started = $this->mid !== null || ($this->imid !== null && $this->lastLen > 0);
        if (!$started) {
            if ($len < 12) {
                return;
            }
        } elseif ($len === $this->lastLen) {
            return;
        }

        if ($this->imid !== null && $len > 3500) {
            $text = mb_substr($text, 0, 3500);
        }
        if (substr_count($text, '```') % 2 === 1) {
            $text .= "\n```";
        }
        $html = AI::toHtml($text) . ' ▍';
        $tg   = App::tg();

        if ($this->imid !== null) {
            $r = $tg->editInline($this->imid, $this->head . $html);
            if (empty($r['ok']) && stripos((string) ($r['description'] ?? ''), 'not modified') === false) {
                $this->failures++;
            }
        } elseif ($this->mid === null) {
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
        $this->lastLen = $len;
    }
}
