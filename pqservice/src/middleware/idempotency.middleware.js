import db from '../config/db.js';

export const idempotencyMiddleware = (req, res, next) => {
  const key = req.headers['idempotency-key'];

  if (!key) {
    return res.status(400).json({
      error: 'Idempotency-Key header required'
    });
  }

  const existing = db.prepare(
    'SELECT response FROM idempotency_keys WHERE key=?'
  ).get(key);

  if (existing) {
    return res.json(JSON.parse(existing.response));
  }

  req.idempotencyKey = key;
  next();
};