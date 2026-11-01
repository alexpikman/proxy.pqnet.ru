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
    : dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'data';

JsonStore::init($dataDir);
$hash = password_hash('admin', PASSWORD_ARGON2ID);
if (!is_string($hash)) {
    fwrite(STDERR, "Could not hash the temporary administrator password.\n");
    exit(1);
}

JsonStore::update('settings.json', static function (&$settings) use ($hash): void {
    if (!is_array($settings)) {
        $settings = [];
    }
    $settings['admin_login'] = 'admin';
    $settings['admin_password_hash'] = $hash;
    $settings['admin_password_change_required'] = true;
    $settings['admin_password_initialized'] = true;
    $settings['admin_password_changed_at'] = date(DATE_ATOM);
    $settings['updated_at'] = date(DATE_ATOM);
}, []);

fwrite(STDOUT, "Temporary administrator credentials reset: admin / admin. Change the password immediately.\n");
