<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notifications.php';
if (empty($_SESSION['user'])) { header('Location: /index.php'); exit; }
try {
    notifications_ensure_schema($db);
    $stmt = $db->prepare('SELECT id,type,title,body,url,created_at,read_at FROM user_notifications WHERE user_email=? ORDER BY id DESC LIMIT 100');
    $stmt->execute([strtolower(trim((string)$_SESSION['user']))]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Notification page failed: '.get_class($e)); $items=[]; $pageError=true; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf=htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Уведомления — PQNET</title><style>
body{margin:0;background:#f3f6fb;color:#172033;font:16px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:820px;margin:36px auto;padding:0 16px}.head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.card{background:#fff;border:1px solid #e1e7f0;border-radius:14px;padding:18px;margin:12px 0}.unread{border-left:4px solid #185abd}.muted{color:#667085;font-size:14px}button{font:inherit;border:0;border-radius:8px;background:#185abd;color:white;padding:9px 12px;cursor:pointer}a{color:#185abd}.title{font-weight:700;margin-bottom:6px}.body{white-space:pre-wrap;overflow-wrap:anywhere}.empty{padding:30px;text-align:center;color:#667085}
</style></head><body><main class="wrap"><div class="head"><div><h1>Уведомления</h1><p class="muted">Сообщения о балансе, подписке и работе сервиса.</p></div><button id="readAll" type="button">Отметить всё прочитанным</button></div>
<?php if (!empty($pageError)): ?><section class="card">Не удалось загрузить уведомления. Попробуйте позже.</section>
<?php elseif (!$items): ?><section class="card empty">Пока уведомлений нет.</section>
<?php else: foreach ($items as $item): ?><article class="card <?= $item['read_at']===null?'unread':'' ?>" data-id="<?= (int)$item['id'] ?>"><div class="title"><?= htmlspecialchars($item['title'],ENT_QUOTES,'UTF-8') ?></div><div class="body"><?= htmlspecialchars($item['body'],ENT_QUOTES,'UTF-8') ?></div><p class="muted"><?= htmlspecialchars((string)$item['created_at'],ENT_QUOTES,'UTF-8') ?> · <?= $item['read_at']===null?'Не прочитано':'Прочитано' ?></p><?php if ($item['url']!==''): ?><a href="<?= htmlspecialchars($item['url'],ENT_QUOTES,'UTF-8') ?>">Открыть</a><?php endif; ?><?php if ($item['read_at']===null): ?> <button class="markRead" type="button">Прочитано</button><?php endif; ?></article><?php endforeach; endif; ?>
<p><a href="/dashboard.php">Вернуться в личный кабинет</a> · <a href="/wallet-page.php">Баланс</a></p></main><script>
const csrf='<?= $csrf ?>';
async function post(action,payload={}){const r=await fetch('/api/notifications.php?action='+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'Accept':'application/json'},body:JSON.stringify(payload)});const d=await r.json();if(!r.ok||!d.success)throw new Error(d.message||'Ошибка');}
document.querySelectorAll('.markRead').forEach(b=>b.addEventListener('click',async()=>{const card=b.closest('[data-id]');b.disabled=true;try{await post('read',{id:Number(card.dataset.id)});card.classList.remove('unread');b.remove();const p=card.querySelector('.muted');if(p)p.textContent=p.textContent.replace('Не прочитано','Прочитано');}catch(e){b.disabled=false;alert(e.message);}}));
document.getElementById('readAll').addEventListener('click',async()=>{const b=document.getElementById('readAll');b.disabled=true;try{await post('read-all');document.querySelectorAll('.markRead').forEach(x=>x.remove());document.querySelectorAll('.unread').forEach(x=>{x.classList.remove('unread');const p=x.querySelector('.muted');if(p)p.textContent=p.textContent.replace('Не прочитано','Прочитано');});}catch(e){alert(e.message);}finally{b.disabled=false;}});
</script></body></html>
