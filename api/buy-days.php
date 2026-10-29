<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/xui-api.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function buy_days_reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    buy_days_reply(405, ['success'=>false, 'message'=>'Метод не поддерживается.']);
}
if (empty($_SESSION['user'])) buy_days_reply(401, ['success'=>false, 'message'=>'Необходимо войти в аккаунт.']);
$csrf=(string)($_SESSION['csrf_token']??'');
$provided=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
if ($csrf==='' || $provided==='' || !hash_equals($csrf,$provided)) buy_days_reply(403,['success'=>false,'message'=>'Проверка безопасности не пройдена. Обновите страницу.']);

try {
    wallet_ensure_schema($db);
    $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver==='sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_purchases (
          id INTEGER PRIMARY KEY AUTOINCREMENT, user_email TEXT NOT NULL, days INTEGER NOT NULL CHECK(days > 0),
          coins_charged INTEGER NOT NULL CHECK(coins_charged > 0),
          status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','succeeded','failed','needs_review')),
          error_code TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    } elseif ($driver==='pgsql') {
        $db->exec("CREATE TABLE IF NOT EXISTS wallet_purchases (
          id BIGSERIAL PRIMARY KEY, user_email VARCHAR(320) NOT NULL, days INTEGER NOT NULL CHECK(days > 0),
          coins_charged BIGINT NOT NULL CHECK(coins_charged > 0),
          status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','succeeded','failed','needs_review')),
          error_code TEXT NOT NULL DEFAULT '', created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }
} catch (Throwable $e) {
    error_log('Buy-days setup failed: '.get_class($e));
    buy_days_reply(500,['success'=>false,'message'=>'Покупка подписки временно недоступна.']);
}

$input=json_decode((string)file_get_contents('php://input'),true);
$days=filter_var(is_array($input)?($input['days']??null):null,FILTER_VALIDATE_INT);
if (!is_int($days)||$days<1||$days>3650) buy_days_reply(422,['success'=>false,'message'=>'Можно купить от 1 до 3650 дней.']);
$email=strtolower(trim((string)$_SESSION['user']));
$price=(int)wallet_setting($db,'price_per_day','10');
if ($price<1||$price>1000000) buy_days_reply(500,['success'=>false,'message'=>'Некорректная цена дня подписки.']);
if ($days > intdiv(PHP_INT_MAX,$price)) buy_days_reply(422,['success'=>false,'message'=>'Слишком большое количество дней.']);
$cost=$days*$price;

try {
    // Validate the client before charging. Unlimited-expiry clients are not eligible for this purchase.
    $client=xui_get_client($email);
    if ((int)($client['expiryTime']??0)===0) {
        buy_days_reply(409,['success'=>false,'message'=>'У этой подписки в 3X-UI безлимитный срок, продление не требуется.']);
    }
} catch (DomainException $e) {
    buy_days_reply(404,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('3X-UI preflight failed: '.get_class($e));
    buy_days_reply(503,['success'=>false,'message'=>'Не удалось проверить подписку в 3X-UI. Монеты не списаны.']);
}

$purchaseId=0;
try {
    $db->beginTransaction();
    $purchase=$db->prepare("INSERT INTO wallet_purchases(user_email,days,coins_charged,status) VALUES(?,?,?,'pending')");
    $purchase->execute([$email,$days,$cost]);
    $purchaseId=(int)$db->lastInsertId();
    wallet_post($db,$email,-$cost,'purchase','subscription-purchase-'.$purchaseId,'Покупка продления на '.$days.' дн.');
    $db->commit();
} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    buy_days_reply(409,['success'=>false,'message'=>'Недостаточно монет для покупки.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Purchase reservation failed: '.get_class($e));
    buy_days_reply(500,['success'=>false,'message'=>'Не удалось оформить покупку. Монеты не списаны.']);
}

try {
    $result=xui_extend_client($email,$days);
    $obj=$result['obj']??null;
    // The current documented API reports per-email skips; do not treat a skipped update as success.
    if (is_array($obj) && isset($obj['skipped']) && is_array($obj['skipped']) && count($obj['skipped'])>0) {
        $db->prepare("UPDATE wallet_purchases SET status='needs_review',error_code='3xui_skipped',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$purchaseId]);
        buy_days_reply(202,['success'=>false,'pending_review'=>true,'message'=>'3X-UI не подтвердил продление. Покупка отправлена на ручную проверку; не повторяйте запрос. Номер операции: '.$purchaseId.'.']);
    }
    $db->prepare("UPDATE wallet_purchases SET status='succeeded',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")->execute([$purchaseId]);
    buy_days_reply(200,['success'=>true,'purchase_id'=>$purchaseId,'days'=>$days,'coins_charged'=>$cost,'message'=>'Подписка продлена.']);
} catch (DomainException $e) {
    // Only a clear precondition rejection is safe to refund automatically.
    try {
        $db->beginTransaction();
        wallet_post($db,$email,$cost,'refund','subscription-purchase-refund-'.$purchaseId,'Возврат за отклонённую покупку #'.$purchaseId);
        $db->prepare("UPDATE wallet_purchases SET status='failed',error_code='precondition',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")->execute([$purchaseId]);
        $db->commit();
    } catch (Throwable $refundError) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Purchase refund failed: '.get_class($refundError));
    }
    buy_days_reply(409,['success'=>false,'message'=>$e->getMessage().' Списание отменено, если возврат прошёл успешно.']);
} catch (Throwable $e) {
    // Network/HTTP errors can be ambiguous: 3X-UI may have applied the change before the connection failed.
    // Keep the reserved balance and flag for manual reconciliation instead of risking a free extension.
    try {
        $db->prepare("UPDATE wallet_purchases SET status='needs_review',error_code='xui_result_uncertain',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")->execute([$purchaseId]);
    } catch (Throwable $dbError) { error_log('Purchase status update failed: '.get_class($dbError)); }
    error_log('Purchase requires reconciliation: '.get_class($e));
    buy_days_reply(202,['success'=>false,'pending_review'=>true,'message'=>'Ответ 3X-UI не удалось однозначно подтвердить. Монеты временно зарезервированы для проверки; не повторяйте запрос. Номер операции: '.$purchaseId.'.']);
}
