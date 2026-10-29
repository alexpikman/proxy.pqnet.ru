<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('XUI_HOST', 'http://144.31.126.125:1905');
define('XUI_PATH', '/p8NRIFKUpnIQ8Ntt0A');
define('XUI_API_KEY', 'eVAl45jCZFpKIUqYvTTQvir8x4CkhtOjGr2MX5NTuYea5wZP');
define('ADMIN_USER', 'admin');
define('ADMIN_PASSWORD', '19201905');

global $last_api_error;
$last_api_error = ['url' => '', 'http_code' => '', 'curl_error' => '', 'response' => ''];

function xui_api_request($endpoint, $data = [], $method = 'GET') {
    global $last_api_error;
    
    // Проверяем оба варианта пути (с префиксом панели и напрямую)
    $urls = [
        XUI_HOST . XUI_PATH . $endpoint,
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
        $curlError = curl_error($ch);
        curl_close($ch);

        $last_api_error = [
            'url' => $url,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => $response
        ];

        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
    }
    
    return null;
}
