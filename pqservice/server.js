import express from 'express';
import cookieParser from 'cookie-parser';
import Database from 'better-sqlite3';
import bcrypt from 'bcrypt';
import crypto from 'crypto';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';
import os from 'os';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();
app.use(express.json());
app.use(cookieParser());
app.use(express.static(path.join(__dirname, 'public')));

const db = new Database('/var/pqservice/data/database.sqlite');

// === МИГРАЦИИ ===
db.exec(`
    CREATE TABLE IF NOT EXISTS users (
        uuid TEXT PRIMARY KEY,
        login TEXT UNIQUE,
        password_hash TEXT,
        coins INTEGER DEFAULT 0,
        localstatus TEXT DEFAULT 'active',
        localexpire INTEGER DEFAULT 0,
        email TEXT,
        phone TEXT,
        device TEXT,
        price_per_day INTEGER,
        vip INTEGER DEFAULT 0,
        session_token TEXT,
        role TEXT DEFAULT 'user',
        days_left INTEGER DEFAULT NULL,
        error_count INTEGER DEFAULT 0,
        xui_email TEXT,
        xui_inbound_id INTEGER,
        xui_client_id TEXT
    );
    CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        type TEXT,
        amount INTEGER,
        description TEXT,
        created_at INTEGER
    );
`);
const cols = db.prepare("PRAGMA table_info(users)").all().map(c => c.name);
if (!cols.includes('xui_email')) db.exec("ALTER TABLE users ADD COLUMN xui_email TEXT");
if (!cols.includes('xui_inbound_id')) db.exec("ALTER TABLE users ADD COLUMN xui_inbound_id INTEGER");
if (!cols.includes('xui_client_id')) db.exec("ALTER TABLE users ADD COLUMN xui_client_id TEXT");
if (!cols.includes('days_left')) db.exec("ALTER TABLE users ADD COLUMN days_left INTEGER DEFAULT NULL");
if (!cols.includes('error_count')) db.exec("ALTER TABLE users ADD COLUMN error_count INTEGER DEFAULT 0");
if (!cols.includes('role')) db.exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'");
if (!cols.includes('session_token')) db.exec("ALTER TABLE users ADD COLUMN session_token TEXT");
if (!cols.includes('price_per_day')) db.exec("ALTER TABLE users ADD COLUMN price_per_day INTEGER");

// Создаём администратора
const adminHash = bcrypt.hashSync('Aa19201905Zz', 10);
const existing = db.prepare("SELECT * FROM users WHERE login = ?").get('admin');
if (!existing) {
    db.prepare("INSERT INTO users (uuid, login, password_hash, coins, localstatus, localexpire, role) VALUES (?, ?, ?, 10000, 'active', 9999999999, 'admin')")
        .run('admin-uuid', 'admin', adminHash);
}

// === АУТЕНТИФИКАЦИЯ ===
function authenticate(req, res, next) {
    const token = req.cookies.session_token;
    if (!token) return res.status(401).json({ error: 'Unauthorized' });
    const user = db.prepare('SELECT * FROM users WHERE session_token = ?').get(token);
    if (!user) return res.status(401).json({ error: 'Invalid session' });
    req.user = user;
    next();
}

// === API ===
app.post('/api/login', (req, res) => {
    const { login, password } = req.body;
    const user = db.prepare('SELECT * FROM users WHERE login = ?').get(login);
    if (!user || !bcrypt.compareSync(password, user.password_hash)) {
        return res.status(401).json({ success: false, message: 'Неверный логин или пароль' });
    }
    const token = crypto.randomBytes(32).toString('hex');
    db.prepare('UPDATE users SET session_token = ? WHERE uuid = ?').run(token, user.uuid);
    res.cookie('session_token', token, { httpOnly: true, maxAge: 7 * 24 * 60 * 60 * 1000, sameSite: 'lax', secure: false });
    res.json({ success: true, role: user.role });
});

app.post('/api/logout', (req, res) => {
    const token = req.cookies.session_token;
    if (token) db.prepare('UPDATE users SET session_token = NULL WHERE session_token = ?').run(token);
    res.clearCookie('session_token');
    res.json({ success: true });
});

app.get('/api/profile', authenticate, (req, res) => {
    const { login, coins, localstatus, localexpire, vip, role, days_left, error_count, price_per_day, email } = req.user;
    res.json({ login, coins, localstatus, localexpire, vip, role, days_left, error_count, price_per_day, email });
});

app.post('/api/change-password', authenticate, (req, res) => {
    const { oldPassword, newPassword } = req.body;
    if (!bcrypt.compareSync(oldPassword, req.user.password_hash)) {
        return res.status(400).json({ success: false, message: 'Неверный старый пароль' });
    }
    const newHash = bcrypt.hashSync(newPassword, 10);
    db.prepare('UPDATE users SET password_hash = ? WHERE uuid = ?').run(newHash, req.user.uuid);
    res.json({ success: true });
});

