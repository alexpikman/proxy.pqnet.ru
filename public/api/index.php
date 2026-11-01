<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/xui.php';
require_once __DIR__ . '/lib/logic.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/user.php';
require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/cron.php';
require_once __DIR__ . '/routes/compat.php';

JsonStore::init();
configure_error_handling();
configure_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $uri = isset($_GET['route']) && is_string($_GET['route']) ? $_GET['route'] : parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $uri = is_string($uri) ? $uri : '/';

    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')));
    if ($scriptDir !== '/' && str_starts_with($uri, $scriptDir . '/')) {
        $uri = substr($uri, strlen($scriptDir));
    } elseif ($scriptDir !== '/' && $uri === $scriptDir) {
        $uri = '/';
    }
    $uri = '/' . trim($uri, '/');
    if ($uri === '//') {
        $uri = '/';
    }

    $body = request_json();
    $isLogin = in_array($uri, ['/auth/login', '/login'], true);
    $isCron = $uri === '/cron/sync';
    $isPublic = in_array($uri, ['/register-request', '/set-password'], true);

    if (in_array($method, ['POST', 'PATCH', 'DELETE', 'PUT'], true) && !$isLogin && !$isCron && !$isPublic) {
        require_auth();
        require_csrf();
    }

    switch (true) {
        case in_array($uri, ['/auth/login', '/login'], true) && $method === 'POST':
            route_auth_login($body);
        case $uri === '/session' && $method === 'GET':
            route_compat_session();
        case $uri === '/profile' && $method === 'GET':
            route_compat_profile();
        case $uri === '/transactions' && $method === 'GET':
            route_compat_transactions(false);
        case $uri === '/programs' && $method === 'GET':
            route_compat_programs(false);
        case $uri === '/register-request' && $method === 'POST':
            route_compat_register_request($body);
        case $uri === '/set-password' && $method === 'POST':
            route_compat_set_password($body);
        case in_array($uri, ['/auth/logout', '/logout'], true) && $method === 'POST':
            route_auth_logout();
        case $uri === '/auth/me' && $method === 'GET':
            route_auth_me();

        case $uri === '/user/balance' && $method === 'GET':
            route_user_balance();
        case $uri === '/user/operations' && $method === 'GET':
            route_user_operations();
        case $uri === '/user/proxy' && $method === 'GET':
            route_user_proxy();

        case $uri === '/admin/users' && $method === 'GET':
            route_compat_admin_users();
        case $uri === '/admin/overview' && $method === 'GET':
            route_compat_admin_overview();
        case $uri === '/admin/transactions' && $method === 'GET':
            route_compat_transactions(true);
        case $uri === '/admin/registration-requests' && $method === 'GET':
            route_compat_admin_registration_requests();
        case preg_match('#^/admin/registration-requests/([a-f0-9]+)/([a-z]+)$#D', $uri, $m) === 1 && $method === 'POST':
            route_compat_admin_review_request($m[1], $m[2]);
        case $uri === '/admin/programs' && $method === 'GET':
            route_compat_programs(true);
        case $uri === '/admin/programs' && $method === 'POST':
            route_compat_admin_program_write($body);
        case preg_match('#^/admin/programs/([0-9]+)$#D', $uri, $m) === 1 && $method === 'PUT':
            route_compat_admin_program_write($body, (int) $m[1]);
        case $uri === '/admin/promocodes' && $method === 'POST':
            route_compat_admin_promocode_create($body);
        case $uri === '/admin/user/update' && $method === 'POST':
            route_compat_admin_user_update($body);
        case preg_match('#^/admin/user/([^/]+)$#D', $uri, $m) === 1 && $method === 'GET':
            route_compat_admin_user_get(rawurldecode($m[1]));
        case $uri === '/admin/users' && $method === 'POST':
            route_admin_users_create($body);
        case preg_match('#^/admin/users/([a-zA-Z0-9_-]+)$#D', $uri, $m) === 1 && $method === 'PATCH':
            route_admin_user_update($m[1], $body);
        case preg_match('#^/admin/users/([a-zA-Z0-9_-]+)$#D', $uri, $m) === 1 && $method === 'DELETE':
            route_admin_user_delete($m[1]);

        case $uri === '/admin/coins' && $method === 'GET':
            route_admin_coins();
        case $uri === '/admin/coins/credit' && $method === 'POST':
            route_compat_admin_coin_change($body, 'credit');
        case $uri === '/admin/coins/debit' && $method === 'POST':
            route_compat_admin_coin_change($body, 'debit');

        case $uri === '/admin/tariffs' && $method === 'GET':
            route_admin_tariffs_list();
        case $uri === '/admin/tariffs' && $method === 'POST':
            route_admin_tariff_create($body);
        case preg_match('#^/admin/tariffs/([a-zA-Z0-9_-]+)$#D', $uri, $m) === 1 && $method === 'PATCH':
            route_admin_tariff_update($m[1], $body);
        case preg_match('#^/admin/tariffs/([a-zA-Z0-9_-]+)$#D', $uri, $m) === 1 && $method === 'DELETE':
            route_admin_tariff_delete($m[1]);

        case $uri === '/admin/settings' && $method === 'GET':
            route_admin_settings_get();
        case $uri === '/admin/settings' && $method === 'PATCH':
            route_admin_settings_update($body);
        case $uri === '/admin/xui/status' && $method === 'GET':
            route_admin_xui_test();
        case $uri === '/admin/xui/test' && $method === 'POST':
            route_admin_xui_test();
        case $uri === '/admin/xui/sync' && $method === 'POST':
            route_compat_admin_xui_compare();
        case $uri === '/cron/sync' && $method === 'POST':
            route_cron_sync();
        default:
            json_err('Endpoint not found or method not allowed.', 404);
    }
} catch (DomainException $e) {
    json_err($e->getMessage(), 422);
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage(), 422);
} catch (RuntimeException $e) {
    json_err($e->getMessage(), 400);
} catch (Throwable $e) {
    $entry = sprintf("[%s] %s in %s:%d\n", date(DATE_ATOM), $e->getMessage(), $e->getFile(), $e->getLine());
    @file_put_contents(JsonStore::directory() . DIRECTORY_SEPARATOR . 'error.log', $entry, FILE_APPEND | LOCK_EX);
    json_err('Internal server error.', 500);
}
