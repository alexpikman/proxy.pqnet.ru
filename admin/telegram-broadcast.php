<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/telegram.php';
if(empty($_SESSION['is_admin'])){header('Location: /index.php');exit;}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
telegram_ensure_schema($db);
$flash='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $provided=(string)($_POST['csrf_token']??'');
 if(!hash_equals((string)$_SESSION['csrf_token'],$provided)){http_response_code(403);$flash='Проверка безопасности не пройдена.';}
 else{
  $message=trim((string)($_POST['message']??''));$target=(string)($_POST['target']??'enabled');
  if($message===''||mb_strlen($message)>4000)$flash='Сообщение должно содержать от 1 до 4000 символов.';
  elseif(!in_array($target,['enabled','all'],true))$flash='Неверная аудитория.';
  else{
   $where=$target==='enabled'?'WHERE enabled=1':'';
   $stmt=$db->query('SELECT user_email,chat_id FROM telegram_links '.$where.' ORDER BY user_email');
   $insert=$db->prepare("INSERT INTO telegram_messages(user_email,chat_id,message,status) VALUES(?,?,?,'pending')");
   $count=0;while($row=$stmt->fetch(PDO::FETCH_ASSOC)){$insert->execute([$row['user_email'],$row['chat_id'],$message]);$count++;}
   $flash='В очередь добавлено получателей: '.$count.'. Для доставки должен работать cron-worker.';
  }
 }
}
$csrf=htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8');
$flash=htmlspecialchars($flash,ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram-рассылка — PQNET</title><style>body{font:16px system-ui,sans-serif;background:#f3f6fb;color:#172033;margin:0}.wrap{max-width:760px;margin:35px auto;padding:0 16px}.card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:22px}textarea,select,button{font:inherit;padding:10px;border:1px solid #cdd5e1;border-radius:8px;width:100%;box-sizing:border-box}textarea{min-height:180px}button{background:#185abd;color:#fff;border:0;margin-top:12px;cursor:pointer}.flash{white-space:pre-wrap;margin:14px 0}</style></head><body><main class="wrap"><h1>Telegram-рассылка</h1><p>Отправка только пользователям, которые сами привязали бота. Сообщения сначала ставятся в очередь.</p><div class="flash"><?=$flash?></div><section class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><label for="target">Получатели</label><select name="target" id="target"><option value="enabled">Только включившие уведомления</option><option value="all">Все привязанные аккаунты</option></select><p><label for="message">Текст сообщения</label></p><textarea id="message" name="message" maxlength="4000" required></textarea><button type="submit">Поставить в очередь</button></form></section><p><a href="/admin.php">Вернуться в админку</a></p></main></body></html>
