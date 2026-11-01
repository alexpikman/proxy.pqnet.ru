<?php
declare(strict_types=1);

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('PQSERVICE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

function session_storage_key(): string
{
    return hash('sha256', session_id());
}

function login(string $login, string $password): array
{
    session_start_once();
    $settings = JsonStore::read('settings.json', []);
    $user = null;

    if (
        isset($settings['admin_login'], $settings['admin_password_hash'])
        && hash_equals((string) $settings['admin_login'], $login)
        && (string) $settings['admin_password_hash'] !== ''
        && password_verify($password, (string) $settings['admin_password_hash'])
    ) {
        $user = [
            'id' => 'admin',
            'login' => $login,
            'email' => $login,
            'role' => 'admin'
        ];
    } else {
        foreach (JsonStore::read('users.json', []) as $candidate) {
            if (
                is_array($candidate)
                && empty($candidate['deleted'])
                && hash_equals((string) ($candidate['login'] ?? ''), $login)
                && isset($candidate['password_hash'])
                && password_verify($password, (string) $candidate['password_hash'])
            ) {
                $user = $candidate;
                $user['role'] = 'user';
                break;
            }
        }
    }

    if ($user === null) {
        throw new RuntimeException('Invalid login or password.');
    }

    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (string) $user['id'],
        'role' => (string) $user['role'],
        'csrf_token' => bin2hex(random_bytes(32)),
        'created_at' => time()
    ];

    $key = session_storage_key();
    JsonStore::update('sessions.json', static function (&$sessions) use ($key, $user): void {
        if (!is_array($sessions)) {
            $sessions = [];
        }
        $sessions[$key] = [
            'user_id' => (string) $user['id'],
            'role' => (string) $user['role'],
            'csrf_hash' => hash('sha256', (string) $_SESSION['csrf_token']),
            'created_at' => date(DATE_ATOM),
            'expires_at' => time() + (int) ini_get('session.gc_maxlifetime')
        ];
    }, []);

    unset($user['password_hash']);
    return [
        'user' => [
            'id' => $user['id'],
            'login' => $user['login'] ?? $user['email'] ?? '',
            'email' => $user['email'] ?? $user['login'] ?? '',
            'role' => $user['role']
        ],
        'csrf_token' => $_SESSION['csrf_token']
    ];
}

function require_auth(): array
{
    session_start_once();
    $userId = (string) ($_SESSION['user_id'] ?? '');
    $role = (string) ($_SESSION['role'] ?? '');
    if ($userId === '' || !in_array($role, ['admin', 'user'], true)) {
        json_err('Authentication required.', 401);
    }

    $record = JsonStore::read('sessions.json', []) [session_storage_key()] ?? null;
    if (
        !is_array($record)
        || (string) ($record['user_id'] ?? '') !== $userId
        || (string) ($record['role'] ?? '') !== $role
        || (int) ($record['expires_at'] ?? 0) < time()
    ) {
        $_SESSION = [];
        session_destroy();
        json_err('Session expired.', 401);
    }

    if ($role === 'admin') {
        $settings = JsonStore::read('settings.json', []);
        return [
            'id' => 'admin',
            'login' => (string) ($settings['admin_login'] ?? ''),
            'email' => (string) ($settings['admin_login'] ?? ''),
            'role' => 'admin'
        ];
    }

    $user = find_user_by_id($userId);
    if ($user === null || !empty($user['deleted'])) {
        json_err('User account is unavailable.', 401);
    }
    $user['role'] = 'user';
    return $user;
}

function require_role(string $role): array
{
    $user = require_auth();
    if (($user['role'] ?? '') !== $role) {
        json_err('Forbidden.', 403);
    }
    return $user;
}

function require_csrf(): void
{
    session_start_once();
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        json_err('CSRF validation failed.', 403);
    }
}

function logout(): void
{
    session_start_once();
    $key = session_storage_key();
    JsonStore::update('sessions.json', static function (&$sessions) use ($key): void {
        unset($sessions[$key]);
    }, []);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
    session_destroy();
}
