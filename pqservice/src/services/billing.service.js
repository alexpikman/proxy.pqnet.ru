import db from '../config/db.js';
import { v4 as uuidv4 } from 'uuid';
import * as xui from './xui.service.js';

export const buyDays = async (user, days, key) => {

  // идемпотентность
  const existing = db.prepare(
    'SELECT response FROM idempotency_keys WHERE key=?'
  ).get(key);

  if (existing) {
    return JSON.parse(existing.response);
  }

  const txId = uuidv4();

  db.prepare(`
    INSERT INTO transactions (id, user_uuid, days, status, created_at)
    VALUES (?, ?, ?, 'pending', ?)
  `).run(txId, user.uuid, days, Date.now());

  try {

    // 1. сначала XUI (важно!)
    await xui.extendExpiry(user.subId, days);

    let coinsSpent = 0;

    if (!user.vip) {
      coinsSpent = days * user.price_per_day;

      if (user.coins < coinsSpent) {
        throw new Error('Not enough coins');
      }

      db.prepare(`
        UPDATE users SET coins = coins - ? WHERE uuid=?
      `).run(coinsSpent, user.uuid);
    }

    // 2. локальное обновление
    const newExpire = (user.localexpire || Date.now()) + days * 86400000;

    db.prepare(`
      UPDATE users
      SET localexpire=?, localstatus='active', updated_at=?
      WHERE uuid=?
    `).run(newExpire, Date.now(), user.uuid);

    db.prepare(`
      UPDATE transactions
      SET status='success', coins_spent=?
      WHERE id=?
    `).run(coinsSpent, txId);

    const response = {
      success: true,
      newExpire,
      coinsSpent
    };

    db.prepare(`
      INSERT INTO idempotency_keys (key, user_uuid, response, created_at)
      VALUES (?, ?, ?, ?)
    `).run(key, user.uuid, JSON.stringify(response), Date.now());

    return response;

  } catch (err) {

    db.prepare(`
      UPDATE transactions SET status='failed' WHERE id=?
    `).run(txId);

    throw err;
  }
};