export default function adminGuard(req, res, next) {
    const session = req.cookies?.session;
    if (!session) return res.status(401).send("Unauthorized");

    const db = req.app.locals.db;
    const user = db.prepare("SELECT uuid FROM users WHERE uuid = ?").get(session);

    if (!user) return res.status(403).send("Forbidden");

    next();
}
