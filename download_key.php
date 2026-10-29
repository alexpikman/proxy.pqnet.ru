<?php
require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user'])) {
    exit('Unauthorized');
}

$email = strtolower($_SESSION['user']);
$format = $_GET['format'] ?? 'txt';

$clientsData = xui_api_request('/panel/api/inbounds/list');
$clientVlessKey = '';

if ($clientsData && isset($clientsData['obj']) && is_array($clientsData['obj'])) {
    foreach ($clientsData['obj'] as $inbound) {
        $settings = is_string($inbound['settings'] ?? null) ? json_decode($inbound['settings'], true) : ($inbound['settings'] ?? []);
        if (!is_array($settings) || !isset($settings['clients']) || !is_array($settings['clients'])) continue;
        foreach ($settings['clients'] as $client) {
            if (isset($client['email']) && strtolower(trim($client['email'])) === $email) {
                $clientVlessKey = build_vless_share_uri($inbound, $client);
                break 2;
            }
        }
    }
}

if (!$clientVlessKey) {
    exit('Key not found');
}

$safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $email);

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $safeName . '.json"');
    echo json_encode(['vless_key' => $clientVlessKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $safeName . '.txt"');
    echo $clientVlessKey;
}
exit;
