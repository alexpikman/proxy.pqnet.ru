<?php
declare(strict_types=1);

function route_auth_login(array $body): never
{
    $result = login(input_login($body), isset($body['password']) && is_string($body['password']) ? $body['password'] : '');
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


function route_admin_password_change(array $body): never
{
    require_role('admin');

    $currentPassword = $body['current_password'] ?? null;
    $newPassword = $body['new_password'] ?? null;
    $confirmation = $body['confirm_password'] ?? null;

    if (!is_string($currentPassword) || $currentPassword === ''
        || !is_string($newPassword) || strlen($newPassword) < 8 || strlen($newPassword) > 1024
        || !is_string($confirmation) || !hash_equals($newPassword, $confirmation)) {
        json_err('Проверьте текущий пароль и подтверждение нового. Новый пароль должен содержать не менее 8 символов.', 422);
    }

    $settings = JsonStore::read('settings.json', []);
    $storedHash = (string) ($settings['admin_password_hash'] ?? '');
    if ($storedHash === '' || !password_verify($currentPassword, $storedHash)) {
        json_err('Текущий пароль администратора указан неверно.', 403);
    }

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
    if (!is_string($newHash)) {
        json_err('Не удалось безопасно сохранить новый пароль.', 500);
    }

    JsonStore::update('settings.json', static function (&$current) use ($newHash): void {
        if (!is_array($current)) {
            throw new RuntimeException('Administrator settings are unavailable.');
        }
        $current['admin_password_hash'] = $newHash;
        $current['admin_password_change_required'] = false;
        $current['admin_password_changed_at'] = date(DATE_ATOM);
        $current['updated_at'] = date(DATE_ATOM);
    }, []);

    session_start_once();
    $currentSessionKey = session_storage_key();
    JsonStore::update('sessions.json', static function (&$sessions) use ($currentSessionKey): void {
        if (!is_array($sessions)) {
            $sessions = [];
        }
        foreach ($sessions as $key => $session) {
            if ($key !== $currentSessionKey && is_array($session) && ($session['role'] ?? '') === 'admin') {
                unset($sessions[$key]);
            }
        }
    }, []);

    json_ok(['changed' => true, 'message' => 'Пароль администратора успешно изменён.']);
}
