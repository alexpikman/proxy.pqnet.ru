import express from 'express';
import cookieParser from 'cookie-parser';
import path from 'path';
import db from './db/db.js';
import bcrypt from 'bcrypt';
import { getClientFromXui, updateClientExpiry, updateClientEnable } from './services/xui.service.js';

const app = express();
app.use(express.json());
app.use(cookieParser());
app.use(express.static(path.join(process.cwd(), 'public')));

app.post('/login', (req, res) => {
    const { login, password } = req.body;
    const user = db.prepare('SELECT uuid, password_hash FROM users WHERE login = ?').get(login);
    if (!user) return res.status(401).json({ success: false });
    const valid = bcrypt.compareSync(password, user.password_hash);
    if (!valid) return res.status(401).json({ success: false });
    res.cookie('session', user.uuid, { httpOnly: true });
    res.json({ success: true, login });
});

app.get('/user-data', (req, res) => {
    const uuid = req.cookies.session;
    if (!uuid) return res.status(401).json({ error: 'Unauthorized' });
    const user = db.prepare('SELECT uuid, login, coins, localstatus as status, localexpire as expire, email, phone, device, price_per_day, vip FROM users WHERE uuid = ?').get(uuid);
    if (!user) return res.status(404).json({ error: 'User not found' });
    const xuiData = getClientFromXui(uuid);
    const subscription = {
        subId: xuiData?.subId || (user.uuid ? user.uuid.substring(0,8) : ''),
        link: `https://proxy.pqnet.ru:2096/pro/${xuiData?.subId || (user.uuid ? user.uuid.substring(0,8) : '')}`,
        status: xuiData?.enable ? 'active' : 'disabled',
        expiry: xuiData?.expiryTime ? new Date(xuiData.expiryTime * 1000).toISOString() : null,
        upload: xuiData?.upload || 0,
        download: xuiData?.download || 0
    };
    res.json({ ...user, subscription });
});

app.post('/billing/buy-days', (req, res) => {
    const uuid = req.cookies.session;
    if (!uuid) return res.status(401).json({ error: 'Unauthorized' });
    const { days } = req.body;
    if (!days || days < 1) return res.status(400).json({ error: 'Invalid days' });
    const user = db.prepare('SELECT coins, localexpire, vip, price_per_day, login FROM users WHERE uuid = ?').get(uuid);
    if (!user) return res.status(404).json({ error: 'User not found' });
    let cost = days * 7;
    if (user.price_per_day) cost = days * user.price_per_day;
    if (user.vip === 1) cost = 0;
    if (user.coins < cost) return res.status(400).json({ error: 'Not enough coins' });
    const newCoins = user.coins - cost;
    const nowSec = Math.floor(Date.now() / 1000);
    const currentExpire = user.localexpire || nowSec;
    const newExpire = Math.max(nowSec, currentExpire) + days * 86400;
    db.prepare('UPDATE users SET coins = ?, localexpire = ? WHERE uuid = ?').run(newCoins, newExpire, uuid);
    db.prepare('INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, ?, ?, ?, ?)').run(uuid, 'spend', cost, `Покупка ${days} дней`, nowSec);
    updateClientExpiry(uuid, newExpire);
    updateClientEnable(uuid, true);
    res.json({ success: true, newCoins, newExpire });
});

app.get('/get-transactions', (req, res) => {
    const uuid = req.cookies.session;
    if (!uuid) return res.status(401).json({ error: 'Unauthorized' });
    const transactions = db.prepare('SELECT id, type, amount, description, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC').all(uuid);
    res.json({ transactions });
});

app.post('/change-password', (req, res) => {
    const uuid = req.cookies.session;
    if (!uuid) return res.status(401).json({ error: 'Unauthorized' });
    const { oldPassword, newPassword } = req.body;
    const user = db.prepare('SELECT password_hash FROM users WHERE uuid = ?').get(uuid);
    if (!user) return res.status(404).json({ error: 'User not found' });
    if (!bcrypt.compareSync(oldPassword, user.password_hash)) return res.status(400).json({ error: 'Old password incorrect' });
    db.prepare('UPDATE users SET password_hash = ? WHERE uuid = ?').run(bcrypt.hashSync(newPassword, 10), uuid);
    res.json({ success: true });
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Server running on port ${PORT}`));
