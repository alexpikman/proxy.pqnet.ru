<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/telegram.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function telegram_link_reply(int $status,array $data): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if(empty($_SESSION['user'])) telegram_link_reply(401,['success'=>false,'message'=>'Необходимо войти в аккаунт.']);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');telegram_link_reply(405,['success'=>false,'message'=>'Метод не поддерживается.']);}
$csrf=(string)($_SESSION['csrf_token']??'');$given=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
if($csrf===''||$given===''||!hash_equals($csrf,$given))telegram_link_reply(403,['success'=>false,'message'=>'Проверка безопасности не пройдена.']);
try {
 telegram_ensure_schema($db);
 $username=(string)getenv('TELEGRAM_BOT_USERNAME');
 if($username===''||!preg_match('/^[A-Za-z0-9_]{5,32}$/',$username))telegram_link_reply(503,['success'=>false,'message'=>'Имя Telegram-бота не настроено.']);
 $token=bin2hex(random_bytes(24));$email=strtolower(trim((string)$_SESSION['user']));
 $db->prepare('DELETE FROM telegram_link_tokens WHERE expires_at < ?')->execute([time()]);
 $db->prepare('DELETE FROM telegram_link_tokens WHERE user_email = ?')->execute([$email]);
 $stmt=$db->prepare('INSERT INTO telegram_link_tokens(token_hash,user_email,expires_at) VALUES(?,?,?)');
 $stmt->execute([hash('sha256',$token),$email,time()+600]);
 telegram_link_reply(200,['success'=>true,'url'=>'https://t.me/'.$username.'?start=link_'.$token,'expires_in'=>600,'message'=>'Откройте ссылку в течение 10 минут и нажмите Start.']);
} catch(Throwable $e){error_log('Telegram link token creation failed: '.get_class($e));telegram_link_reply(500,['success'=>false,'message'=>'Не удалось создать ссылку привязки.']);}
