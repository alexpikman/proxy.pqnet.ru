import Database from 'better-sqlite3';
import bcrypt from 'bcrypt';
const DB_PATH = process.env.LOCAL_DB_PATH || '/var/pqservice/data/database.sqlite';
export function migrate() {
    const db = new Database(DB_PATH);
    db.exec(`
        CREATE TABLE IF NOT EXISTS users (
            uuid TEXT PRIMARY KEY,
            login TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            coins INTEGER DEFAULT 0,
            localstatus TEXT DEFAULT 'active',
            localexpire INTEGER DEFAULT 0,
            email TEXT,
            phone TEXT,
            device TEXT,
            price_per_day INTEGER,
            vip INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            type TEXT CHECK(type IN ('add','spend')) NOT NULL,
            amount INTEGER NOT NULL,
            description TEXT,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
    `);
    const test = db.prepare("SELECT uuid FROM users WHERE login = ?").get('test');
    if (!test) {
        const hash = bcrypt.hashSync('test', 10);
        db.prepare("INSERT INTO users (uuid, login, password_hash, coins, localexpire, localstatus) VALUES (?,?,?,?,?,?)")
          .run('test-uuid', 'test', hash, 100, 9999999999, 'active');
    }
    db.close();
}
