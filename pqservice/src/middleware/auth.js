import db from "../db/db.js";

export const auth = (req, res, next) => {
  const cookie = req.headers.cookie || "";
  const match = cookie.match(/session=([^;]+)/);

  if (!match) return res.status(401).json({ error: "Unauthorized" });

  const uuid = match[1];

  const user = db.prepare("SELECT * FROM users WHERE uuid = ?").get(uuid);

  if (!user) return res.status(401).json({ error: "Unauthorized" });

  req.user = user;
  next();
};
