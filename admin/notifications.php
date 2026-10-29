<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/notifications.php';
if (empty($_SESSION['is_admin'])) { header('Location: /index.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
notifications_ensure_schema($db);
$flash='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $provided=(string)($_POST['csrf_token']??'');
    if (!hash_equals((string)$_SESSION['csrf_token'],$provided)) { http_response_code(403); $flash='Проверка безопасности не пройдена.'; }
    else {
        $title=trim((string)($_POST['title']??'')); $body=trim((string)($_POST['body']??'')); $type=(string)($_POST['type']??'announcement');
        if ($title===''||mb_strlen($title)>160||$body===''||mb_strlen($body)>5000||!in_array($type,['service','billing','subscription','announcement'],true)) $flash='Проверьте заголовок, текст и тип уведомления.';
        else {
            $users=$db->query('SELECT email FROM users ORDER BY email');
            $created=0;
            while (($email=$users->fetchColumn())!==false) { if (notification_create($db,(string)$email,$title,$body,$type)) $created++; }
            $flash='Уведомление добавлено в центр уведомлений для аккаунтов: '.$created.'.';
        }
    }
}
$csrf=htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Уведомления пользователям — PQNET</title><style>body{font:16px system-ui,sans-serif;background:#f3f6fb;color:#172033;margin:0}.wrap{max-width:760px;margin:35px auto;padding:0 16px}.card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:22px}input,textarea,select,button{font:inherit;padding:10px;border:1px solid #cdd5e1;border-radius:8px;width:100%;box-sizing:border-box;margin:6px 0 14px}textarea{min-height:160px}button{background:#185abd;color:#fff;border:0;cursor:pointer}.flash{margin:14px 0;white-space:pre-wrap}</style></head><body><main class="wrap"><h1>Уведомление пользователям</h1><p>Сообщение появится в центре уведомлений после сохранения. Это не отправляет Telegram и не рассылает email.</p><div class="flash"><?=htmlspecialchars($flash,ENT_QUOTES,'UTF-8')?></div><section class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><label for="title">Заголовок</label><input id="title" name="title" maxlength="160" required><label for="type">Тип</label><select id="type" name="type"><option value="announcement">Объявление</option><option value="service">Сервис</option><option value="billing">Баланс и пополнение</option><option value="subscription">Подписка</option></select><label for="body">Текст</label><textarea id="body" name="body" maxlength="5000" required></textarea><button type="submit">Создать уведомление для всех</button></form></section><p><a href="/admin.php">Вернуться в админку</a></p></main></body></html>
