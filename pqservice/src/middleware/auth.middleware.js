import db from '../config/db.js';

export const authMiddleware = (req, res, next) => {
  const session = req.cookies.session;

  if (!session) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  const user = db.prepare(
    'SELECT * FROM users WHERE uuid=?'
  ).get(session);

  if (!user) {
    return res.status(401).json({ error: 'Invalid session' });
  }

  req.user = user;
  next();
};