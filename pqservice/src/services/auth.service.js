import db from '../config/db.js';
import { comparePassword } from '../utils/bcrypt.js';

export const login = (login, password) => {
  const user = db.prepare(
    'SELECT * FROM users WHERE login=?'
  ).get(login);

  if (!user) {
    throw new Error('Invalid credentials');
  }

  const valid = comparePassword(password, user.password_hash);

  if (!valid) {
    throw new Error('Invalid credentials');
  }

  return user;
};