<?php

final class Railway
{
    private const API = 'https://backboard.railway.com/graphql/v2';

    private static function gql(string $query, array $vars = []): ?array
    {
        $token = trim((string) env('RAILWAY_TOKEN', ''));
        if ($token === '') {
            return null;
        }
        $r = Http::postJson(self::API, ['query' => $query, 'variables' => (object) $vars], ['Project-Access-Token: ' . $token], 20);
        if ($r['code'] !== 200 || !is_array($r['data'])) {
            logError('web', 'railway api HTTP ' . $r['code'] . ': ' . mb_substr((string) $r['raw'], 0, 200));
            return null;
        }
        if (!empty($r['data']['errors'])) {
            logError('web', 'railway api: ' . mb_substr((string) ($r['data']['errors'][0]['message'] ?? json_encode($r['data']['errors'])), 0, 200));
        }
        return $r['data']['data'] ?? null;
    }

    public static function ensureDomain(int $port): string
    {
        $d = trim((string) env('RAILWAY_PUBLIC_DOMAIN', ''));
        if ($d !== '') {
            Store::set('meta', 'public_domain', $d);
            return $d;
        }
        $saved = (string) (Store::get('meta', 'public_domain') ?? '');
        if ($saved !== '') {
            return $saved;
        }
        $service = trim((string) env('RAILWAY_SERVICE_ID', ''));
        $envId   = trim((string) env('RAILWAY_ENVIRONMENT_ID', ''));
        if ($service === '' || $envId === '') {
            return '';
        }
        $data = self::gql(
            'mutation ($input: ServiceDomainCreateInput!) { serviceDomainCreate(input: $input) { domain } }',
            ['input' => ['serviceId' => $service, 'environmentId' => $envId, 'targetPort' => $port]]
        );
        $d = (string) ($data['serviceDomainCreate']['domain'] ?? '');
        if ($d !== '') {
            Store::set('meta', 'public_domain', $d);
        }
        return $d;
    }
}
