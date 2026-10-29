<?php
declare(strict_types=1);

/**
 * Send a notification to a configured Mattermost Incoming Webhook.
 * Configure MATTERMOST_WEBHOOK_URL in the server environment; never commit it.
 */
function mattermost_send(string $text, string $username = 'PQNET', string $channel = ''): void {
    $url = trim((string) getenv('MATTERMOST_WEBHOOK_URL'));
    if ($url === '') throw new RuntimeException('Mattermost webhook is not configured.');
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
        throw new RuntimeException('Mattermost webhook must be an HTTPS URL without embedded credentials.');
    }
    if (strlen($text) < 1 || strlen($text) > 10000) throw new InvalidArgumentException('Mattermost message must contain 1–10000 bytes.');
    $payload = ['text' => $text, 'username' => mb_substr($username, 0, 80)];
    if ($channel !== '' && preg_match('/^#[a-z0-9][a-z0-9_-]{0,79}$/i', $channel)) $payload['channel'] = $channel;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Mattermost webhook delivery failed (HTTP '.$status.'): '.substr($error ?: (is_string($response) ? $response : 'transport error'), 0, 200));
    }
}
