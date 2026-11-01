import { getClientsFromInbound, updateClientEnable, updateClientExpiry } from '../services/xui.service.js';
import db from '../db/sqlite.js';
import bcrypt from 'bcrypt';
export default function(router) {
    router.get('/admin/users', (req, res) => {
        res.json(db.prepare('SELECT uuid, login, coins, localexpire, localstatus, email, phone, device, price_per_day, vip FROM users').all());
    });
    router.post('/admin/user', (req, res) => {
        const { uuid, login, password, coins, localstatus, localexpire, email, phone, device, price_per_day, vip } = req.body;
        if (!uuid || !login) return res.status(400).json({ error: 'uuid and login required' });
        let hash = password ? bcrypt.hashSync(password,10) : (db.prepare('SELECT password_hash FROM users WHERE uuid=?').get(uuid)?.password_hash || bcrypt.hashSync('changeme',10));
        db.prepare(`INSERT OR REPLACE INTO users (uuid,login,password_hash,coins,localexpire,localstatus,email,phone,device,price_per_day,vip) VALUES(?,?,?,?,?,?,?,?,?,?,?)`)
          .run(uuid,login,hash,coins||0,localexpire||0,localstatus||'active',email||null,phone||null,device||null,price_per_day||null,vip||0);
        updateClientEnable(uuid, localstatus === 'active');
        if (localexpire !== undefined) updateClientExpiry(uuid, localexpire);
        res.json({ success: true });
    });
    router.delete('/admin/user/:uuid', (req, res) => {
        db.prepare('DELETE FROM users WHERE uuid=?').run(req.params.uuid);
        updateClientEnable(req.params.uuid, false);
        res.json({ success: true });
    });
    router.get('/admin/get-clients-from-3xui', (req, res) => {
        const clients = getClientsFromInbound();
        const local = new Set(db.prepare('SELECT uuid FROM users').all().map(u=>u.uuid));
        res.json(clients.map(c=>({...c, inLocalDb: local.has(c.id)})));
    });
    router.post('/admin/sync-client', (req, res) => {
        const { uuid, login, password, coins, localexpire, localstatus, email, phone, device, price_per_day, vip } = req.body;
        if (!uuid || !login || !password) return res.status(400).json({ error: 'Missing fields' });
        const hash = bcrypt.hashSync(password,10);
        db.prepare(`INSERT OR REPLACE INTO users (uuid,login,password_hash,coins,localexpire,localstatus,email,phone,device,price_per_day,vip) VALUES(?,?,?,?,?,?,?,?,?,?,?)`)
          .run(uuid,login,hash,coins||0,localexpire||0,localstatus||'active',email||null,phone||null,device||null,price_per_day||null,vip||0);
        updateClientEnable(uuid, true);
        if (localexpire) updateClientExpiry(uuid, localexpire);
        res.json({ success: true });
    });
}
