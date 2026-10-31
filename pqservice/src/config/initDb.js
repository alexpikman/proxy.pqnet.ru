import fs from 'fs';
import db from './db.js';

export const initDb = () => {
  const schema = fs.readFileSync('./db/schema.sql', 'utf-8');
  db.exec(schema);
  console.log('Database initialized');
};