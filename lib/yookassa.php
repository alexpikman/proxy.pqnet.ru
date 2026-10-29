<?php
declare(strict_types=1);

function yookassa_config(): array
{
    $shopId = trim((string) getenv('YOOKASSA_SHOP_ID'));
    $secret = trim((string) getenv('YOOKASSA_SECRET_KEY'));
    $returnUrl = trim((string) getenv('YOOKASSA_RETURN_URL'));
    if ($shopId === '' || $secret === '' || $returnUrl === '') {
        throw new RuntimeException('Платёжный сервис пока не настроен.');
    }
    if (filter_var($returnUrl, FILTER_VALIDATE_URL) === false || parse_url($returnUrl, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('YOOKASSA_RETURN_URL должен быть корректным HTTPS URL.');
    }
    return ['shop_id' => $shopId, 'secret_key' => $secret, 'return_url' => $returnUrl];
}

function yookassa_request(string $method, string $path, ?array $payload = null, ?string $idempotenceKey = null): array
{
    $config = yookassa_config();
    $ch = curl_init('https://api.yookassa.ru/v3/' . ltrim($path, '/'));
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($idempotenceKey !== null) $headers[] = 'Idempotence-Key: ' . $idempotenceKey;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => $config['shop_id'] . ':' . $config['secret_key'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $error !== '' || $status < 200 || $status >= 300) {
        error_log('YooKassa API request failed; HTTP=' . $status . '; curl_error=' . ($error !== '' ? 'yes' : 'no'));
        throw new RuntimeException('Не удалось выполнить запрос к платёжному сервису.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) throw new RuntimeException('Платёжный сервис вернул некорректный ответ.');
    return $decoded;
}

function yookassa_ensure_schema(PDO $db): void
{
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT NOT NULL UNIQUE,
            user_email TEXT NOT NULL, amount_kopecks INTEGER NOT NULL CHECK (amount_kopecks > 0),
            currency TEXT NOT NULL DEFAULT 'RUB', status TEXT NOT NULL DEFAULT 'pending',
            provider_payment_id TEXT UNIQUE, confirmation_url TEXT, credited_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS payment_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, provider_event_id TEXT NOT NULL UNIQUE,
            provider_payment_id TEXT NOT NULL, event_type TEXT NOT NULL,
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_email TEXT NOT NULL,
            payment_order_id TEXT NOT NULL UNIQUE, amount_kopecks INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        return;
    }
    if ($driver === 'pgsql') {
        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id BIGSERIAL PRIMARY KEY, order_id VARCHAR(64) NOT NULL UNIQUE,
            user_email VARCHAR(320) NOT NULL, amount_kopecks BIGINT NOT NULL CHECK (amount_kopecks > 0),
            currency CHAR(3) NOT NULL DEFAULT 'RUB', status VARCHAR(32) NOT NULL DEFAULT 'pending',
            provider_payment_id VARCHAR(128) UNIQUE, confirmation_url TEXT, credited_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS payment_events (
            id BIGSERIAL PRIMARY KEY, provider_event_id VARCHAR(255) NOT NULL UNIQUE,
            provider_payment_id VARCHAR(128) NOT NULL, event_type VARCHAR(64) NOT NULL,
            received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_ledger (
            id BIGSERIAL PRIMARY KEY, user_email VARCHAR(320) NOT NULL,
            payment_order_id VARCHAR(64) NOT NULL UNIQUE, amount_kopecks BIGINT NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        return;
    }
    throw new RuntimeException('Для платежей поддерживаются только SQLite и PostgreSQL.');
}
