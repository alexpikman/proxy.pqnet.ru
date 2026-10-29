<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function admin_wallet_reply(int $status,array $body): never { http_response_code($status); echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if (empty($_SESSION['is_admin'])) admin_wallet_reply(401,['success'=>false,'message'=>'Требуется вход администратора.']);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$method=$_SERVER['REQUEST_METHOD']??'GET';
$action=(string)($_GET['action']??'');
if ($method === 'POST') {
    $expected=(string)($_SESSION['csrf_token']??''); $provided=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if ($expected===''||$provided===''||!hash_equals($expected,$provided)) admin_wallet_reply(403,['success'=>false,'message'=>'Проверка безопасности не пройдена.']);
}
try { wallet_ensure_schema($db); } catch(Throwable $e) { error_log('Admin wallet schema failed: '.get_class($e)); admin_wallet_reply(500,['success'=>false,'message'=>'Кошелёк временно недоступен.']); }
if($method==='GET'&&$action==='requests'){
    $status=(string)($_GET['status']??'pending');
    if(!in_array($status,['pending','approved','rejected','all'],true)) admin_wallet_reply(422,['success'=>false,'message'=>'Некорректный статус.']);
    if($status==='all') $stmt=$db->query('SELECT * FROM topup_requests ORDER BY id DESC LIMIT 500');
    else { $stmt=$db->prepare('SELECT * FROM topup_requests WHERE status = ? ORDER BY id DESC LIMIT 500'); $stmt->execute([$status]); }
    admin_wallet_reply(200,['success'=>true,'requests'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'csrf_token'=>$_SESSION['csrf_token']]);
}
if($method==='GET'&&$action==='settings'){
    admin_wallet_reply(200,['success'=>true,'coins_per_ruble'=>(int)wallet_setting($db,'coins_per_ruble','1'),'price_per_day'=>(int)wallet_setting($db,'price_per_day','10'),'csrf_token'=>$_SESSION['csrf_token']]);
}
if($method!=='POST'){header('Allow: GET, POST');admin_wallet_reply(405,['success'=>false,'message'=>'Метод не поддерживается.']);}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input)) admin_wallet_reply(400,['success'=>false,'message'=>'Некорректный JSON.']);
if($action==='settings'){
    $rate=filter_var($input['coins_per_ruble']??null,FILTER_VALIDATE_INT);
    $price=filter_var($input['price_per_day']??null,FILTER_VALIDATE_INT);
    if(!is_int($rate)||$rate<1||$rate>100000||!is_int($price)||$price<1||$price>1000000) admin_wallet_reply(422,['success'=>false,'message'=>'Курс и цена должны быть положительными целыми числами в допустимом диапазоне.']);
    $db->beginTransaction();
    foreach(['coins_per_ruble'=>$rate,'price_per_day'=>$price] as $key=>$value){
        $stmt=$db->prepare('INSERT INTO wallet_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([$key,(string)$value]);
    }
    $db->commit();
    admin_wallet_reply(200,['success'=>true]);
}
if($action==='review'){
    $id=filter_var($input['id']??null,FILTER_VALIDATE_INT);
    $decision=(string)($input['decision']??'');
    $note=trim((string)($input['note']??''));
    if(!is_int($id)||$id<1||!in_array($decision,['approve','reject'],true)) admin_wallet_reply(422,['success'=>false,'message'=>'Некорректные параметры заявки.']);
    try{
        $db->beginTransaction();
        $stmt=$db->prepare('SELECT * FROM topup_requests WHERE id = ?');
        $stmt->execute([$id]); $request=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$request||$request['status']!=='pending') throw new DomainException('Заявка уже обработана или не существует.');
        $newStatus=$decision==='approve'?'approved':'rejected';
        if($decision==='approve'){
            wallet_post($db,(string)$request['user_email'],(int)$request['requested_coins'],'topup','topup-request-'.$id,'Ручное пополнение заявки #'.$id,(string)($_SESSION['admin_user']??'admin'));
        }
        $upd=$db->prepare('UPDATE topup_requests SET status=?,admin_note=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=? AND status=\'pending\'');
        $upd->execute([$newStatus,mb_substr($note,0,1000),(string)($_SESSION['admin_user']??'admin'),$id]);
        if($upd->rowCount()!==1) throw new DomainException('Заявка уже обработана.');
        $db->commit();
        admin_wallet_reply(200,['success'=>true,'status'=>$newStatus]);
    }catch(DomainException $e){if($db->inTransaction())$db->rollBack();admin_wallet_reply(409,['success'=>false,'message'=>$e->getMessage()]);}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Topup review failed: '.get_class($e));admin_wallet_reply(500,['success'=>false,'message'=>'Не удалось обработать заявку.']);}
}
admin_wallet_reply(404,['success'=>false,'message'=>'Неизвестное действие.']);
