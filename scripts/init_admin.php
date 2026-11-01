<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/lib/store.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$configured = getenv('PQSERVICE_DATA_DIR');
$dataDir = is_string($configured) && $configured !== ''
    ? rtrim($configured, DIRECTORY_SEPARATOR)
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
JsonStore::init($dataDir);

$password = getenv('PQSERVICE_ADMIN_PASSWORD') ?: '';
if (strlen($password) < 12) {
    fwrite(STDERR, "Set PQSERVICE_ADMIN_PASSWORD to a password of at least 12 characters.\n");
    exit(1);
}

JsonStore::update('settings.json', static function (&$settings) use ($password): void {
    if (!is_array($settings)) {
        $settings = [];
    }
    $settings['admin_login'] = (string) ($settings['admin_login'] ?? 'admin');
    $settings['admin_password_hash'] = password_hash($password, PASSWORD_ARGON2ID);
    $settings['updated_at'] = date(DATE_ATOM);
}, []);

fwrite(STDOUT, "Administrator password hash saved.\n");
