<?php
declare(strict_types=1);

function xui_connection_key(): string
{
    $path = JsonStore::directory() . DIRECTORY_SEPARATOR . 'xui_connections.key';
    JsonStore::ensureDirectory();
    if (!is_file($path)) {
        $handle = fopen($path, 'x');
        if ($handle === false) {
            if (!is_file($path)) {
                throw new RuntimeException('Cannot initialize 3x-ui encryption key.');
            }
        } else {
            fwrite($handle, random_bytes(32));
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
            chmod($path, 0600);
        }
    }
    $key = file_get_contents($path);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('Invalid 3x-ui encryption key.');
    }
    return $key;
}

function xui_encrypt_token(string $token): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', xui_connection_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Cannot encrypt 3x-ui API token.');
    }
    return base64_encode($nonce . $tag . $ciphertext);
}

function xui_decrypt_token(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('Stored 3x-ui token is invalid.');
    }
    $token = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', xui_connection_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if (!is_string($token)) {
        throw new RuntimeException('Cannot decrypt 3x-ui API token.');
    }
    return $token;
}

function xui_connections_read(): array
{
    $items = JsonStore::read('xui_connections.json', []);
    return is_array($items) ? $items : [];
}

function xui_connection_find(string $id): ?array
{
    foreach (xui_connections_read() as $item) {
        if (is_array($item) && hash_equals((string) ($item['id'] ?? ''), $id)) {
            return $item;
        }
    }
    return null;
}

function xui_connection_public(array $item): array
{
    unset($item['token_encrypted']);
    $item['has_token'] = !empty($item['has_token']);
    return $item;
}

function xui_connection_client(array $item): XuiClient
{
    return new XuiClient([
        'xui_host' => (string) ($item['base_url'] ?? ''),
        'xui_token' => xui_decrypt_token((string) ($item['token_encrypted'] ?? '')),
        'xui_timeout' => (int) ($item['timeout_seconds'] ?? 15)
    ]);
}

