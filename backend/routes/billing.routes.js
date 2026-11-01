import { authMiddleware } from '../middleware/auth.middleware.js';
import { buyDays } from '../services/billing.service.js';
import { updateClientExpiry, updateClientEnable } from '../services/xui.service.js';
import db from '../db/sqlite.js';
export default function(router) {
    router.post('/billing/buy-days', authMiddleware, (req, res) => {
        const { days } = req.body;
        if (!days || days < 1) return res.status(400).json({ error: 'Invalid days' });
        try {
            const { newCoins, newExpire } = buyDays(req.userUuid, days);
            updateClientExpiry(req.userUuid, newExpire);
            updateClientEnable(req.userUuid, true);
            res.json({ success: true, newCoins, newExpire });
        } catch(e) { res.status(400).json({ error: e.message }); }
    });
    router.get('/get-transactions', authMiddleware, (req, res) => {
        const tx = db.prepare('SELECT id, type, amount, description, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC').all(req.userUuid);
        res.json({ transactions: tx });
    });
}
