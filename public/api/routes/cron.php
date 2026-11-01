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
    json_ok(['synchronized' => true, 'result' => sync_xui_clients()]);
}
