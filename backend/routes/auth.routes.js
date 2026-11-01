import bcrypt from 'bcrypt';
import db from '../db/sqlite.js';
export default function(router) {
    router.post('/login', (req, res) => {
        const { login, password } = req.body;
        const user = db.prepare('SELECT uuid, password_hash FROM users WHERE login = ?').get(login);
        if (!user || !bcrypt.compareSync(password, user.password_hash)) return res.status(401).json({ success: false });
        res.cookie('session', user.uuid, { httpOnly: true });
        res.json({ success: true, login });
    });
}
