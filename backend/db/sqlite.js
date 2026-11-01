import Database from 'better-sqlite3';
import { migrate } from './migrations.js';
migrate();
const db = new Database(process.env.LOCAL_DB_PATH || '/var/pqservice/data/database.sqlite');
export default db;
