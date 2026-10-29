<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('XUI_HOST', 'http://144.31.126.125:1905');
define('XUI_API_KEY', 'eVAl45jCZFpKIUqYvTTQvir8x4CkhtOjGr2MX5NTuYea5wZP');
define('ADMIN_USER', 'admin');
define('ADMIN_PASSWORD', '19201905');

$dbFile = __DIR__ . '/database.sqlite';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Создаем таблицу, если ее вообще нет
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL
)");

// Безопасно добавляем недостающие колонки, если таблица уже существовала
$columnsToAdd = [
    "phone TEXT DEFAULT ''",
    "password_changed INTEGER DEFAULT 0",
    "last_login DATETIME DEFAULT NULL",
    "custom_content TEXT DEFAULT ''"
];

foreach ($columnsToAdd as $col) {
    try {
        $db->exec("ALTER TABLE users ADD COLUMN $col");
    } catch (PDOException $e) {
        // Колонка уже существует — пропускаем ошибку
    }
}

$db->exec("CREATE TABLE IF NOT EXISTS apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    logo TEXT DEFAULT '',
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    added_date TEXT DEFAULT CURRENT_DATE
)");

function xui_api_request($endpoint, $data = [], $method = 'GET') {
    $urls = [
        XUI_HOST . '/p8NRIFKUpnIQ8Ntt0A' . $endpoint,
        XUI_HOST . $endpoint
    ];

    foreach ($urls as $url) {
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . XUI_API_KEY
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
    }
    return null;
}
function build_vless_share_uri(array $inbound, array $client) {
    $host = parse_url(XUI_HOST, PHP_URL_HOST);
    $port = (int)($inbound['port'] ?? 0); $uuid = trim((string)($client['id'] ?? ''));
    if (!$host || !$port || !$uuid) return '';
    $decode = static function ($v) { if (is_string($v)) $v = json_decode($v, true); return is_array($v) ? $v : []; };
    $s = $decode($inbound['streamSettings'] ?? []); $net = (string)($s['network'] ?? 'tcp'); $sec = (string)($s['security'] ?? 'none');
    $q = ['encryption' => 'none']; if (!empty($client['flow'])) $q['flow'] = (string)$client['flow'];
    if ($sec === 'reality') { $r = $decode($s['realitySettings'] ?? []); $rs = $decode($r['settings'] ?? []); $q['security']='reality'; $q['fp']=(string)($rs['fingerprint'] ?? 'firefox'); $names=$r['serverNames'] ?? []; if (is_array($names) && !empty($names[0])) $q['sni']=(string)$names[0]; elseif (!empty($r['serverName'])) $q['sni']=(string)$r['serverName']; $pbk=$rs['publicKey'] ?? ($r['publicKey'] ?? ''); if ($pbk!=='') $q['pbk']=(string)$pbk; $ids=$r['shortIds'] ?? []; if (is_array($ids) && !empty($ids[0])) $q['sid']=(string)$ids[0]; $spx=$rs['spiderX'] ?? ($r['spiderX'] ?? ''); if ($spx!=='') $q['spx']=(string)$spx; }
    elseif ($sec === 'tls') { $t=$decode($s['tlsSettings'] ?? []); $q['security']='tls'; if (!empty($t['serverName'])) $q['sni']=(string)$t['serverName']; if (!empty($t['fingerprint'])) $q['fp']=(string)$t['fingerprint']; if (!empty($t['alpn']) && is_array($t['alpn'])) $q['alpn']=implode(',', $t['alpn']); }
    if ($net === 'ws') { $w=$decode($s['wsSettings'] ?? []); if (!empty($w['path'])) $q['path']=(string)$w['path']; if (!empty($w['headers']['Host'])) $q['host']=(string)$w['headers']['Host']; }
    elseif ($net === 'grpc') { $g=$decode($s['grpcSettings'] ?? []); if (!empty($g['serviceName'])) $q['serviceName']=(string)$g['serviceName']; if (!empty($g['authority'])) $q['authority']=(string)$g['authority']; if (!empty($g['mode'])) $q['mode']=(string)$g['mode']; }
    elseif ($net === 'tcp') { $t=$decode($s['tcpSettings'] ?? []); $h=$decode($t['header'] ?? []); if (!empty($h['type']) && $h['type']!=='none') $q['headerType']=(string)$h['type']; }
    elseif ($net === 'httpupgrade') { $u=$decode($s['httpupgradeSettings'] ?? []); if (!empty($u['path'])) $q['path']=(string)$u['path']; if (!empty($u['host'])) $q['host']=(string)$u['host']; }
    // Keep the share URI in the same conventional shape as the Xray Reality example.
    $q['type'] = $net;
    $label=(string)($client['email'] ?? 'PQNET'); return 'vless://' . rawurlencode($uuid) . '@' . $host . ':' . $port . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) . '#' . rawurlencode($label);
}
