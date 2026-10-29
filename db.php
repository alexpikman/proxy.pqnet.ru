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
