<?php

final class ChosenHandler
{
    public const OWNED = ['calc', 'coin', 'fal', 'pass'];

    private const GAMES = ['rps' => 'rps', 'ttt' => 'ttt', 'c4' => 'c4', 'math' => 'math', 'chess' => 'chess'];

    public static function handle(array $c): void
    {
        $id   = (string) ($c['result_id'] ?? '');
        $imid = $c['inline_message_id'] ?? null;
        $from = $c['from'] ?? [];
        if (!$imid || empty($from['id'])) {
            return;
        }

        Users::hit('inline:' . (strstr($id, '_', true) ?: $id));

        if (in_array($id, self::OWNED, true)) {
            Store::set('owner', $imid, (int) $from['id']);
        }
        if ($id === 'fal') {
            Tools::showHafez($imid);
        }
        if (isset(self::GAMES[$id])) {
            GameKit::save($imid, GameKit::fresh(self::GAMES[$id], GameKit::player($from)));
        }
        if (preg_match('/^(ai|tr)q_(\w+)$/', $id, $m)) {
            InlineHandler::resolveDeferred($m[2], $imid);
        }
        if (str_starts_with($id, 'rs_')) {
            Roast::resolve(substr($id, 3), $imid);
        }
        if (str_starts_with($id, 'lv_')) {
            Love::resolve(substr($id, 3), $imid);
        }
        if (str_starts_with($id, 'gwi_')) {
            Giveaway::onChosen(substr($id, 4), $imid, $from);
        }
        if (str_starts_with($id, 'wh_')) {
            Whisper::bind(substr($id, 3), $imid);
        }
        if (str_starts_with($id, 'wt_')) {
            $city = Store::get('weather_pending', substr($id, 3));
            if (is_string($city)) {
                Store::set('weather', $imid, $city);
            }
        }
    }
}
