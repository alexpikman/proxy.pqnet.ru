<?php
declare(strict_types=1);

function compat_user_by_key(string $key): ?array
{
    foreach (JsonStore::read('users.json', []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        if (
            hash_equals((string) ($user['id'] ?? ''), $key)
            || hash_equals((string) ($user['uuid'] ?? ''), $key)
            || strcasecmp((string) ($user['login'] ?? ''), $key) === 0
            || strcasecmp((string) ($user['email'] ?? ''), $key) === 0
        ) {
            return $user;
        }
    }
    return null;
}

function compat_user_view(array $user): array
{
    $balance = get_user_balance($user);
    $enabled = empty($user['deleted']) && empty($user['admin_blocked']) && $balance > 0;
    $expiry = null;
    if (!empty($user['expiry_time'])) {
        $expiry = (int) $user['expiry_time'];
    }
    $daysLeft = $expiry ? max(0, (int) ceil(($expiry - (int) (microtime(true) * 1000)) / 86400000)) : 0;
    return [
        'id' => (string) ($user['id'] ?? ''),
        'uuid' => (string) ($user['uuid'] ?? $user['id'] ?? ''),
        'login' => (string) ($user['login'] ?? $user['email'] ?? ''),
        'email' => (string) ($user['email'] ?? $user['login'] ?? ''),
        'coins' => $balance,
        'balance' => $balance,
        'price_per_day' => (float) ($user['price_per_day'] ?? 0),
        'days_left' => $daysLeft,
        'localstatus' => $enabled,
        'localexpire' => $expiry ? (int) floor($expiry / 1000) : ($user['expires_at'] ?? null),
        'expiry_date' => $user['expires_at'] ?? null,
        'vip' => !empty($user['vip']),
        'source' => !empty($user['uuid']) ? 'xui' : 'local',
        'password_set' => !empty($user['password_hash']),
        'admin_blocked' => !empty($user['admin_blocked']),
        'tariff_id' => $user['tariff_id'] ?? null,
        'created_at' => $user['created_at'] ?? null
    ];
}

function route_compat_session(): never
{
    $user = require_auth();
    json_ok([
        'authenticated' => true,
        'role' => $user['role'],
        'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        'user' => ['id' => $user['id'], 'login' => $user['login'] ?? '', 'email' => $user['email'] ?? '']
    ]);
}

function route_compat_profile(): never
{
    $user = require_role('user');
    $view = compat_user_view($user);
    $view['localstatus'] = $view['localstatus'] && empty($user['deleted']);
    json_ok(['user' => $view, 'subscription_url' => $user['subscription_url'] ?? null]);
}

function route_compat_transactions(bool $admin = false): never
{
    $current = $admin ? require_role('admin') : require_role('user');
    $items = JsonStore::read('coins.json', []);
    if (!$admin) {
        $items = array_values(array_filter($items, static fn($item): bool =>
            is_array($item) && (string) ($item['user_id'] ?? '') === (string) $current['id']
        ));
    }
    usort($items, static fn(array $a, array $b): int =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    if ($admin) {
        $users = JsonStore::read('users.json', []);
        foreach ($items as &$item) {
            $user = null;
            foreach ($users as $candidate) {
                if (is_array($candidate) && (string) ($candidate['id'] ?? '') === (string) ($item['user_id'] ?? '')) {
                    $user = $candidate;
                    break;
                }
            }
            $item['login'] = $user['login'] ?? '';
            $item['email'] = $user['email'] ?? '';
            $item['operation'] = ($item['type'] ?? '') === 'credit' ? 'Начисление' : 'Списание';
            $item['amount'] = ($item['type'] ?? '') === 'debit' ? -(float) ($item['amount'] ?? 0) : (float) ($item['amount'] ?? 0);
        }
        unset($item);
    }
    json_ok(array_values($items));
}

function route_compat_programs(bool $admin = false): never
{
    if ($admin) {
        require_role('admin');
    }
    $items = JsonStore::read('programs.json', []);
    if (!is_array($items)) {
        $items = [];
    }
    if (!$admin) {
        $items = array_values(array_filter($items, static fn($item): bool =>
            is_array($item) && !empty($item['is_active'])
        ));
    }
    usort($items, static fn(array $a, array $b): int =>
        ((int) ($a['sort_order'] ?? 100)) <=> ((int) ($b['sort_order'] ?? 100))
    );
    json_ok($items);
}

function route_compat_register_request(array $body): never
{
    $login = input_string($body, 'login', true, 320);
    $email = input_string($body, 'email', true, 320);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('Invalid email address.', 422);
    }
    $request = [
        'id' => generate_id(),
        'login' => $login,
        'email' => $email,
        'phone' => input_string($body, 'phone', false, 64),
        'telegram' => input_string($body, 'telegram', false, 128),
        'status' => 'pending',
        'created_at' => date(DATE_ATOM)
    ];
    JsonStore::update('registration_requests.json', static function (&$items) use ($request): void {
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $item) {
            if (is_array($item) && ($item['status'] ?? '') === 'pending'
                && strcasecmp((string) ($item['email'] ?? ''), $request['email']) === 0) {
                throw new DomainException('A registration request for this email is already pending.');
            }
        }
        $items[] = $request;
    }, []);
    json_ok(['message' => 'Заявка отправлена. Дождитесь решения администратора.'], 201);
}

