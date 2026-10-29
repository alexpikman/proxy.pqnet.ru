<?php
require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user'])) {
    exit('Unauthorized');
}

$email = strtolower($_SESSION['user']);
$format = $_GET['format'] ?? 'txt';

$clientsData = xui_api_request('/panel/api/inbounds/list');
$clientVlessKey = '';

if ($clientsData && isset($clientsData['obj'])) {
    foreach ($clientsData['obj'] as $inbound) {
        $settings = is_string($inbound['settings']) ? json_decode($inbound['settings'], true) : $inbound['settings'];
        if (isset($settings['clients'])) {
            foreach ($settings['clients'] as $c) {
                if (isset($c['email']) && strtolower(trim($c['email'])) === $email) {
                    $streamSettings = is_string($inbound['streamSettings']) ? json_decode($inbound['streamSettings'], true) : $inbound['streamSettings'];
                    $network = isset($streamSettings['network']) ? $streamSettings['network'] : 'tcp';
                    $clientVlessKey = "vless://" . $c['id'] . "@" . parse_url(XUI_HOST, PHP_URL_HOST) . ":" . $inbound['port'] . "?type=" . $network . "#" . urlencode($c['email']);
                    break 2;
                }
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
