<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__);
$configured = getenv('PQSERVICE_DATA_DIR');
$dataDir = is_string($configured) && $configured !== ''
    ? rtrim($configured, DIRECTORY_SEPARATOR)
    : $root . DIRECTORY_SEPARATOR . 'data';
$examples = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'examples';

if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
    fwrite(STDERR, "Cannot create data directory.\n");
    exit(1);
}
if (!is_dir($examples)) {
    fwrite(STDERR, "Example data files are missing.\n");
    exit(1);
}

foreach (['settings.json', 'users.json', 'tariffs.json', 'coins.json', 'sessions.json', 'sync_state.json'] as $file) {
    $target = $dataDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($target)) {
        if (!copy($examples . DIRECTORY_SEPARATOR . $file, $target)) {
            fwrite(STDERR, "Cannot initialize {$file}.\n");
            exit(1);
        }
        chmod($target, 0600);
        fwrite(STDOUT, "Created {$file}\n");
    } else {
        fwrite(STDOUT, "Preserved {$file}\n");
    }
}
chmod($dataDir, 0700);
fwrite(STDOUT, "PQService data directory initialized: {$dataDir}\n");
