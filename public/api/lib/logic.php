<?php
declare(strict_types=1);

function get_user_balance(array $user): float
{
    return round((float) ($user['balance'] ?? 0), 2);
}

function append_coin_operation(string $userId, string $type, float $amount, string $comment, string $actorId): array
{
    $operation = [
        'id' => generate_id(),
        'user_id' => $userId,
        'type' => $type,
        'amount' => round($amount, 2),
        'comment' => $comment,
        'actor_id' => $actorId,
        'created_at' => date(DATE_ATOM)
    ];
    JsonStore::update('coins.json', static function (&$coins) use ($operation): void {
        if (!is_array($coins)) {
            $coins = [];
        }
        $coins[] = $operation;
    }, []);
    return $operation;
}

function credit(string $userId, float $amount, string $comment, string $actorId): array
{
    if ($amount <= 0 || !is_finite($amount)) {
        throw new InvalidArgumentException('Invalid credit amount.');
    }
    $updated = JsonStore::update('users.json', static function (&$users) use ($userId, $amount): array {
        $index = find_user_index($users, $userId);
        if ($index === null || !empty($users[$index]['deleted'])) {
            throw new RuntimeException('User not found.');
        }
        $users[$index]['balance'] = round((float) ($users[$index]['balance'] ?? 0) + $amount, 2);
        $users[$index]['updated_at'] = date(DATE_ATOM);
        return $users[$index];
    }, []);
    $operation = append_coin_operation($userId, 'credit', $amount, $comment, $actorId);
    $access = apply_access_state($userId);
    return ['user' => $updated, 'operation' => $operation, 'access' => $access];
}

function debit(string $userId, float $amount, string $comment, string $actorId): array
{
    if ($amount <= 0 || !is_finite($amount)) {
        throw new InvalidArgumentException('Invalid debit amount.');
    }
    $updated = JsonStore::update('users.json', static function (&$users) use ($userId, $amount): array {
        $index = find_user_index($users, $userId);
        if ($index === null || !empty($users[$index]['deleted'])) {
            throw new RuntimeException('User not found.');
        }
        $balance = round((float) ($users[$index]['balance'] ?? 0), 2);
        if ($amount > $balance) {
            throw new DomainException('Insufficient balance.');
        }
        $users[$index]['balance'] = round($balance - $amount, 2);
        $users[$index]['updated_at'] = date(DATE_ATOM);
        return $users[$index];
    }, []);
    $operation = append_coin_operation($userId, 'debit', $amount, $comment, $actorId);
    $access = apply_access_state($userId);
    return ['user' => $updated, 'operation' => $operation, 'access' => $access];
}

function calculate_tariff(string $tariffId): array
{
    $tariff = get_tariff($tariffId);
    if ($tariff === null) {
        throw new DomainException('Tariff not found or inactive.');
    }
    return [
        'tariff_id' => $tariff['id'],
        'price' => round((float) $tariff['price'], 2),
        'duration_days' => (int) ($tariff['duration_days'] ?? 30),
        'expires_at' => date(DATE_ATOM, time() + ((int) ($tariff['duration_days'] ?? 30) * 86400))
    ];
}

function apply_access_state(string $userId): array
{
    $user = find_user_by_id($userId);
    if ($user === null) {
        throw new RuntimeException('User not found.');
    }
    $shouldEnable = empty($user['deleted'])
        && get_user_balance($user) > 0
        && empty($user['admin_blocked']);

    if (empty($user['uuid']) || (int) ($user['inbound_id'] ?? 0) < 1) {
        return ['applied' => false, 'enabled' => $shouldEnable, 'reason' => 'client_not_linked'];
    }

    if (!empty($user['xui_connection_id']) && function_exists('xui_connection_find')) {
        $connection = xui_connection_find((string) $user['xui_connection_id']);
        if ($connection === null) {
            return ['applied' => false, 'enabled' => $shouldEnable, 'reason' => 'connection_not_found'];
        }
        $xui = xui_connection_client($connection);
        $inboundId = (int) ($user['xui_inbound_id'] ?? $user['inbound_id'] ?? 0);
        $uuid = (string) ($user['xui_uuid'] ?? $user['uuid'] ?? '');
    } else {
        $xui = new XuiClient();
        $inboundId = (int) $user['inbound_id'];
        $uuid = (string) $user['uuid'];
    }
    $result = $xui->setClientEnabled($inboundId, $uuid, $shouldEnable);
    return ['applied' => true, 'enabled' => $shouldEnable, 'result' => $result];
}

