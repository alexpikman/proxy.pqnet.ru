<?php
declare(strict_types=1);

/**
 * Minimal 3X-UI REST client. Configure XUI_BASE_URL and XUI_API_TOKEN in the
 * server environment; XUI_BASE_URL is the panel origin (including any custom
 * panel path, but not /panel/api). TLS verification is intentionally enabled.
 */
function xui_base_url(): string
{
    $base = rtrim((string) getenv('XUI_BASE_URL'), '/');
    if ($base === '' || !preg_match('~^https://~i', $base)) {
        throw new RuntimeException('3X-UI must be configured with an HTTPS XUI_BASE_URL.');
    }
    return $base;
}

function xui_request(string $path, string $method = 'GET', ?array $payload = null): array
{
    $token = (string) getenv('XUI_API_TOKEN');
    if ($token === '') {
        throw new RuntimeException('XUI_API_TOKEN is not configured.');
    }
    if (!str_starts_with($path, '/panel/api/')) {
        throw new InvalidArgumentException('Invalid 3X-UI API path.');
    }

    $url = xui_base_url() . $path;
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body)) {
        throw new RuntimeException('3X-UI request transport failed: ' . ($error !== '' ? $error : 'unknown error'));
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('3X-UI returned a non-JSON response (HTTP ' . $status . ').');
    }
    if ($status < 200 || $status >= 300 || ($decoded['success'] ?? false) !== true) {
        $message = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : '3X-UI request failed';
        throw new RuntimeException('3X-UI rejected the request (HTTP ' . $status . '): ' . mb_substr($message, 0, 300));
    }
    return $decoded;
}

/** Return the full modern 3X-UI client record, or fail closed if it cannot be found. */
function xui_get_client(string $email): array
{
    $response = xui_request('/panel/api/clients/get/' . rawurlencode($email));
    $client = $response['obj']['client'] ?? $response['obj'] ?? null;
    if (!is_array($client) || strtolower((string)($client['email'] ?? '')) !== strtolower($email)) {
        throw new DomainException('Клиент не найден в 3X-UI или ответ панели имеет неизвестный формат.');
    }
    return $client;
}

/**
 * Extend one client's expiry using the documented bulk adjustment API.
 * Returns false only for a confirmed API rejection; transport/verification
 * failures throw, because the panel may have applied the request despite timeout.
 */
function xui_extend_client(string $email, int $days): array
{
    if ($days < 1 || $days > 3650) {
        throw new InvalidArgumentException('Количество дней вне допустимого диапазона.');
    }
    $client = xui_get_client($email);
    $expiry = (int)($client['expiryTime'] ?? 0);
    if ($expiry === 0) {
        throw new DomainException('У клиента в 3X-UI безлимитный срок; продление не требуется.');
    }

    // The 3X-UI bulk-adjust endpoint applies days to expired accounts as well.
    return xui_request('/panel/api/clients/bulkAdjust', 'POST', [
        'emails' => [$email],
        'addDays' => $days,
    ]);
}
