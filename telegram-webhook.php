<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/telegram.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$expected=(string)getenv('TELEGRAM_WEBHOOK_SECRET');
$received=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
if($expected===''||$received===''||!hash_equals($expected,$received)){http_response_code(403);echo '{"ok":false}';exit;}
$update=json_decode((string)file_get_contents('php://input'),true);
$message=is_array($update)?($update['message']??null):null;
$text=is_array($message)?trim((string)($message['text']??'')):'';
$chatId=$message['chat']['id']??null;$fromId=$message['from']['id']??null;
if(!is_array($message)||$chatId===null||$fromId===null||$text===''||$text[0]!=='/'){echo '{"ok":true}';exit;}
try {
 telegram_ensure_schema($db);
 if(preg_match('~^/start(?:@\\w+)?(?:\\s+link_([a-f0-9]{48}))?$~i',$text,$m)){
  if(!empty($m[1])){
   $hash=hash('sha256',$m[1]);$db->beginTransaction();
   $stmt=$db->prepare('SELECT user_email,expires_at FROM telegram_link_tokens WHERE token_hash=?');$stmt->execute([$hash]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
   if(!$row||(int)$row['expires_at']<time()){
    $db->rollBack();telegram_api('sendMessage',['chat_id'=>$chatId,'text'=>'Ссылка привязки недействительна или истекла. Создайте новую в личном кабинете.']);
   }else{
    $db->prepare('DELETE FROM telegram_links WHERE user_email=? OR chat_id=?')->execute([$row['user_email'],(string)$chatId]);
    $ins=$db->prepare('INSERT INTO telegram_links(user_email,chat_id,telegram_user_id,enabled) VALUES(?,?,?,1)');
    $ins->execute([$row['user_email'],(string)$chatId,(string)$fromId]);
    $db->prepare('DELETE FROM telegram_link_tokens WHERE token_hash=?')->execute([$hash]);$db->commit();
    telegram_api('sendMessage',['chat_id'=>$chatId,'text'=>'Telegram привязан к вашему аккаунту PQNET. Для отключения уведомлений отправьте /stop.']);
   }
  }else telegram_api('sendMessage',['chat_id'=>$chatId,'text'=>'Привет! Чтобы получать уведомления PQNET, создайте ссылку привязки в личном кабинете сайта.']);
 }elseif(preg_match('~^/stop(?:@\\w+)?$~i',$text)){
  $db->prepare('UPDATE telegram_links SET enabled=0,updated_at=CURRENT_TIMESTAMP WHERE chat_id=?')->execute([(string)$chatId]);
  telegram_api('sendMessage',['chat_id'=>$chatId,'text'=>'Уведомления отключены. Создайте новую ссылку в личном кабинете, чтобы включить их снова.']);
 }elseif(preg_match('~^/status(?:@\\w+)?$~i',$text)){
  $stmt=$db->prepare('SELECT enabled FROM telegram_links WHERE chat_id=?');$stmt->execute([(string)$chatId]);$enabled=$stmt->fetchColumn();
  $state=$enabled===false?'не привязан':(((string)$enabled==='1'||$enabled===true)?'включены':'отключены');
  telegram_api('sendMessage',['chat_id'=>$chatId,'text'=>'Статус уведомлений: '.$state.'.']);
 }else telegram_api('sendMessage',['chat_id'=>$chatId,'text'=>'Команды: /start — помощь, /status — статус, /stop — отключить уведомления.']);
}catch(Throwable $e){if($db instanceof PDO&&$db->inTransaction())$db->rollBack();error_log('Telegram webhook failed: '.get_class($e));http_response_code(500);echo '{"ok":false}';exit;}
echo '{"ok":true}';
