<?php
declare(strict_types=1);
// Run by cron every minute; do not expose this script through the web server.
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/telegram.php';
telegram_ensure_schema($db);
$stmt=$db->query("SELECT id,chat_id,message,attempts FROM telegram_messages WHERE status='pending' ORDER BY id ASC LIMIT 20");
$update=$db->prepare("UPDATE telegram_messages SET status=?,attempts=attempts+1,last_error=?,sent_at=CASE WHEN ?='sent' THEN CURRENT_TIMESTAMP ELSE sent_at END WHERE id=? AND status='pending'");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
 try{
  telegram_api('sendMessage',['chat_id'=>$row['chat_id'],'text'=>$row['message']]);
  $update->execute(['sent','','sent',$row['id']]);
 }catch(Throwable $e){
  $attempts=(int)$row['attempts']+1;$error=mb_substr($e->getMessage(),0,500);
  $permanent=str_contains(strtolower($error),'bot was blocked')||str_contains(strtolower($error),'chat not found')||str_contains(strtolower($error),'user is deactivated');
  $status=($attempts>=5||$permanent)?'failed':'pending';
  $update->execute([$status,$error,'pending',$row['id']]);
  error_log('Telegram queue item '.(int)$row['id'].' failed: '.$error);
 }
 usleep(400000);
}
