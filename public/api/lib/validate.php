<?php
declare(strict_types=1);

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function input_string(array $data, string $key, bool $required = true, int $max = 255): ?string
{
    if (!array_key_exists($key, $data)) {
        if ($required) {
            throw new InvalidArgumentException('Missing field: ' . $key);
        }
        return null;
    }
    if (!is_string($data[$key])) {
        throw new InvalidArgumentException('Invalid field: ' . $key);
    }
    $value = trim($data[$key]);
    if (($required && $value === '') || text_length($value) > $max) {
        throw new InvalidArgumentException('Invalid field: ' . $key);
    }
    return $value;
}

function input_login(array $data): string
{
    return input_string($data, 'login', true, 320);
}

function input_password(array $data): string
{
    if (!isset($data['password']) || !is_string($data['password'])) {
        throw new InvalidArgumentException('Invalid password.');
    }
    $password = $data['password'];
    if (strlen($password) < 1 || strlen($password) > 1024) {
        throw new InvalidArgumentException('Password must contain between 1 and 1024 bytes.');
    }
    return $password;
}

function input_id(array $data, string $key = 'user_id'): string
{
    $id = input_string($data, $key, true, 128);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $id)) {
        throw new InvalidArgumentException('Invalid identifier.');
    }
    return $id;
}

function input_nonnegative_amount(array $data, string $key): float
{
    if (!array_key_exists($key, $data) || !is_numeric($data[$key])) {
        throw new InvalidArgumentException('Invalid amount: ' . $key);
    }
    $amount = round((float) $data[$key], 2);
    if (!is_finite($amount) || $amount < 0 || $amount > 1000000000) {
        throw new InvalidArgumentException('Amount is outside allowed limits: ' . $key);
    }
    return $amount;
}

function input_amount(array $data, string $key = 'amount'): float
{
    $amount = input_nonnegative_amount($data, $key);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }
    return $amount;
}

function input_boolean(array $data, string $key): bool
{
    if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
        throw new InvalidArgumentException('Invalid boolean field: ' . $key);
    }
    return $data[$key];
}

function input_integer(array $data, string $key, bool $required = true, int $min = 0, int $max = 1000000): ?int
{
    if (!array_key_exists($key, $data)) {
        if ($required) {
            throw new InvalidArgumentException('Missing field: ' . $key);
        }
        return null;
    }
    $value = filter_var($data[$key], FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        throw new InvalidArgumentException('Invalid integer field: ' . $key);
    }
    return $value;
}

function input_pagination(): array
{
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 25, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1 || $limit === false || $limit < 1 || $limit > 100) {
        throw new InvalidArgumentException('Invalid pagination parameters.');
    }
    return ['page' => $page, 'limit' => $limit];
}

function paginate_array(array $items, int $page, int $limit): array
{
    $total = count($items);
    return [
        'items' => array_slice($items, ($page - 1) * $limit, $limit),
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]
    ];
}

function generate_id(): string
{
    return bin2hex(random_bytes(16));
}

function find_user_index(array $users, string $id): ?int
{
    foreach ($users as $index => $user) {
        if (is_array($user) && (string) ($user['id'] ?? '') === $id) {
            return (int) $index;
        }
    }
    return null;
}

function find_user_by_id(string $id): ?array
{
    foreach (JsonStore::read('users.json', []) as $user) {
        if (is_array($user) && (string) ($user['id'] ?? '') === $id) {
            return $user;
        }
    }
    return null;
}

function get_tariff(string $id): ?array
{
    foreach (JsonStore::read('tariffs.json', []) as $tariff) {
        if (is_array($tariff) && (string) ($tariff['id'] ?? '') === $id && !empty($tariff['active'])) {
            return $tariff;
        }
    }
    return null;
}