function xui_connection_save(array $item): void
{
    JsonStore::update('xui_connections.json', static function (&$items) use ($item): void {
        if (!is_array($items)) {
            $items = [];
        }
        $found = false;
        foreach ($items as $index => $current) {
            if (is_array($current) && (string) ($current['id'] ?? '') === (string) $item['id']) {
                $items[$index] = $item;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $items[] = $item;
        }
    }, []);
}

function xui_sync_connection(array $connection): array
{
    $client = xui_connection_client($connection);
    $inbounds = $client->listInbounds();
    $remote = [];
    foreach ($inbounds as $inbound) {
        $inboundId = (int) ($inbound['id'] ?? 0);
        if ($inboundId < 1) {
            continue;
        }
        foreach ($client->getClients($inboundId) as $remoteClient) {
            $uuid = trim((string) ($remoteClient['id'] ?? ''));
            $email = trim((string) ($remoteClient['email'] ?? ''));
            if ($uuid === '' || $email === '') {
                continue;
            }
            $remote[$inboundId . ':' . $uuid] = [
                'inbound_id' => $inboundId,
                'uuid' => $uuid,
                'email' => $email,
                'enable' => (bool) ($remoteClient['enable'] ?? false),
                'client' => $remoteClient
            ];
        }
    }

    $stats = ['inbounds' => count($inbounds), 'remote_clients' => count($remote), 'imported' => 0, 'updated' => 0, 'missing' => 0, 'matched' => 0, 'panel_only' => 0, 'service_only' => 0, 'conflicts' => 0];
    $connectionId = (string) $connection['id'];

    JsonStore::update('users.json', static function (&$users) use ($remote, $connectionId, &$stats): void {
        if (!is_array($users)) {
            $users = [];
        }
        $seen = [];
        foreach ($remote as $key => $entry) {
            $index = null;
            foreach ($users as $i => $user) {
                if (!is_array($user)) {
                    continue;
                }
                if ((string) ($user['xui_connection_id'] ?? '') === $connectionId
                    && (int) ($user['xui_inbound_id'] ?? $user['inbound_id'] ?? 0) === $entry['inbound_id']
                    && hash_equals((string) ($user['xui_uuid'] ?? $user['uuid'] ?? ''), $entry['uuid'])) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                $id = generate_id();
                $users[] = [
                    'id' => $id,
                    'login' => $entry['email'],
                    'email' => $entry['email'],
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
                    'role' => 'user',
                    'balance' => 0,
                    'admin_blocked' => false,
                    'deleted' => false,
                    'tariff_id' => null,
                    'uuid' => $entry['uuid'],
                    'inbound_id' => $entry['inbound_id'],
                    'xui_connection_id' => $connectionId,
                    'xui_inbound_id' => $entry['inbound_id'],
                    'xui_uuid' => $entry['uuid'],
                    'xui_enabled' => $entry['enable'],
                    'xui_present' => true,
                    'xui_last_seen_at' => date(DATE_ATOM),
                    'created_at' => date(DATE_ATOM),
                    'updated_at' => date(DATE_ATOM)
                ];
                $stats['imported']++;
                $seen[$key] = true;
                continue;
            }

            $users[$index]['login'] = $entry['email'];
            $users[$index]['email'] = $entry['email'];
            $users[$index]['uuid'] = $entry['uuid'];
            $users[$index]['inbound_id'] = $entry['inbound_id'];
            $users[$index]['xui_connection_id'] = $connectionId;
            $users[$index]['xui_inbound_id'] = $entry['inbound_id'];
            $users[$index]['xui_uuid'] = $entry['uuid'];
            $users[$index]['xui_enabled'] = $entry['enable'];
            $users[$index]['xui_present'] = true;
            $users[$index]['xui_last_seen_at'] = date(DATE_ATOM);
            $users[$index]['updated_at'] = date(DATE_ATOM);
            $stats['updated']++;
            $seen[$key] = true;
        }

        foreach ($users as &$user) {
            if (!is_array($user) || (string) ($user['xui_connection_id'] ?? '') !== $connectionId) {
                continue;
            }
            $key = (int) ($user['xui_inbound_id'] ?? $user['inbound_id'] ?? 0) . ':' . (string) ($user['xui_uuid'] ?? $user['uuid'] ?? '');
            if (isset($seen[$key])) {
                $stats['matched']++;
                continue;
            }
            if (!empty($user['xui_present'])) {
                $user['xui_present'] = false;
                $user['xui_missing_at'] = date(DATE_ATOM);
                $stats['missing']++;
            }
            $stats['service_only']++;
        }
        unset($user);
        $stats['panel_only'] = max(0, $stats['remote_clients'] - $stats['matched']);
    }, []);

    return $stats;
}

function xui_compare_connection(array $connection): array
{
    $client = xui_connection_client($connection);
    $remoteKeys = [];
    $inbounds = $client->listInbounds();
    foreach ($inbounds as $inbound) {
        $inboundId = (int) ($inbound['id'] ?? 0);
        if ($inboundId < 1) {
            continue;
        }
        foreach ($client->getClients($inboundId) as $remoteClient) {
            $uuid = trim((string) ($remoteClient['id'] ?? ''));
            if ($uuid !== '') {
                $remoteKeys[$inboundId . ':' . $uuid] = true;
            }
        }
    }

    $localKeys = [];
    foreach (JsonStore::read('users.json', []) as $user) {
        if (!is_array($user) || (string) ($user['xui_connection_id'] ?? '') !== (string) $connection['id']) {
            continue;
        }
        $key = (int) ($user['xui_inbound_id'] ?? $user['inbound_id'] ?? 0) . ':' . (string) ($user['xui_uuid'] ?? $user['uuid'] ?? '');
        $localKeys[$key] = true;
    }

    $matched = count(array_intersect_key($remoteKeys, $localKeys));
    return [
        'inbounds' => count($inbounds),
        'remote_clients' => count($remoteKeys),
        'matched' => $matched,
        'panel_only' => count(array_diff_key($remoteKeys, $localKeys)),
        'service_only' => count(array_diff_key($localKeys, $remoteKeys)),
        'conflicts' => 0,
        'changed' => false
    ];
}

function route_admin_xui_connections(string $id, string $action, string $method, array $body): never
{
    require_role('admin');

    if ($id === '' && $action === '' && $method === 'GET') {
        json_ok(['items' => array_map('xui_connection_public', xui_connections_read())]);
    }

    if ($id === '' && $action === '' && $method === 'POST') {
        $name = input_string($body, 'name', true, 100);
        $baseUrl = input_string($body, 'base_url', true, 2048);
        $token = input_string($body, 'api_token', true, 4096);
        $timeout = input_integer($body, 'timeout_seconds', true, 3, 60);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['https'], true)) {
            json_err('URL панели должен использовать HTTPS.', 422);
        }
        $item = [
            'id' => generate_id(), 'name' => $name, 'base_url' => rtrim($baseUrl, '/'),
            'token_encrypted' => xui_encrypt_token($token), 'has_token' => true,
            'timeout_seconds' => $timeout, 'enabled' => true, 'is_active' => false,
            'verified' => false, 'last_test_ok' => null, 'last_test_at' => null,
            'last_test_error' => null, 'load_clients' => false, 'load_inbounds' => false,
            'load_logs' => false, 'load_telemetry' => false, 'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM)
        ];
        xui_connection_save($item);
        json_ok(['item' => xui_connection_public($item)], 201);
    }

    $item = xui_connection_find($id);
    if ($item === null) {
        json_err('Подключение не найдено.', 404);
    }

    if ($action === '' && $method === 'PUT') {
        $item['name'] = input_string($body, 'name', true, 100);
        $url = input_string($body, 'base_url', true, 2048);
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            json_err('URL панели должен использовать HTTPS.', 422);
        }
        $item['base_url'] = rtrim($url, '/');
        $item['timeout_seconds'] = input_integer($body, 'timeout_seconds', true, 3, 60);
        $token = input_string($body, 'api_token', false, 4096);
        if ($token !== null && $token !== '') {
            $item['token_encrypted'] = xui_encrypt_token($token);
            $item['has_token'] = true;
        }
        $item['verified'] = false;
        $item['last_test_ok'] = null;
        $item['updated_at'] = date(DATE_ATOM);
        xui_connection_save($item);
        json_ok(['item' => xui_connection_public($item)]);
    }

    if ($action === 'test' && $method === 'POST') {
        try {
            $test = xui_connection_client($item)->testConnection();
            $item['verified'] = true;
            $item['last_test_ok'] = true;
            $item['last_test_error'] = null;
            $item['last_test_at'] = date(DATE_ATOM);
            $item['panel_inbounds'] = (int) ($test['inbounds_count'] ?? 0);
            xui_connection_save($item);
            json_ok(['connected' => true, 'message' => 'Соединение установлено.', 'item' => xui_connection_public($item)]);
        } catch (Throwable $error) {
            $item['verified'] = false;
            $item['last_test_ok'] = false;
            $item['last_test_error'] = $error->getMessage();
            $item['last_test_at'] = date(DATE_ATOM);
            xui_connection_save($item);
            json_ok(['connected' => false, 'message' => $error->getMessage(), 'item' => xui_connection_public($item)]);
        }
    }

    if ($action === 'sync' && $method === 'POST') {
        if (empty($item['verified']) || empty($item['enabled'])) {
            json_err('Сначала проверьте и включите подключение.', 409);
        }
        $result = xui_sync_connection($item);
        json_ok(['counts' => $result, 'read_only' => true]);
    }

    if ($action === 'settings' && $method === 'PATCH') {
        if (empty($item['verified'])) {
            json_err('Сначала успешно проверьте соединение.', 409);
        }
        foreach (['enabled', 'is_active', 'load_clients', 'load_inbounds', 'load_logs', 'load_telemetry'] as $key) {
            if (array_key_exists($key, $body)) {
                if (!is_bool($body[$key])) {
                    json_err('Некорректное значение настройки: ' . $key, 422);
                }
                $item[$key] = $body[$key];
            }
        }
        if (!empty($item['is_active'])) {
            JsonStore::update('xui_connections.json', static function (&$items) use (&$item): void {
                foreach ($items as &$candidate) {
                    if (is_array($candidate)) {
                        $candidate['is_active'] = (string) ($candidate['id'] ?? '') === (string) $item['id'];
                    }
                }
                unset($candidate);
                foreach ($items as $candidate) {
                    if (is_array($candidate) && (string) ($candidate['id'] ?? '') === (string) $item['id']) {
                        $item = $candidate;
                        break;
                    }
                }
            }, []);
        }
        $item['updated_at'] = date(DATE_ATOM);
        xui_connection_save($item);
        json_ok(['item' => xui_connection_public($item)]);
    }

    json_err('Endpoint not found.', 404);
}

