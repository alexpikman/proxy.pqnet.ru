import db from '../db/sqlite.js';
export function buyDays(uuid, days, globalPrice = 7) {
    const user = db.prepare('SELECT coins, localexpire, vip, price_per_day FROM users WHERE uuid = ?').get(uuid);
    if (!user) throw new Error('User not found');
    let cost = days * (user.price_per_day || globalPrice);
    if (user.vip === 1) cost = 0;
    if (user.coins < cost) throw new Error('Not enough coins');
    const newCoins = user.coins - cost;
    const now = Math.floor(Date.now() / 1000);
    const newExpire = Math.max(now, user.localexpire || now) + days * 86400;
    db.transaction(() => {
        db.prepare('UPDATE users SET coins = ?, localexpire = ? WHERE uuid = ?').run(newCoins, newExpire, uuid);
        db.prepare('INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, ?, ?, ?, ?)')
          .run(uuid, 'spend', cost, `Покупка ${days} дней`, now);
    })();
    return { newCoins, newExpire };
}
