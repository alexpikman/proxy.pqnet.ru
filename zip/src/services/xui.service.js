import Database from 'better-sqlite3';
const XUI_DB_PATH = '/etc/x-ui/x-ui.db';
const INBOUND_ID = 10;

export function getClientsFromInbound() {
    try {
        const db = new Database(XUI_DB_PATH);
        const inbound = db.prepare('SELECT settings FROM inbounds WHERE id = ?').get(INBOUND_ID);
        if (!inbound) return [];
        const settings = JSON.parse(inbound.settings);
        const clients = settings.clients || [];
        db.close();
        return clients.map(c => ({
            id: c.id,
            email: c.email,
            enable: c.enable,
            expiryTime: c.expiryTime,
            subId: c.subId || (c.id ? c.id.substring(0,8) : '')
        }));
    } catch(e) { console.error('getClientsFromInbound error:', e); return []; }
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
        try {
            traffic = db.prepare('SELECT up, down, expiry_time, enable FROM client_traffics WHERE email = ?').get(client.email);
        } catch(e) { /* ignore */ }
        db.close();
        return {
            id: client.id,
            email: client.email,
            enable: client.enable,
            expiryTime: client.expiryTime,
            subId: client.subId || (client.id ? client.id.substring(0,8) : ''),
            upload: traffic?.up || 0,
            download: traffic?.down || 0
        };
    } catch(e) { console.error('getClientFromXui error:', e); return null; }
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
        if (client.email) {
            db.prepare('UPDATE client_traffics SET enable = ? WHERE email = ?').run(enable ? 1 : 0, client.email);
        }
        db.close();
        return true;
    } catch(e) { return false; }
}

export function updateClientExpiry(uuid, expiryTimestampSec) {
    try {
        const db = new Database(XUI_DB_PATH);
        const inbound = db.prepare('SELECT settings FROM inbounds WHERE id = ?').get(INBOUND_ID);
        if (!inbound) return false;
        let settings = JSON.parse(inbound.settings);
        const client = settings.clients?.find(c => c.id === uuid);
        if (!client) return false;
        client.expiryTime = expiryTimestampSec;
        db.prepare('UPDATE inbounds SET settings = ? WHERE id = ?').run(JSON.stringify(settings), INBOUND_ID);
        if (client.email) {
            db.prepare('UPDATE client_traffics SET expiry_time = ? WHERE email = ?').run(expiryTimestampSec, client.email);
        }
        db.close();
        return true;
    } catch(e) { return false; }
}
