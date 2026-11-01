import { authMiddleware } from '../middleware/auth.middleware.js';
import { getClientFromXui } from '../services/xui.service.js';
import db from '../db/sqlite.js';
export default function(router) {
    router.get('/user-data', authMiddleware, (req, res) => {
        const user = db.prepare('SELECT uuid, login, coins, localstatus as status, localexpire as expire, email, phone, device, price_per_day, vip FROM users WHERE uuid = ?').get(req.userUuid);
        if (!user) return res.status(404).json({ error: 'User not found' });
        const xui = getClientFromXui(user.uuid);
        const subId = xui?.subId || user.uuid?.substring(0,8);
        res.json({
            ...user,
            subscription: {
                subId, link: `https://proxy.pqnet.ru:2096/pro/${subId}`,
                status: xui?.enable ? 'active' : 'disabled',
                expiry: xui?.expiryTime ? new Date(xui.expiryTime*1000).toISOString() : null,
                upload: xui?.upload || 0, download: xui?.download || 0
            }
        });
    });
}