function sync_xui_clients(): array
{
    $xui = new XuiClient();
    $started = date(DATE_ATOM);
    $stats = [
        'started_at' => $started,
        'finished_at' => null,
        'inbounds' => 0,
        'remote_clients' => 0,
        'imported' => 0,
        'updated' => 0,
        'enabled' => 0,
        'blocked' => 0,
        'skipped' => 0,
        'errors' => []
    ];

    $inbounds = $xui->listInbounds();
    $stats['inbounds'] = count($inbounds);

    foreach ($inbounds as $inbound) {
        $inboundId = (int) ($inbound['id'] ?? 0);
        if ($inboundId < 1) {
            continue;
        }
        try {
            $clients = $xui->getClients($inboundId);
            $stats['remote_clients'] += count($clients);

            foreach ($clients as $client) {
                $email = trim((string) ($client['email'] ?? ''));
                $uuid = (string) ($client['id'] ?? '');
                if ($email === '' || $uuid === '') {
                    $stats['skipped']++;
                    continue;
                }

                $userId = null;
                $wasImported = false;

                $user = JsonStore::update('users.json', static function (&$users) use (
                    $email,
                    $uuid,
                    $inboundId,
                    &$userId,
                    &$wasImported
                ): array {
                    if (!is_array($users)) {
                        $users = [];
                    }
                    $index = null;
                    foreach ($users as $i => $candidate) {
                        if (
                            is_array($candidate)
                            && (string) ($candidate['email'] ?? $candidate['login'] ?? '') === $email
                        ) {
                            $index = $i;
                            break;
                        }
                    }

                    if ($index === null) {
                        $userId = generate_id();
                        $wasImported = true;
                        $users[] = [
                            'id' => $userId,
                            'login' => $email,
                            'email' => $email,
                            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
                            'role' => 'user',
                            'balance' => 0,
                            'admin_blocked' => false,
                            'deleted' => false,
                            'tariff_id' => null,
                            'uuid' => $uuid,
                            'inbound_id' => $inboundId,
                            'created_at' => date(DATE_ATOM),
                            'updated_at' => date(DATE_ATOM)
                        ];
                        return $users[array_key_last($users)];
                    }

                    $userId = (string) $users[$index]['id'];
                    $users[$index]['login'] = $email;
                    $users[$index]['email'] = $email;
                    $users[$index]['uuid'] = $uuid;
                    $users[$index]['inbound_id'] = $inboundId;
                    $users[$index]['updated_at'] = date(DATE_ATOM);
                    return $users[$index];
                }, []);

                if ($wasImported) {
                    $stats['imported']++;
                } else {
                    $stats['updated']++;
                }

                try {
                    $access = apply_access_state((string) $userId);
                    if (!empty($access['enabled'])) {
                        $stats['enabled']++;
                    } else {
                        $stats['blocked']++;
                    }
                } catch (Throwable $e) {
                    $stats['errors'][] = ['email' => $email, 'message' => $e->getMessage()];
                }
            }
        } catch (Throwable $e) {
            $stats['errors'][] = ['inbound_id' => $inboundId, 'message' => $e->getMessage()];
        }
    }

    $stats['finished_at'] = date(DATE_ATOM);
    JsonStore::write('sync_state.json', $stats);
    return $stats;
}
