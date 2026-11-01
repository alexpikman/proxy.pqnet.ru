<?php
declare(strict_types=1);

function admin_public_user(array $user): array
{
    unset($user['password_hash']);
    $user['balance'] = get_user_balance($user);
    return $user;
}

function route_admin_users_list(): never
{
    require_role('admin');
    $pagination = input_pagination();
    $includeDeleted = filter_var($_GET['include_deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $users = array_values(array_filter(
        JsonStore::read('users.json', []),
        static fn($u): bool => is_array($u) && ($includeDeleted || empty($u['deleted']))
    ));
    usort($users, static fn(array $a, array $b): int =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    json_ok(paginate_array(array_map('admin_public_user', $users), $pagination['page'], $pagination['limit']));
}

function route_admin_users_create(array $body): never
{
    $admin = require_role('admin');
    $login = input_login($body);
    $password = input_password($body);
    $balance = array_key_exists('balance', $body) ? input_nonnegative_amount($body, 'balance') : 0.0;
    $tariffId = input_string($body, 'tariff_id', false, 128);
    if ($tariffId !== null && get_tariff($tariffId) === null) {
        json_err('Tariff not found or inactive.', 422);
    }

    $user = [
        'id' => generate_id(),
        'login' => $login,
        'email' => $login,
        'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
        'role' => 'user',
        'uuid' => null,
        'inbound_id' => null,
        'balance' => $balance,
        'admin_blocked' => false,
        'deleted' => false,
        'tariff_id' => $tariffId,
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'created_by' => (string) $admin['id']
    ];

    JsonStore::update('users.json', static function (&$users) use ($user): void {
        if (!is_array($users)) {
            $users = [];
        }
        foreach ($users as $existing) {
            if (
                is_array($existing)
                && strcasecmp((string) ($existing['login'] ?? ''), $user['login']) === 0
            ) {
                throw new DomainException('Login already exists.');
            }
        }
        $users[] = $user;
    }, []);

    if ($balance > 0) {
        append_coin_operation($user['id'], 'credit', $balance, 'Initial balance', (string) $admin['id']);
    }
    json_ok(admin_public_user($user), 201);
}

function route_admin_user_update(string $userId, array $body): never
{
    $admin = require_role('admin');
    $allowed = ['admin_blocked', 'tariff_id', 'balance_delta'];
    if ($body === [] || array_diff(array_keys($body), $allowed) !== []) {
        json_err('Invalid or empty user update.', 422);
    }

    $current = find_user_by_id($userId);
    if ($current === null || !empty($current['deleted'])) {
        json_err('User not found.', 404);
    }

    $changes = [];
    if (array_key_exists('admin_blocked', $body)) {
        $changes['admin_blocked'] = input_boolean($body, 'admin_blocked');
    }
    if (array_key_exists('tariff_id', $body)) {
        $tariffId = $body['tariff_id'];
        if ($tariffId !== null && (!is_string($tariffId) || ($tariffId !== '' && get_tariff($tariffId) === null))) {
            json_err('Invalid tariff_id.', 422);
        }
        $changes['tariff_id'] = $tariffId === '' ? null : $tariffId;
    }

    if ($changes !== []) {
        JsonStore::update('users.json', static function (&$users) use ($userId, $changes): void {
            $index = find_user_index($users, $userId);
            if ($index === null || !empty($users[$index]['deleted'])) {
                throw new RuntimeException('User not found.');
            }
            foreach ($changes as $field => $value) {
                $users[$index][$field] = $value;
            }
            $users[$index]['updated_at'] = date(DATE_ATOM);
        }, []);
    }

    $delta = 0.0;
    if (array_key_exists('balance_delta', $body)) {
        if (!is_numeric($body['balance_delta']) || !is_finite((float) $body['balance_delta'])) {
            json_err('Invalid balance_delta.', 422);
        }
        $delta = round((float) $body['balance_delta'], 2);
        if (abs($delta) > 1000000000) {
            json_err('Balance adjustment is too large.', 422);
        }
    }
    if ($delta > 0) {
        credit($userId, $delta, 'Administrative balance adjustment', (string) $admin['id']);
    } elseif ($delta < 0) {
        debit($userId, abs($delta), 'Administrative balance adjustment', (string) $admin['id']);
    }

    $access = apply_access_state($userId);
    $updated = find_user_by_id($userId);
    json_ok(['user' => admin_public_user($updated ?? []), 'access' => $access]);
}

function route_admin_user_delete(string $userId): never
{
    require_role('admin');
    JsonStore::update('users.json', static function (&$users) use ($userId): void {
        $index = find_user_index($users, $userId);
        if ($index === null || !empty($users[$index]['deleted'])) {
            throw new RuntimeException('User not found.');
        }
        $users[$index]['deleted'] = true;
        $users[$index]['deleted_at'] = date(DATE_ATOM);
        $users[$index]['updated_at'] = date(DATE_ATOM);
    }, []);

    $access = apply_access_state($userId);
    json_ok(['deleted' => true, 'user_id' => $userId, 'access' => $access]);
}

function route_admin_coins(): never
{
    require_role('admin');
    $pagination = input_pagination();
    $userId = isset($_GET['user_id']) ? (string) $_GET['user_id'] : '';
    $type = isset($_GET['type']) ? (string) $_GET['type'] : '';
    if ($type !== '' && !in_array($type, ['credit', 'debit'], true)) {
        json_err('Invalid operation type.', 422);
    }
    $items = array_values(array_filter(
        JsonStore::read('coins.json', []),
        static fn($op): bool => is_array($op)
            && ($userId === '' || (string) ($op['user_id'] ?? '') === $userId)
            && ($type === '' || ($op['type'] ?? '') === $type)
    ));
    usort($items, static fn(array $a, array $b): int =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    json_ok(paginate_array($items, $pagination['page'], $pagination['limit']));
}

function route_admin_coin_change(array $body, string $type): never
{
    $admin = require_role('admin');
    $userId = input_id($body);
    $amount = input_amount($body);
    $comment = input_string($body, 'comment', false, 1000) ?? '';
    $result = $type === 'credit'
        ? credit($userId, $amount, $comment, (string) $admin['id'])
        : debit($userId, $amount, $comment, (string) $admin['id']);
    json_ok($result, 201);
}

function route_admin_tariffs_list(): never
{
    require_role('admin');
    json_ok(array_values(JsonStore::read('tariffs.json', [])));
}

function route_admin_tariff_create(array $body): never
{
    require_role('admin');
    $tariff = [
        'id' => generate_id(),
        'name' => input_string($body, 'name', true, 150),
        'description' => input_string($body, 'description', false, 2000) ?? '',
        'price' => input_nonnegative_amount($body, 'price'),
        'duration_days' => input_integer($body, 'duration_days', false, 1, 3660) ?? 30,
        'active' => true,
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM)
    ];
    JsonStore::update('tariffs.json', static function (&$tariffs) use ($tariff): void {
        if (!is_array($tariffs)) {
            $tariffs = [];
        }
        $tariffs[] = $tariff;
    }, []);
    json_ok($tariff, 201);
}

function route_admin_tariff_update(string $id, array $body): never
{
    require_role('admin');
    $allowed = ['name', 'description', 'price', 'duration_days', 'active'];
    if ($body === [] || array_diff(array_keys($body), $allowed) !== []) {
        json_err('Invalid or empty tariff update.', 422);
    }

    JsonStore::update('tariffs.json', static function (&$tariffs) use ($id, $body): void {
        foreach ($tariffs as &$tariff) {
            if (!is_array($tariff) || (string) ($tariff['id'] ?? '') !== $id) {
                continue;
            }
            if (array_key_exists('name', $body)) {
                if (!is_string($body['name']) || trim($body['name']) === '' || text_length($body['name']) > 150) {
                    throw new InvalidArgumentException('Invalid tariff name.');
                }
                $tariff['name'] = trim($body['name']);
            }
            if (array_key_exists('description', $body)) {
                if (!is_string($body['description']) || text_length($body['description']) > 2000) {
                    throw new InvalidArgumentException('Invalid tariff description.');
                }
                $tariff['description'] = trim($body['description']);
            }
            if (array_key_exists('price', $body)) {
                if (!is_numeric($body['price']) || (float) $body['price'] < 0 || !is_finite((float) $body['price'])) {
                    throw new InvalidArgumentException('Invalid tariff price.');
                }
                $tariff['price'] = round((float) $body['price'], 2);
            }
            if (array_key_exists('duration_days', $body)) {
                $days = filter_var($body['duration_days'], FILTER_VALIDATE_INT);
                if ($days === false || $days < 1 || $days > 3660) {
                    throw new InvalidArgumentException('Invalid tariff duration.');
                }
                $tariff['duration_days'] = $days;
            }
            if (array_key_exists('active', $body)) {
                if (!is_bool($body['active'])) {
                    throw new InvalidArgumentException('Invalid active flag.');
                }
                $tariff['active'] = $body['active'];
            }
            $tariff['updated_at'] = date(DATE_ATOM);
            unset($tariff);
            return;
        }
        unset($tariff);
        throw new RuntimeException('Tariff not found.');
    }, []);
    foreach (JsonStore::read('tariffs.json', []) as $tariff) {
        if ((string) ($tariff['id'] ?? '') === $id) {
            json_ok($tariff);
        }
    }
    json_err('Tariff not found.', 404);
}

function route_admin_tariff_delete(string $id): never
{
    require_role('admin');
    JsonStore::update('tariffs.json', static function (&$tariffs) use ($id): void {
        foreach ($tariffs as &$tariff) {
            if (is_array($tariff) && (string) ($tariff['id'] ?? '') === $id) {
                $tariff['active'] = false;
                $tariff['deleted_at'] = date(DATE_ATOM);
                $tariff['updated_at'] = date(DATE_ATOM);
                unset($tariff);
                return;
            }
        }
        unset($tariff);
        throw new RuntimeException('Tariff not found.');
    }, []);
    json_ok(['deleted' => true, 'tariff_id' => $id]);
}

function route_admin_settings_get(): never
{
    require_role('admin');
    $settings = JsonStore::read('settings.json', []);
    foreach (['admin_password_hash', 'xui_pass', 'xui_token', 'cron_secret'] as $key) {
        if (!empty($settings[$key])) {
            $settings[$key] = '********';
        }
    }
    json_ok($settings);
}

function route_admin_settings_update(array $body): never
{
    require_role('admin');
    $allowed = ['admin_login', 'admin_password', 'xui_host', 'xui_user', 'xui_pass', 'xui_token', 'xui_timeout', 'cron_secret', 'currency', 'cors_origins'];
    if ($body === [] || array_diff(array_keys($body), $allowed) !== []) {
        json_err('Invalid or empty settings update.', 422);
    }

    JsonStore::update('settings.json', static function (&$settings) use ($body): void {
        foreach ($body as $key => $value) {
            if (in_array($key, ['xui_pass', 'xui_token', 'cron_secret'], true) && $value === '********') {
                continue;
            }
            switch ($key) {
                case 'admin_login':
                case 'xui_user':
                case 'currency':
                    if (!is_string($value) || trim($value) === '') {
                        throw new InvalidArgumentException('Invalid setting: ' . $key);
                    }
                    $settings[$key] = trim($value);
                    break;
                case 'admin_password':
                    if (!is_string($value) || strlen($value) < 8 || strlen($value) > 1024) {
                        throw new InvalidArgumentException('Invalid administrator password.');
                    }
                    $settings['admin_password_hash'] = password_hash($value, PASSWORD_ARGON2ID);
                    break;
                case 'xui_host':
                    if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)
                        || !in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                        throw new InvalidArgumentException('Invalid 3x-ui host URL.');
                    }
                    $settings[$key] = rtrim($value, '/');
                    break;
                case 'xui_pass':
                case 'xui_token':
                case 'cron_secret':
                    if (!is_string($value) || strlen($value) > 4096) {
                        throw new InvalidArgumentException('Invalid secret setting.');
                    }
                    if ($value !== '') {
                        $settings[$key] = $value;
                    }
                    break;
                case 'xui_timeout':
                    $timeout = filter_var($value, FILTER_VALIDATE_INT);
                    if ($timeout === false || $timeout < 1 || $timeout > 10) {
                        throw new InvalidArgumentException('Invalid 3x-ui timeout.');
                    }
                    $settings[$key] = $timeout;
                    break;
                case 'cors_origins':
                    if (!is_array($value)) {
                        throw new InvalidArgumentException('Invalid CORS origins.');
                    }
                    foreach ($value as $origin) {
                        if (!is_string($origin) || !preg_match('#^https?://[^/]+$#', $origin)) {
                            throw new InvalidArgumentException('Invalid CORS origin.');
                        }
                    }
                    $settings[$key] = array_values(array_unique($value));
                    break;
            }
        }
        $settings['updated_at'] = date(DATE_ATOM);
    }, []);
    json_ok(['updated' => true]);
}

function route_admin_xui_test(): never
{
    require_role('admin');
    json_ok((new XuiClient())->testConnection());
}

function route_admin_xui_sync(): never
{
    require_role('admin');
    json_ok(sync_xui_clients());
}
