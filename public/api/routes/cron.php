<?php
declare(strict_types=1);

function route_cron_sync(): never
{
    $settings = JsonStore::read('settings.json', []);
    $expected = (string) ($settings['cron_secret'] ?? '');
    $provided = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        json_err('Invalid cron secret.', 403);
    }

    $results = [];
    foreach (xui_connections_read() as $connection) {
        if (!is_array($connection) || empty($connection['enabled']) || empty($connection['verified']) || empty($connection['load_clients'])) {
            continue;
        }
        try {
            $results[$connection['id']] = xui_sync_connection($connection);
        } catch (Throwable $error) {
            $results[$connection['id']] = ['error' => $error->getMessage()];
        }
    }
    $state = ['finished_at' => date(DATE_ATOM), 'connections' => $results];
    JsonStore::write('sync_state.json', $state);
    json_ok(['synchronized' => true, 'connections' => $results]);
}
