import Database from 'better-sqlite3';
import path from 'path';
const dbPath = path.join(process.cwd(), 'db', 'database.sqlite');
const db = new Database(dbPath);
export default db;
