import bcrypt from "bcrypt";
import db from "../db/db.js";

export const login = async (req, res) => {
  const { login, password } = req.body;

  const user = db.prepare("SELECT * FROM users WHERE login = ?").get(login);

  if (!user) return res.status(401).json({ error: "invalid" });

  const ok = bcrypt.compareSync(password, user.password_hash || "");

  if (!ok) return res.status(401).json({ error: "invalid" });

  req.session = { uuid: user.uuid };

  res.json({ success: true, login: user.login });
};
