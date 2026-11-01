<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/lib/store.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\\n");
    exit(1);
}

$configured = getenv('PQSERVICE_DATA_DIR');
$dataDir = is_string($configured) && $configured !== ''
    ? rtrim($configured, DIRECTORY_SEPARATOR)
    : dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'data';
JsonStore::init($dataDir);

$initialized = JsonStore::update('settings.json', static function (&$settings): bool {
    if (!is_array($settings)) {
        $settings = [];
    }

    if (!empty($settings['admin_password_initialized'])) {
        return false;
    }

    $settings['admin_login'] = 'admin';
    $settings['admin_password_hash'] = password_hash('admin', PASSWORD_ARGON2ID);
    $settings['admin_password_change_required'] = true;
    $settings['admin_password_initialized'] = true;
    $settings['admin_password_changed_at'] = null;
    $settings['updated_at'] = date(DATE_ATOM);
    return true;
}, []);

fwrite(STDOUT, $initialized
    ? "Default administrator credentials initialized: admin / admin. Change the password in Technical Management > System.\\n"
    : "Existing administrator password preserved.\\n");
