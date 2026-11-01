export function authMiddleware(req, res, next) {
    const uuid = req.cookies.session;
    if (!uuid) return res.status(401).json({ error: 'Unauthorized' });
    req.userUuid = uuid;
    next();
}
