<?php
declare(strict_types=1);

/** Create the notification tables lazily for the current SQLite application. */
function notifications_ensure_schema(PDO $db): void {
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'sqlite') {
        throw new RuntimeException('Notifications currently require the SQLite database used by PQNET.');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_email TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'service',
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        url TEXT NOT NULL DEFAULT '',
        dedupe_key TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at TEXT DEFAULT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_user_notifications_user_created ON user_notifications(user_email, created_at DESC)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_user_notifications_dedupe ON user_notifications(user_email, dedupe_key) WHERE dedupe_key IS NOT NULL");
}

/** Returns false for a duplicate dedupe key, true when a row was created. */
function notification_create(PDO $db, string $email, string $title, string $body, string $type = 'service', string $url = '', ?string $dedupeKey = null): bool {
    $email = strtolower(trim($email));
    $title = trim($title);
    $body = trim($body);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $title === '' || mb_strlen($title) > 160 || $body === '' || mb_strlen($body) > 5000) {
        throw new InvalidArgumentException('Invalid notification fields.');
    }
    if (!in_array($type, ['service', 'billing', 'subscription', 'announcement'], true)) $type = 'service';
    if ($url !== '' && (!str_starts_with($url, '/') || str_starts_with($url, '//'))) $url = '';
    $stmt = $db->prepare('INSERT OR IGNORE INTO user_notifications(user_email,type,title,body,url,dedupe_key) VALUES(?,?,?,?,?,?)');
    $stmt->execute([$email, $type, $title, $body, $url, $dedupeKey]);
    return $stmt->rowCount() > 0;
}
