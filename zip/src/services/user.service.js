import db from '../config/db.js';
import * as xui from './xui.service.js';

export const updateUser = async (data) => {
  const user = db.prepare(
    'SELECT * FROM users WHERE uuid=?'
  ).get(data.uuid);

  if (!user) {
    throw new Error('User not found');
  }

  const tx = db.transaction(() => {

    // 1. обновляем 3X-UI ПЕРВЫМ
    const xuiResult = xui.updateClient(user.subId, {
      email: data.email,
      password: data.password
    });

    if (!xuiResult) {
      throw new Error('XUI sync failed');
    }

    // 2. локальное обновление
    db.prepare(`
      UPDATE users SET
        email=?,
        login=?,
        password_hash=COALESCE(?, password_hash),
        coins=?,
        price_per_day=?,
        device=?,
        contact=?,
        vip=?,
        updated_at=?
      WHERE uuid=?
    `).run(
      data.email,
      data.login,
      data.password_hash,
      data.coins,
      data.price_per_day,
      data.device,
      data.contact,
      data.vip,
      Date.now(),
      data.uuid
    );
  });

  tx();

  return { success: true };
};