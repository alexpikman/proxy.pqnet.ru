import db from '../config/db.js';
import * as xui from './xui.service.js';

export const checkAllUsers = async () => {
  const users = db.prepare('SELECT * FROM users').all();

  const issues = [];

  for (const u of users) {
    const remote = await xui.getClient(u.subId);

    if (!remote) {
      issues.push({
        user_uuid: u.uuid,
        type: 'missing_xui',
        details: 'User not found in XUI'
      });
      continue;
    }

    if (
      remote.enable !== (u.localstatus === 'active' ? 1 : 0) ||
      remote.expiryTime !== u.localexpire
    ) {
      issues.push({
        user_uuid: u.uuid,
        type: 'mismatch',
        details: 'Status or expiry mismatch'
      });
    }
  }

  return issues;
};

export const saveIssues = (issues) => {
  const insert = db.prepare(`
    INSERT INTO desync_issues (user_uuid, type, details, detected_at)
    VALUES (?, ?, ?, ?)
  `);

  const now = Date.now();

  const tx = db.transaction(() => {
    db.prepare('DELETE FROM desync_issues').run();

    for (const i of issues) {
      insert.run(i.user_uuid, i.type, i.details, now);
    }
  });

  tx();
};