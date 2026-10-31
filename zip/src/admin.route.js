export default function registerAdmin(app, adminGuard, path) {

    app.get('/admin', adminGuard, (req, res) => {
        res.sendFile(path.join(process.cwd(), 'public/admin.html'));
    });

    app.get('/admin/', adminGuard, (req, res) => {
        res.sendFile(path.join(process.cwd(), 'public/admin.html'));
    });

}
