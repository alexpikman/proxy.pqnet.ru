<?php
declare(strict_types=1);

function route_user_balance(): never
{
    $user = require_role('user');
    $balance = get_user_balance($user);
    json_ok([
        'balance' => $balance,
        'currency' => (string) (JsonStore::read('settings.json', [])['currency'] ?? 'COIN'),
        'tariff_id' => $user['tariff_id'] ?? null,
        'admin_blocked' => !empty($user['admin_blocked']),
        'access_enabled' => $balance > 0 && empty($user['admin_blocked']) && empty($user['deleted'])
    ]);
}

function route_user_operations(): never
{
    $user = require_role('user');
    $page = input_pagination();
    $items = array_values(array_filter(
        JsonStore::read('coins.json', []),
        static fn($item): bool => is_array($item)
            && (string) ($item['user_id'] ?? '') === (string) $user['id']
    ));
    usort($items, static fn(array $a, array $b): int =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    json_ok(paginate_array($items, $page['page'], $page['limit']));
}

function route_user_proxy(): never
{
    $user = require_role('user');
    if (empty($user['uuid']) || (int) ($user['inbound_id'] ?? 0) < 1) {
        json_err('Proxy client is not linked.', 404);
    }
    try {
        $xui = new XuiClient();
        $client = null;
        foreach ($xui->getClients((int) $user['inbound_id']) as $candidate) {
            if (isset($candidate['id']) && hash_equals((string) $user['uuid'], (string) $candidate['id'])) {
                $client = $candidate;
                break;
            }
        }
        if ($client === null) {
            json_err('Proxy client not found in 3x-ui.', 404);
        }
        json_ok([
            'email' => $client['email'] ?? $user['login'],
            'enable' => (bool) ($client['enable'] ?? false),
            'expiryTime' => $client['expiryTime'] ?? 0,
            'totalGB' => $client['totalGB'] ?? 0,
            'limitIp' => $client['limitIp'] ?? 0,
            'subId' => $client['subId'] ?? null,
            'reset' => $client['reset'] ?? 0,
            'access_enabled' => get_user_balance($user) > 0
                && empty($user['admin_blocked'])
                && empty($user['deleted'])
        ]);
    } catch (Throwable $e) {
        json_err('Cannot retrieve proxy client information.', 502, ['reason' => $e->getMessage()]);
    }
}