app.post('/api/set-profile-status', authenticate, (req, res) => {
    const { status } = req.body;
    if (!['active', 'disabled'].includes(status)) return res.status(400).json({ error: 'Invalid status' });
    db.prepare('UPDATE users SET localstatus = ? WHERE uuid = ?').run(status, req.user.uuid);
    res.json({ success: true });
});

app.post('/api/set-subscription-status', authenticate, (req, res) => {
    const { active } = req.body;
    db.prepare('UPDATE users SET localstatus = ? WHERE uuid = ?').run(active ? 'active' : 'disabled', req.user.uuid);
    res.json({ success: true });
});

app.post('/api/exchange-unused-days', authenticate, (req, res) => {
    const { days } = req.body;
    const user = req.user;
    const current = user.days_left || 0;
    if (days <= 0 || days > current) return res.status(400).json({ error: 'Invalid days' });
    db.prepare('UPDATE users SET days_left = ? WHERE uuid = ?').run(current - days, user.uuid);
    db.prepare('INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, ?, ?, ?, ?)')
        .run(user.uuid, 'exchange_days', 0, `Обмен ${days} дней (без монет)`, Date.now());
    res.json({ success: true, days_removed: days });
});

app.post('/api/buy-days', authenticate, (req, res) => {
    const { days, cost } = req.body;
    const user = req.user;
    if (user.coins < cost) return res.status(400).json({ error: 'Not enough coins' });
    const newCoins = user.coins - cost;
    const newExpire = Math.max(user.localexpire, Math.floor(Date.now() / 1000)) + days * 86400;
    const newDaysLeft = (user.days_left || 0) + days;
    db.prepare('UPDATE users SET coins = ?, localexpire = ?, days_left = ? WHERE uuid = ?')
        .run(newCoins, newExpire, newDaysLeft, user.uuid);
    db.prepare('INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, ?, ?, ?, ?)')
        .run(user.uuid, 'buy_days', -cost, `Покупка ${days} дней`, Date.now());
    res.json({ success: true });
});

app.get('/api/transactions', authenticate, (req, res) => {
    const stmt = db.prepare(`SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50`);
    res.json(stmt.all(req.user.uuid));
});

// === ЗАГЛУШКИ ПОДПИСКИ ===
app.get('/api/subscription-details', authenticate, (req, res) => {
    res.json({ id: '—', totalLimit: '∞', expiryTime: null, total: 0, up: 0, down: 0, allTime: 0, link: '' });
});
app.get('/api/subscription-link', authenticate, (req, res) => {
    res.json({ link: '' });
});

// === АДМИНСКИЕ ЭНДПОИНТЫ ===
app.get('/admin/users', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json(db.prepare('SELECT * FROM users').all());
});
app.get('/admin/user/:uuid', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const user = db.prepare('SELECT * FROM users WHERE uuid = ?').get(req.params.uuid);
    if (!user) return res.status(404).json({ error: 'Not found' });
    res.json(user);
});
app.post('/admin/user/create', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const { login, password, coins, vip, localstatus, localexpire, email, phone, price_per_day } = req.body;
    if (!login || !password) return res.status(400).json({ error: 'Login and password required' });
    if (db.prepare('SELECT * FROM users WHERE login = ?').get(login)) return res.status(400).json({ error: 'User already exists' });
    const uuid = crypto.randomUUID();
    const hash = bcrypt.hashSync(password, 10);
    db.prepare(`INSERT INTO users (uuid, login, password_hash, coins, vip, localstatus, localexpire, email, phone, price_per_day, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user')`)
        .run(uuid, login, hash, coins || 0, vip || 0, localstatus || 'active', localexpire || 0, email || null, phone || null, price_per_day || null);
    res.json({ success: true, uuid });
});
app.post('/admin/user/update', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const { uuid, login, coins, vip, localstatus, localexpire, email, phone, price_per_day, password } = req.body;
    if (!uuid) return res.status(400).json({ error: 'UUID required' });
    let updates = [], params = [];
    if (login !== undefined) { updates.push('login = ?'); params.push(login); }
    if (coins !== undefined) { updates.push('coins = ?'); params.push(coins); }
    if (vip !== undefined) { updates.push('vip = ?'); params.push(vip); }
    if (localstatus !== undefined) { updates.push('localstatus = ?'); params.push(localstatus); }
    if (localexpire !== undefined) { updates.push('localexpire = ?'); params.push(localexpire); }
    if (email !== undefined) { updates.push('email = ?'); params.push(email); }
    if (phone !== undefined) { updates.push('phone = ?'); params.push(phone); }
    if (price_per_day !== undefined) { updates.push('price_per_day = ?'); params.push(price_per_day); }
    if (password) { updates.push('password_hash = ?'); params.push(bcrypt.hashSync(password, 10)); }
    if (updates.length === 0) return res.status(400).json({ error: 'No fields to update' });
    params.push(uuid);
    db.prepare(`UPDATE users SET ${updates.join(', ')} WHERE uuid = ?`).run(...params);
    res.json({ success: true });
});
app.post('/admin/user/delete', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    db.prepare('DELETE FROM users WHERE uuid = ?').run(req.body.uuid);
    res.json({ success: true });
});
app.post('/admin/user/create-from-xui', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const { login, password, coins, vip, localstatus, localexpire, email, phone, price_per_day, xui_email, xui_inbound_id, xui_client_id } = req.body;
    if (!login || !password) return res.status(400).json({ error: 'Login and password required' });
    if (db.prepare('SELECT * FROM users WHERE login = ?').get(login)) return res.status(400).json({ error: 'User already exists' });
    const uuid = crypto.randomUUID();
    const hash = bcrypt.hashSync(password, 10);
    db.prepare(`INSERT INTO users (uuid, login, password_hash, coins, vip, localstatus, localexpire, email, phone, price_per_day, role, xui_email, xui_inbound_id, xui_client_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user', ?, ?, ?)`)
        .run(uuid, login, hash, coins || 0, vip || 0, localstatus || 'active', localexpire || 0, email || null, phone || null, price_per_day || null, xui_email || null, xui_inbound_id || null, xui_client_id || null);
    res.json({ success: true, uuid });
});