function route_admin_xui_connection_users(string $connectionId, string $method, array $body): never
{
    require_role('admin');
    $connection = xui_connection_find($connectionId);
    if ($connection === null || empty($connection['load_clients'])) {
        json_err('Источник клиентов не найден или загрузка клиентов отключена.', 404);
    }

    if ($method === 'GET') {
        $search = trim((string) ($_GET['search'] ?? ''));
        $items = [];
        foreach (JsonStore::read('users.json', []) as $user) {
            if (!is_array($user) || !empty($user['deleted'])
                || (string) ($user['xui_connection_id'] ?? '') !== $connectionId
                || empty($user['xui_present'])) {
                continue;
            }
            $view = compat_user_view($user);
            $view['connection_id'] = $connectionId;
            $view['connection_name'] = $connection['name'];
            $view['inbound_id'] = (int) ($user['xui_inbound_id'] ?? $user['inbound_id'] ?? 0);
            $view['enabled'] = (bool) ($user['xui_enabled'] ?? false);
            $view['present'] = true;
            if ($search !== '' && stripos($view['login'] . ' ' . $view['email'] . ' ' . $view['uuid'], $search) === false) {
                continue;
            }
            $items[] = $view;
        }
        json_ok(['items' => $items, 'connection' => ['id' => $connectionId, 'name' => $connection['name']]]);
    }

    if ($method === 'POST') {
        $userId = input_string($body, 'user_id', true, 128);
        $enabled = input_boolean($body, 'enabled');
        $user = find_user_by_id($userId);
        if ($user === null || (string) ($user['xui_connection_id'] ?? '') !== $connectionId
            || empty($user['xui_present'])) {
            json_err('Клиент не найден в выбранном источнике.', 404);
        }
        $inboundId = (int) ($user['xui_inbound_id'] ?? $user['inbound_id'] ?? 0);
        $uuid = (string) ($user['xui_uuid'] ?? $user['uuid'] ?? '');
        $result = xui_connection_client($connection)->setClientEnabled($inboundId, $uuid, $enabled);
        JsonStore::update('users.json', static function (&$users) use ($userId, $enabled): void {
            $index = find_user_index($users, $userId);
            if ($index === null) {
                throw new RuntimeException('User not found.');
            }
            $users[$index]['xui_enabled'] = $enabled;
            $users[$index]['xui_status_updated_at'] = date(DATE_ATOM);
        }, []);
        json_ok(['enabled' => $enabled, 'result' => $result]);
    }

    json_err('Method not allowed.', 405);
}