function route_compat_set_password(array $body): never
{
    $token = input_string($body, 'token', true, 256);
    $password = input_password($body);
    $hash = hash('sha256', $token);
    $record = JsonStore::read('setup_tokens.json', [])[$hash] ?? null;
    if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) {
        json_err('The password setup link is invalid or expired.', 410);
    }
    $userId = (string) ($record['user_id'] ?? '');
    JsonStore::update('users.json', static function (&$users) use ($userId, $password): void {
        $index = find_user_index($users, $userId);
        if ($index === null || !empty($users[$index]['deleted'])) {
            throw new RuntimeException('User not found.');
        }
        $users[$index]['password_hash'] = password_hash($password, PASSWORD_ARGON2ID);
        $users[$index]['updated_at'] = date(DATE_ATOM);
    }, []);
    JsonStore::update('setup_tokens.json', static function (&$tokens) use ($hash): void {
        unset($tokens[$hash]);
    }, []);
    json_ok(['message' => 'Password set successfully.']);
}

function route_compat_admin_overview(): never
{
    require_role('admin');
    $users = array_values(array_filter(JsonStore::read('users.json', []), static fn($u): bool =>
        is_array($u) && empty($u['deleted'])
    ));
    $active = count(array_filter($users, static fn($u): bool =>
        empty($u['admin_blocked']) && (float) ($u['balance'] ?? 0) > 0
    ));
    $sync = JsonStore::read('sync_state.json', []);
    json_ok([
        'total_users' => count($users),
        'active_users' => $active,
        'disabled_users' => count($users) - $active,
        'total_coins' => round(array_sum(array_map(static fn($u): float => (float) ($u['balance'] ?? 0), $users)), 2),
        'xui' => [
            'connected' => !empty($sync['finished_at']) && empty($sync['errors']),
            'message' => !empty($sync['finished_at']) ? 'Последняя синхронизация завершена.' : 'Синхронизация ещё не выполнялась.',
            'last_sync' => $sync['finished_at'] ?? null
        ]
    ]);
}

function route_compat_admin_users(): never
{
    require_role('admin');
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = (string) ($_GET['status'] ?? 'all');
    $source = (string) ($_GET['source'] ?? 'all');
    $users = array_values(array_filter(JsonStore::read('users.json', []), static function ($user) use ($search, $status, $source): bool {
        if (!is_array($user) || !empty($user['deleted'])) {
            return false;
        }
        $view = compat_user_view($user);
        if ($search !== '' && stripos($view['login'] . ' ' . $view['email'] . ' ' . $view['uuid'], $search) === false) {
            return false;
        }
        if ($status === 'active' && !$view['localstatus']) {
            return false;
        }
        if ($status === 'disabled' && $view['localstatus']) {
            return false;
        }
        if ($source !== 'all' && $view['source'] !== $source) {
            return false;
        }
        return true;
    }));
    json_ok(array_map('compat_user_view', $users));
}

