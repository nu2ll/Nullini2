<?php

final class Kernel
{
    private const TTL = [
        'games'           => 7 * 86400,
        'calc'            => 7 * 86400,
        'owner'           => 14 * 86400,
        'whisper'         => 30 * 86400,
        'whisper_pending' => 86400,
        'whisper_recent'  => 120 * 86400,
        'whisper_group'   => 120 * 86400,
        'weather'         => 14 * 86400,
        'weather_pending' => 2 * 86400,
        'ai_memory'       => 2 * 86400,
        'cache'           => 3 * 86400,
        'join'            => 86400,
        'inline_ai'       => 86400,
        'dl'              => 2 * 86400,
        'rl'              => 3600,
        'dl_busy'         => 86400,
        'dl_quota'        => 2 * 86400,
        'dl_files'        => 30 * 86400,
        'love'            => 86400,
        'roast'           => 3600,
        'uname'           => 30 * 86400,
        'gw'              => 120 * 86400,
        'gw_wiz'          => 86400,
        'gw_draft'        => 86400,
    ];

    public static function handle(array $update): void
    {
        App::useBot((int) ($update['_bot'] ?? 0));
        try {
            if (Ban::block($update)) {
                return;
            }
            if (isset($update['message'])) {
                MessageHandler::handle($update['message']);
            } elseif (isset($update['inline_query'])) {
                InlineHandler::handle($update['inline_query']);
            } elseif (isset($update['chosen_inline_result'])) {
                ChosenHandler::handle($update['chosen_inline_result']);
            } elseif (isset($update['callback_query'])) {
                CallbackHandler::handle($update['callback_query']);
            } elseif (isset($update['guest_message'])) {
                GuestHandler::handle($update['guest_message']);
            } elseif (isset($update['my_chat_member'])) {
                MessageHandler::onMyChatMember($update['my_chat_member']);
            }
        } catch (\Throwable $e) {
            logError('update', '#' . ($update['update_id'] ?? '?') . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            if (isset($update['callback_query']['id'])) {
                App::tg()->answer($update['callback_query'], '😕 یه مشکلی پیش اومد، دوباره امتحان کن.');
            }
        }
    }

    public static function tick(): void
    {
        try {
            MathDuel::tick();
        } catch (\Throwable $e) {
            logError('tick', $e->getMessage());
        }
        App::useBot(0);
    }

    public static function janitor(): void
    {
        try {
            $removed = Store::cleanup(self::TTL);
            Users::cleanupStats();
            Giveaway::janitor();
        } catch (\Throwable $e) {
            logError('janitor', $e->getMessage());
            return;
        }
        if ($removed > 0) {
            logInfo('janitor', "removed {$removed} old records");
        }
        $log = NULLINI_DATA . '/error.log';
        if (is_file($log) && filesize($log) > 5 * 1024 * 1024) {
            @rename($log, $log . '.old');
        }
    }
}
