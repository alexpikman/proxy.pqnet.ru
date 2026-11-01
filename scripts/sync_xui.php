<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/lib/store.php';
require_once dirname(__DIR__) . '/public/api/lib/validate.php';
require_once dirname(__DIR__) . '/public/api/lib/xui.php';
require_once dirname(__DIR__) . '/public/api/lib/logic.php';
require_once dirname(__DIR__) . '/public/api/routes/xui_connections.php';

JsonStore::init();

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

JsonStore::write('sync_state.json', [
    'finished_at' => date(DATE_ATOM),
    'connections' => $results
]);

echo json_encode(['synchronized' => true, 'connections' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
