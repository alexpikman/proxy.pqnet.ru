<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/mattermost.php';
if (empty($_SESSION['is_admin'])) { header('Location: /index.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$message = '';
$ok = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $provided = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $provided)) {
        http_response_code(403);
        $message = 'Проверка безопасности не пройдена.';
    } else {
        try {
            mattermost_send("Тестовое уведомление: интеграция PQNET → Mattermost работает.\nВремя: ".date(DATE_ATOM));
            $message = 'Тестовое сообщение отправлено. Проверьте канал Mattermost.';
            $ok = true;
        } catch (Throwable $e) {
            error_log('Mattermost test failed: '.get_class($e).' '.substr($e->getMessage(), 0, 200));
            $message = 'Не удалось отправить тестовое сообщение. Проверьте настройку MATTERMOST_WEBHOOK_URL и серверный журнал.';
        }
    }
}
$csrf = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mattermost — PQNET</title><style>body{font:16px system-ui,sans-serif;background:#f3f6fb;color:#172033;margin:0}.wrap{max-width:700px;margin:36px auto;padding:0 16px}.card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:22px}.flash{padding:12px 0;color:<?= $ok ? "'#087443'" : "'#b42318'" ?>}button{font:inherit;padding:10px 14px;border:0;border-radius:8px;background:#185abd;color:white;cursor:pointer}a{color:#185abd}.muted{color:#667085}</style></head><body><main class="wrap"><h1>Mattermost</h1><section class="card"><p>Отправка тестового сообщения через Incoming Webhook. Секретный URL хранится только в переменной окружения сервера.</p><?php if ($message !== ''): ?><div class="flash"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><button type="submit">Отправить тестовое сообщение</button></form></section><p><a href="/admin.php">Вернуться в админку</a></p></main></body></html>
