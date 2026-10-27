<?php

final class Ban
{
    public static function is(int $uid): bool
    {
        return $uid > 0 && !App::isAdmin($uid) && Store::get('ban', (string) $uid) !== null;
    }

    public static function add(int $uid, int $by): bool
    {
        if ($uid <= 0 || App::isAdmin($uid)) {
            return false;
        }
        Store::set('ban', (string) $uid, ['at' => time(), 'by' => $by]);
        return true;
    }

    public static function remove(int $uid): bool
    {
        if (Store::get('ban', (string) $uid) === null) {
            return false;
        }
        Store::delete('ban', (string) $uid);
        return true;
    }

    public static function byUsername(string $username): int
    {
        $u = strtolower(ltrim(trim($username), '@'));
        if ($u === '' || !preg_match('/^[a-z0-9_]{3,64}$/', $u)) {
            return 0;
        }
        $id = (int) Db::value('SELECT id FROM users WHERE LOWER(username) = ?', [$u]);
        return $id > 0 ? $id : (int) Store::get('uname', $u, 0);
    }

    public static function all(): array
    {
        return array_map('intval', Db::run("SELECT k FROM kv WHERE ns = 'ban' ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function senderId(array $update): int
    {
        foreach (['message', 'inline_query', 'chosen_inline_result', 'callback_query'] as $k) {
            if (isset($update[$k])) {
                return (int) ($update[$k]['from']['id'] ?? 0);
            }
        }
        if (isset($update['guest_message'])) {
            $g = $update['guest_message'];
            return (int) ($g['guest_bot_caller_user']['id'] ?? $g['from']['id'] ?? 0);
        }
        return 0;
    }

    public static function block(array $update): bool
    {
        $uid = self::senderId($update);
        if (!self::is($uid)) {
            return false;
        }
        $tg = App::tg();
        if (isset($update['callback_query'])) {
            $tg->answer($update['callback_query']);
        } elseif (isset($update['inline_query']['id'])) {
            $tg->call('answerInlineQuery', ['inline_query_id' => $update['inline_query']['id'], 'results' => [], 'cache_time' => 0, 'is_personal' => true]);
        }
        return true;
    }
}
