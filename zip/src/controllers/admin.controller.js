import { getClientsFromInbound } from '../services/xui.service.js';
import db from '../db/db.js';
import bcrypt from 'bcrypt';

export async function getClientsFrom3xui(req, res) {
    const clients = getClientsFromInbound();
    const localUsers = db.prepare('SELECT uuid FROM users').all();
    const localUuids = new Set(localUsers.map(u => u.uuid));
    const result = clients.map(c => ({
        ...c,
        inLocalDb: localUuids.has(c.id)
    }));
    res.json(result);
}

export async function syncClient(req, res) {
    const { uuid, login, password, coins, localexpire, localstatus } = req.body;
    if (!uuid || !login || !password) {
        return res.status(400).json({ error: 'Missing required fields' });
    }
    const passwordHash = bcrypt.hashSync(password, 10);
    const stmt = db.prepare(`
        INSERT OR REPLACE INTO users (uuid, login, password_hash, coins, localexpire, localstatus)
        VALUES (?, ?, ?, ?, ?, ?)
    `);
    stmt.run(uuid, login, passwordHash, coins || 0, localexpire || 0, localstatus || 'active');
    res.json({ success: true });
}