// === ЗАГЛУШКИ 3X-UI ===
app.get('/admin/inbounds', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json([{ id: 10, remark: 'Тестовый inbound (заглушка)', port: 443 }]);
});
app.get('/admin/inbound-clients', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const inboundId = req.query.inboundId;
    res.json({ inboundId, clients: [{ email: 'test@example.com', enable: true, id: 'test-uuid' }] });
});
app.post('/admin/client/update-status', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json({ success: true });
});

// === НАСТРОЙКИ И МОНИТОРИНГ ===
app.get('/admin/settings', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json({ XUI_HOST: '', XUI_USERNAME: '', SUB_PORT: 2096, SUB_PATH: '/sub/' });
});
app.post('/admin/settings', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json({ success: true });
});
app.post('/admin/restart-service', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json({ success: true, message: 'Функция временно отключена' });
});
app.get('/admin/system-info', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const cpus = os.cpus();
    const totalMem = os.totalmem();
    const freeMem = os.freemem();
    const usedMem = totalMem - freeMem;
    const loadAvg = os.loadavg();
    const uptime = os.uptime();
    res.json({
        hostname: os.hostname(),
        platform: os.platform(),
        arch: os.arch(),
        cpus: cpus.length,
        cpuModel: cpus[0]?.model,
        loadAvg: { "1min": loadAvg[0], "5min": loadAvg[1], "15min": loadAvg[2] },
        memory: { total: totalMem, used: usedMem, free: freeMem, percent: (usedMem / totalMem * 100).toFixed(1) },
        uptime: uptime,
        uptimeHuman: formatUptime(uptime)
    });
});

// === ЛОГИ ===
const logFiles = [
    { name: 'Ошибки сервера', path: '/root/.pm2/logs/pqservice-error.log' },
    { name: 'Вывод сервера', path: '/root/.pm2/logs/pqservice-out.log' },
    { name: 'Nginx ошибки', path: '/var/log/nginx/error.log' },
    { name: 'Nginx доступ', path: '/var/log/nginx/access.log' },
    { name: 'Системный журнал', path: '/var/log/syslog' }
];
app.get('/admin/logs/list', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    res.json(logFiles.map(l => ({ name: l.name, id: l.path })));
});
app.get('/admin/logs/content', authenticate, (req, res) => {
    if (req.user.role !== 'admin') return res.status(403).json({ error: 'Forbidden' });
    const logPath = req.query.path;
    const lines = parseInt(req.query.lines) || 200;
    if (!logPath) return res.status(400).json({ error: 'Path required' });
    if (!fs.existsSync(logPath)) return res.json({ content: 'Лог-файл не найден' });
    try {
        const content = fs.readFileSync(logPath, 'utf8');
        const linesArr = content.split('\n').filter(l => l.trim());
        const lastLines = linesArr.slice(-lines);
        res.json({ content: lastLines.join('\n'), path: logPath });
    } catch(e) {
        res.status(500).json({ error: e.message });
    }
});

function formatUptime(seconds) {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return `${days}д ${hours}ч ${minutes}м ${secs}с`;
}

app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', timestamp: Date.now() });
});
app.get('/api/footer', (req, res) => {
    try {
        const footerRow = db.prepare('SELECT content FROM footer WHERE footID = 1').get();
        res.json({ content: footerRow ? footerRow.content : '' });
    } catch(e) { res.json({ content: '' }); }
});

const PORT = 3000;
app.listen(PORT, () => console.log(`Server running on port ${PORT}`));
