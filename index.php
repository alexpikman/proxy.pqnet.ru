<?php
include_once __DIR__ . '/header_status.php';
ini_set('display_errors', 0);
require_once __DIR__ . '/db.php';

if (isset($_SESSION['is_admin'])) {
    header('Location: admin.php');
    exit;
}
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация — Proxy PQNET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            width: 100%;
            max-width: 400px;
            padding: 2.5rem;
        }
        .form-control-light {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            padding: 0.625rem 0.875rem;
        }
        .form-control-light:focus {
            background-color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .btn-primary-custom {
            background-color: #2563eb;
            border: none;
            padding: 0.625rem;
            font-weight: 500;
        }
        .btn-primary-custom:hover {
            background-color: #1d4ed8;
        }
    </style>
</head>
<body>
<?php renderServerHeaderStatus(); ?>
<div class="login-card">
    <div class="text-center mb-4">
        <h4 class="fw-bold mb-1" style="color: #0f172a;">Вход в систему</h4>
        <p class="text-muted small">Введите ваши учётные данные</p>
    </div>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger py-2 px-3 small border-0 text-center mb-3" style="background-color: #fef2f2; color: #dc2626;">
            Неверный логин или пароль
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <div class="mb-3">
            <label class="form-label small fw-medium text-secondary">Логин / Email</label>
            <input type="text" name="email" class="form-control form-control-light" required placeholder="admin или email">
        </div>
        <div class="mb-4">
            <label class="form-label small fw-medium text-secondary">Пароль</label>
            <input type="password" name="password" class="form-control form-control-light" required>
        </div>
        <button type="submit" class="btn btn-primary btn-primary-custom w-100">Войти</button>
    </form>
</div>
</body>
</html>
