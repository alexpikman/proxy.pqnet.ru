import Database from 'better-sqlite3';
import dotenv from 'dotenv';

dotenv.config();

const db = new Database(process.env.DB_PATH);

// включаем WAL для стабильности
db.pragma('journal_mode = WAL');

export default db;
