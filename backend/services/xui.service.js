import Database from 'better-sqlite3';
import NodeCache from 'node-cache';
const cache = new NodeCache({ stdTTL: 60 });
const XUI_DB_PATH = process.env.XUI_DB_PATH || '/etc/x-ui/x-ui.db';
const INBOUND_ID = parseInt(process.env.XUI_INBOUND_ID || '10');

export function getClientsFromInbound() {
    const cached = cache.get('clients');
    if (cached) return cached;
    try {
        const db = new Database(XUI_DB_PATH);
        const inbound = db.prepare('SELECT settings FROM inbounds WHERE id = ?').get(INBOUND_ID);
        if (!inbound) return [];
        const settings = JSON.parse(inbound.settings);
        const clients = (settings.clients || []).map(c => ({
            id: c.id, email: c.email, enable: c.enable, expiryTime: c.expiryTime, subId: c.subId || c.id?.substring(0,8)
        }));
        db.close();
        cache.set('clients', clients);
        return clients;
    } catch(e) { return []; }
}

export function getClientFromXui(uuid) {
    try {
        const db = new Database(XUI_DB_PATH);
        const inbound = db.prepare('SELECT settings FROM inbounds WHERE id = ?').get(INBOUND_ID);
        if (!inbound) return null;
        const settings = JSON.parse(inbound.settings);
        const client = settings.clients?.find(c => c.id === uuid);
        if (!client) { db.close(); return null; }
        let traffic = null;
        try { traffic = db.prepare('SELECT up, down, expiry_time, enable FROM client_traffics WHERE email = ?').get(client.email); } catch(e) {}
        db.close();
        console.log(`[XUI] getClient ${uuid} expiry=${client.expiryTime} (${new Date(client.expiryTime*1000).toLocaleString()})`);
        return {
            id: client.id, email: client.email, enable: client.enable, expiryTime: client.expiryTime,
            subId: client.subId || client.id?.substring(0,8),
            upload: traffic?.up || 0, download: traffic?.down || 0
        };
    } catch(e) { return null; }
}

export function updateClientEnable(uuid, enable) {
    try {
        const db = new Database(XUI_DB_PATH);
        const inbound = db.prepare('SELECT settings FROM inbounds WHERE id = ?').get(INBOUND_ID);
        if (!inbound) return false;
        let settings = JSON.parse(inbound.settings);
        const client = settings.clients?.find(c => c.id === uuid);
        if (!client) return false;
        client.enable = enable;
        db.prepare('UPDATE inbounds SET settings = ? WHERE id = ?').run(JSON.stringify(settings), INBOUND_ID);
        if (client.email) db.prepare('UPDATE client_traffics SET enable = ? WHERE email = ?').run(enable ? 1 : 0, client.email);
        db.close();
        cache.del('clients');
        return true;
    } catch(e) { return false; }
}

export function updateClientExpiry(uuid, expiryTimestampSec) {
    try {
        // Конвертация, если пришли миллисекунды
        let expiry = expiryTimestampSec;
        if (expiry > 1e12) {
            console.log(`[XUI] Конвертируем миллисекунды в секунды: ${expiry} -> ${Math.floor(expiry/1000)}`);
            expiry = Math.floor(expiry / 1000);
        }
        if (expiry < 0) expiry = 0;
        
        const db = new Database(XUI_DB_PATH);
        const inbound = db.prepare('SELECT settings FROM inbounds WHERE id = ?').get(INBOUND_ID);
        if (!inbound) return false;
        let settings = JSON.parse(inbound.settings);
        const client = settings.clients?.find(c => c.id === uuid);
        if (!client) return false;
        client.expiryTime = expiry;
        db.prepare('UPDATE inbounds SET settings = ? WHERE id = ?').run(JSON.stringify(settings), INBOUND_ID);
        if (client.email) {
            db.prepare('UPDATE client_traffics SET expiry_time = ? WHERE email = ?').run(expiry, client.email);
        }
        db.close();
        cache.del('clients');
        console.log(`[XUI] Обновлён expiry для ${uuid}: ${expiry} (${new Date(expiry*1000).toLocaleString()})`);
        return true;
    } catch(e) { console.error('updateClientExpiry error:', e); return false; }
}
