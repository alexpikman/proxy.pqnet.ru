<?php
declare(strict_types=1);

function route_auth_login(array $body): never
{
    $result = login(input_login($body), input_password($body));
    json_ok(['role' => $result['user']['role'], 'user' => $result['user'], 'csrf_token' => $result['csrf_token']]);
}

function route_auth_logout(): never
{
    logout();
    json_ok(['logged_out' => true]);
}

function route_auth_me(): never
{
    $user = require_auth();
    session_start_once();
    $public = [
        'id' => $user['id'],
        'login' => $user['login'] ?? $user['email'] ?? '',
        'email' => $user['email'] ?? $user['login'] ?? '',
        'role' => $user['role']
    ];
    if ($user['role'] === 'user') {
        $public['balance'] = get_user_balance($user);
        $public['tariff_id'] = $user['tariff_id'] ?? null;
        $public['admin_blocked'] = !empty($user['admin_blocked']);
    }
    json_ok(['user' => $public, 'csrf_token' => $_SESSION['csrf_token']]);
}