function route_compat_admin_user_get(string $key): never
{
    require_role('admin');
    $user = compat_user_by_key($key);
    if ($user === null || !empty($user['deleted'])) {
        json_err('User not found.', 404);
    }
    json_ok(compat_user_view($user));
}

function route_compat_admin_user_update(array $body): never
{
    require_role('admin');
    $key = input_string($body, 'uuid', true, 128);
    $user = compat_user_by_key($key);
    if ($user === null) {
        json_err('User not found.', 404);
    }
    $userId = (string) $user['id'];
    $changes = [];
    if (array_key_exists('coins', $body)) {
        $changes['balance'] = input_nonnegative_amount($body, 'coins');
    }
    if (array_key_exists('price_per_day', $body)) {
        $changes['price_per_day'] = input_nonnegative_amount($body, 'price_per_day');
    }
    if (array_key_exists('vip', $body)) {
        $changes['vip'] = (bool) $body['vip'];
    }
    if (array_key_exists('localstatus', $body)) {
        $changes['admin_blocked'] = !filter_var($body['localstatus'], FILTER_VALIDATE_BOOLEAN);
    }
    JsonStore::update('users.json', static function (&$users) use ($userId, $changes): void {
        $index = find_user_index($users, $userId);
        if ($index === null) {
            throw new RuntimeException('User not found.');
        }
        foreach ($changes as $field => $value) {
            $users[$index][$field] = $value;
        }
        $users[$index]['updated_at'] = date(DATE_ATOM);
    }, []);
    apply_access_state($userId);
    json_ok(compat_user_view(find_user_by_id($userId) ?? []));
}

function route_compat_admin_coin_change(array $body, string $type): never
{
    $admin = require_role('admin');
    $key = input_string($body, 'user', true, 320);
    $user = compat_user_by_key($key);
    if ($user === null) {
        json_err('User not found.', 404);
    }
    $result = $type === 'credit'
        ? credit((string) $user['id'], input_amount($body), input_string($body, 'comment', false, 1000) ?? '', (string) $admin['id'])
        : debit((string) $user['id'], input_amount($body), input_string($body, 'comment', false, 1000) ?? '', (string) $admin['id']);
    json_ok($result, 201);
}

function route_compat_admin_registration_requests(): never
{
    require_role('admin');
    $items = JsonStore::read('registration_requests.json', []);
    usort($items, static fn(array $a, array $b): int =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    json_ok($items);
}

function route_compat_admin_review_request(string $id, string $action): never
{
    require_role('admin');
    if (!in_array($action, ['approve', 'reject'], true)) {
        json_err('Invalid request action.', 404);
    }
    $request = null;
    JsonStore::update('registration_requests.json', static function (&$items) use ($id, $action, &$request): void {
        foreach ($items as &$item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === $id) {
                if (($item['status'] ?? '') !== 'pending') {
                    throw new DomainException('Request has already been reviewed.');
                }
                $item['status'] = $action === 'approve' ? 'approved' : 'rejected';
                $item['reviewed_at'] = date(DATE_ATOM);
                $request = $item;
                unset($item);
                return;
            }
        }
        unset($item);
        throw new RuntimeException('Registration request not found.');
    }, []);
    if ($action === 'reject') {
        json_ok(['status' => 'rejected']);
    }

    $existing = compat_user_by_key((string) $request['email']);
    if ($existing !== null) {
        throw new DomainException('A user with this email already exists.');
    }
    $userId = generate_id();
    $user = [
        'id' => $userId,
        'login' => $request['login'],
        'email' => $request['email'],
        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
        'role' => 'user',
        'balance' => 0,
        'admin_blocked' => false,
        'deleted' => false,
        'uuid' => null,
        'inbound_id' => null,
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM)
    ];
    JsonStore::update('users.json', static function (&$users) use ($user): void {
        $users[] = $user;
    }, []);
    $token = bin2hex(random_bytes(32));
    JsonStore::update('setup_tokens.json', static function (&$tokens) use ($token, $userId): void {
        if (!is_array($tokens)) {
            $tokens = [];
        }
        $tokens[hash('sha256', $token)] = ['user_id' => $userId, 'expires_at' => time() + 86400];
    }, []);
    json_ok(['status' => 'approved', 'setup_token' => $token]);
}

