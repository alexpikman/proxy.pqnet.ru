import express from 'express';
import { getClientsFromInbound, updateClientEnable, updateClientExpiry } from '../services/xui.service.js';
import db from '../db/db.js';
import bcrypt from 'bcrypt';

const router = express.Router();

router.get('/users', (req, res) => {
    try {
        const users = db.prepare('SELECT uuid, login, coins, localexpire, localstatus, email, phone, device, price_per_day, vip FROM users').all();
        res.json(users);
    } catch(e) { res.status(500).json({ error: e.message }); }
});

router.post('/user', (req, res) => {
    const { uuid, login, password, coins, localstatus, localexpire, email, phone, device, price_per_day, vip } = req.body;
    if (!uuid || !login) return res.status(400).json({ error: 'uuid and login required' });
    let passwordHash;
    if (password) passwordHash = bcrypt.hashSync(password, 10);
    else {
        const existing = db.prepare('SELECT password_hash FROM users WHERE uuid = ?').get(uuid);
        passwordHash = existing ? existing.password_hash : bcrypt.hashSync('changeme', 10);
    }
    const stmt = db.prepare(`INSERT OR REPLACE INTO users (uuid, login, password_hash, coins, localexpire, localstatus, email, phone, device, price_per_day, vip) VALUES (?,?,?,?,?,?,?,?,?,?,?)`);
    stmt.run(uuid, login, passwordHash, coins || 0, localexpire || 0, localstatus || 'active', email || null, phone || null, device || null, price_per_day || null, vip || 0);
    updateClientEnable(uuid, localstatus === 'active');
    if (localexpire !== undefined) updateClientExpiry(uuid, localexpire);
    res.json({ success: true });
});

router.delete('/user/:uuid', (req, res) => {
    const { uuid } = req.params;
    db.prepare('DELETE FROM users WHERE uuid = ?').run(uuid);
    updateClientEnable(uuid, false);
    res.json({ success: true });
});

router.get('/get-clients-from-3xui', (req, res) => {
    try {
        const clients = getClientsFromInbound();
        const localUsers = db.prepare('SELECT uuid FROM users').all();
        const localUuids = new Set(localUsers.map(u => u.uuid));
        res.json(clients.map(c => ({ ...c, inLocalDb: localUuids.has(c.id) })));
    } catch(e) { res.status(500).json({ error: e.message }); }
});

router.post('/sync-client', (req, res) => {
    const { uuid, login, password, coins, localexpire, localstatus, email, phone, device, price_per_day, vip } = req.body;
    if (!uuid || !login || !password) return res.status(400).json({ error: 'Missing required fields' });
    const passwordHash = bcrypt.hashSync(password, 10);
    const stmt = db.prepare(`INSERT OR REPLACE INTO users (uuid, login, password_hash, coins, localexpire, localstatus, email, phone, device, price_per_day, vip) VALUES (?,?,?,?,?,?,?,?,?,?,?)`);
    stmt.run(uuid, login, passwordHash, coins || 0, localexpire || 0, localstatus || 'active', email || null, phone || null, device || null, price_per_day || null, vip || 0);
    updateClientEnable(uuid, true);
    if (localexpire) updateClientExpiry(uuid, localexpire);
    res.json({ success: true });
});

export default router;
