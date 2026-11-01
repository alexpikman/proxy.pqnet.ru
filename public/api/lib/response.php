<?php
declare(strict_types=1);

function json_ok(mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $message, int $status = 400, array $details = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $payload = ['success' => false, 'error' => $message];
    if ($details !== []) {
        $payload['details'] = $details;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function configure_cors(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $settings = JsonStore::read('settings.json', []);
    $allowed = $settings['cors_origins'] ?? [];
    if ($origin !== '' && is_array($allowed) && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Cron-Secret');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function configure_error_handling(): void
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        $entry = sprintf("[%s] %s in %s:%d\n", date(DATE_ATOM), $message, $file, $line);
        @file_put_contents(JsonStore::directory() . DIRECTORY_SEPARATOR . 'error.log', $entry, FILE_APPEND | LOCK_EX);
        return true;
    });
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_err('Invalid JSON request body.', 400);
    }
    if (!is_array($decoded) || !str_starts_with(ltrim($raw), '{')) {
        json_err('JSON request body must be an object.', 400);
    }
    return $decoded;
}
