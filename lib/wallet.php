<?php
declare(strict_types=1);

/** Persistent wallet data is separate from the legacy users table. */
function wallet_ensure_schema(PDO $db): void
{
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_accounts (
            user_email TEXT PRIMARY KEY, coins INTEGER NOT NULL DEFAULT 0 CHECK (coins >= 0),
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_email TEXT NOT NULL,
            type TEXT NOT NULL CHECK (type IN ('topup','purchase','promo','admin_adjustment','refund')),
            coins_delta INTEGER NOT NULL, idempotency_key TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT '', admin_user TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS topup_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_email TEXT NOT NULL,
            amount_rub INTEGER NOT NULL CHECK (amount_rub > 0),
            requested_coins INTEGER NOT NULL CHECK (requested_coins > 0),
            status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
            admin_note TEXT NOT NULL DEFAULT '', reviewed_by TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_settings (
            setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("INSERT OR IGNORE INTO wallet_settings(setting_key, setting_value) VALUES ('coins_per_ruble','1'), ('price_per_day','10')");
        return;
    }
    if ($driver === 'pgsql') {
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_accounts (
            user_email VARCHAR(320) PRIMARY KEY, coins BIGINT NOT NULL DEFAULT 0 CHECK (coins >= 0),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
            id BIGSERIAL PRIMARY KEY, user_email VARCHAR(320) NOT NULL,
            type VARCHAR(32) NOT NULL CHECK (type IN ('topup','purchase','promo','admin_adjustment','refund')),
            coins_delta BIGINT NOT NULL, idempotency_key VARCHAR(160) NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT '', admin_user VARCHAR(320),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS topup_requests (
            id BIGSERIAL PRIMARY KEY, user_email VARCHAR(320) NOT NULL,
            amount_rub BIGINT NOT NULL CHECK (amount_rub > 0),
            requested_coins BIGINT NOT NULL CHECK (requested_coins > 0),
            status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
            admin_note TEXT NOT NULL DEFAULT '', reviewed_by VARCHAR(320),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at TIMESTAMPTZ
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_settings (
            setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("INSERT INTO wallet_settings(setting_key, setting_value) VALUES ('coins_per_ruble','1'), ('price_per_day','10') ON CONFLICT(setting_key) DO NOTHING");
        return;
    }
    throw new RuntimeException('Поддерживаются только SQLite и PostgreSQL.');
}

function wallet_setting(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare('SELECT setting_value FROM wallet_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function wallet_balance(PDO $db, string $email): int
{
    $stmt = $db->prepare('SELECT coins FROM wallet_accounts WHERE user_email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $balance = $stmt->fetchColumn();
    return $balance === false ? 0 : (int) $balance;
}

/** Must be called inside a DB transaction. Unique idempotency key prevents duplicate credits. */
function wallet_post(PDO $db, string $email, int $delta, string $type, string $key, string $description, ?string $admin = null): void
{
    if ($delta === 0 || !in_array($type, ['topup','purchase','promo','admin_adjustment','refund'], true)) {
        throw new InvalidArgumentException('Некорректная операция с балансом.');
    }
    $email = strtolower(trim($email));
    $insert = $db->prepare('INSERT INTO wallet_transactions (user_email, type, coins_delta, idempotency_key, description, admin_user) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$email, $type, $delta, $key, $description, $admin]);

    if ($delta < 0) {
        $update = $db->prepare('UPDATE wallet_accounts SET coins = coins + ?, updated_at = CURRENT_TIMESTAMP WHERE user_email = ? AND coins >= ?');
        $update->execute([$delta, $email, -$delta]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('Недостаточно монет.');
        }
    } else {
        $upsert = $db->prepare('INSERT INTO wallet_accounts(user_email, coins) VALUES (?, ?) ON CONFLICT(user_email) DO UPDATE SET coins = wallet_accounts.coins + excluded.coins, updated_at = CURRENT_TIMESTAMP');
        $upsert->execute([$email, $delta]);
    }
}