function route_compat_admin_program_write(array $body, ?int $id = null): never
{
    require_role('admin');
    $record = [
        'id' => $id ?? (int) (microtime(true) * 1000),
        'name' => input_string($body, 'name', true, 150),
        'platform' => input_string($body, 'platform', true, 40),
        'url' => input_string($body, 'url', true, 2048),
        'download_url' => input_string($body, 'url', true, 2048),
        'icon_url' => input_string($body, 'icon_url', false, 2048),
        'description' => input_string($body, 'description', false, 2000) ?? '',
        'version' => input_string($body, 'version', false, 80) ?? '',
        'coin_price' => input_nonnegative_amount($body, 'coin_price'),
        'sort_order' => input_integer($body, 'sort_order', false, 0, 100000) ?? 100,
        'is_active' => array_key_exists('is_active', $body) ? input_boolean($body, 'is_active') : true,
        'updated_at' => date(DATE_ATOM)
    ];
    JsonStore::update('programs.json', static function (&$items) use ($record, $id): void {
        if (!is_array($items)) {
            $items = [];
        }
        if ($id === null) {
            $items[] = $record;
            return;
        }
        foreach ($items as &$item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $item = array_merge($item, $record);
                unset($item);
                return;
            }
        }
        unset($item);
        throw new RuntimeException('Program not found.');
    }, []);
    json_ok($record, $id === null ? 201 : 200);
}

function route_compat_admin_promocode_create(array $body): never
{
    require_role('admin');
    $code = strtoupper(input_string($body, 'code', true, 64));
    if (!preg_match('/^[A-Z0-9_-]+$/D', $code)) {
        json_err('Invalid promocode.', 422);
    }
    $record = [
        'id' => generate_id(),
        'code' => $code,
        'type' => input_string($body, 'type', true, 16),
        'coins_amount' => input_nonnegative_amount($body, 'coins_amount'),
        'days_amount' => input_nonnegative_amount($body, 'days_amount'),
        'max_uses' => input_integer($body, 'max_uses', false, 0, 1000000) ?? 1,
        'uses' => 0,
        'active' => true,
        'created_at' => date(DATE_ATOM)
    ];
    if (!in_array($record['type'], ['coins', 'days'], true)) {
        json_err('Invalid promocode type.', 422);
    }
    JsonStore::update('promocodes.json', static function (&$items) use ($record): void {
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $item) {
            if (is_array($item) && ($item['code'] ?? '') === $record['code']) {
                throw new DomainException('Promocode already exists.');
            }
        }
        $items[] = $record;
    }, []);
    json_ok($record, 201);
}

function route_compat_admin_xui_compare(): never
{
    require_role('admin');
    $xui = new XuiClient();
    $remote = [];
    foreach ($xui->listInbounds() as $inbound) {
        $inboundId = (int) ($inbound['id'] ?? 0);
        if ($inboundId < 1) {
            continue;
        }
        foreach ($xui->getClients($inboundId) as $client) {
            $email = strtolower(trim((string) ($client['email'] ?? '')));
            if ($email !== '') {
                $remote[$email] = ['uuid' => (string) ($client['id'] ?? ''), 'inbound_id' => $inboundId];
            }
        }
    }
    $local = [];
    foreach (JsonStore::read('users.json', []) as $user) {
        if (is_array($user) && empty($user['deleted'])) {
            $email = strtolower(trim((string) ($user['email'] ?? $user['login'] ?? '')));
            if ($email !== '') {
                $local[$email] = $user;
            }
        }
    }
    $matched = 0;
    $conflicts = 0;
    foreach ($remote as $email => $client) {
        if (!isset($local[$email])) {
            continue;
        }
        $matched++;
        if (!empty($local[$email]['uuid']) && $local[$email]['uuid'] !== $client['uuid']) {
            $conflicts++;
        }
    }
    json_ok([
        'counts' => [
            'matched' => $matched,
            'panel_only' => count(array_diff_key($remote, $local)),
            'service_only' => count(array_diff_key($local, $remote)),
            'conflicts' => $conflicts
        ],
        'inbounds_count' => count($xui->listInbounds()),
        'changed' => false
    ]);
}
