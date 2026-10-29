<?php
declare(strict_types=1);
function telegram_api(string $method, array $payload = []): array {
 $token=(string)getenv('TELEGRAM_BOT_TOKEN'); if($token==='') throw new RuntimeException('Telegram token not configured.');
 if(!preg_match('/^[A-Za-z0-9_]+$/',$method)) throw new InvalidArgumentException('Invalid method.');
 $ch=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $body=curl_exec($ch); $error=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
 if(!is_string($body)) throw new RuntimeException('Telegram transport failed: '.($error?:'unknown error'));
 $data=json_decode($body,true); if(!is_array($data)||$status<200||$status>=300||($data['ok']??false)!==true) { $desc=is_array($data)&&is_string($data['description']??null)?$data['description']:'invalid Telegram response'; throw new RuntimeException('Telegram API error ('.$status.'): '.mb_substr($desc,0,250)); }
 return $data['result']??[];
}
function telegram_ensure_schema(PDO $db): void {
 $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
 if($driver==='sqlite') {
  $db->exec("CREATE TABLE IF NOT EXISTS telegram_links(user_email TEXT PRIMARY KEY,chat_id TEXT NOT NULL UNIQUE,telegram_user_id TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1,linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $db->exec("CREATE TABLE IF NOT EXISTS telegram_link_tokens(token_hash TEXT PRIMARY KEY,user_email TEXT NOT NULL,expires_at INTEGER NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $db->exec("CREATE TABLE IF NOT EXISTS telegram_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,user_email TEXT NOT NULL,chat_id TEXT NOT NULL,message TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,last_error TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at TEXT NULL)");
 } elseif($driver==='pgsql') {
  $db->exec("CREATE TABLE IF NOT EXISTS telegram_links(user_email VARCHAR(320) PRIMARY KEY,chat_id VARCHAR(64) NOT NULL UNIQUE,telegram_user_id VARCHAR(64) NOT NULL,enabled BOOLEAN NOT NULL DEFAULT TRUE,linked_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $db->exec("CREATE TABLE IF NOT EXISTS telegram_link_tokens(token_hash CHAR(64) PRIMARY KEY,user_email VARCHAR(320) NOT NULL,expires_at BIGINT NOT NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $db->exec("CREATE TABLE IF NOT EXISTS telegram_messages(id BIGSERIAL PRIMARY KEY,user_email VARCHAR(320) NOT NULL,chat_id VARCHAR(64) NOT NULL,message TEXT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,last_error TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at TIMESTAMPTZ NULL)");
 } else throw new RuntimeException('Unsupported DB driver.');
}
function telegram_queue_message(PDO $db,string $email,string $message): bool { $s=$db->prepare('SELECT chat_id FROM telegram_links WHERE user_email=? AND enabled=1');$s->execute([$email]);$chat=$s->fetchColumn();if($chat===false)return false;$i=$db->prepare("INSERT INTO telegram_messages(user_email,chat_id,message,status) VALUES(?,?,?,'pending')");$i->execute([$email,(string)$chat,$message]);return true; }
