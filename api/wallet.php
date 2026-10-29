<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wallet_reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function wallet_csrf(): void {
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) wallet_reply(403, ['success'=>false,'message'=>'Проверка безопасности не пройдена. Обновите страницу.']);
}
if (empty($_SESSION['user'])) wallet_reply(401, ['success'=>false,'message'=>'Необходимо войти в аккаунт.']);
try { wallet_ensure_schema($db); } catch (Throwable $e) { error_log('Wallet schema failed: '.get_class($e)); wallet_reply(500,['success'=>false,'message'=>'Кошелёк временно недоступен.']); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$email = strtolower(trim((string) $_SESSION['user']));

if ($method === 'GET' && $action === 'summary') {
    wallet_reply(200, [
        'success'=>true,
        'coins'=>wallet_balance($db,$email),
        'coins_per_ruble'=>(int) wallet_setting($db,'coins_per_ruble','1'),
        'price_per_day'=>(int) wallet_setting($db,'price_per_day','10')
    ]);
}
if ($method === 'GET' && $action === 'transactions') {
    $stmt=$db->prepare('SELECT type, coins_delta, description, created_at FROM wallet_transactions WHERE user_email = ? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$email]);
    wallet_reply(200,['success'=>true,'transactions'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
if ($method !== 'POST') { header('Allow: GET, POST'); wallet_reply(405,['success'=>false,'message'=>'Метод не поддерживается.']); }
wallet_csrf();
$raw=file_get_contents('php://input');
$input=json_decode(is_string($raw)?$raw:'',true);
if (!is_array($input)) wallet_reply(400,['success'=>false,'message'=>'Некорректный JSON.']);

if ($action === 'request-topup') {
    $rub=filter_var($input['amount_rub']??null,FILTER_VALIDATE_INT);
    if (!is_int($rub)||$rub<1||$rub>100000) wallet_reply(422,['success'=>false,'message'=>'Сумма должна быть от 1 до 100000 рублей.']);
    $rate=(int)wallet_setting($db,'coins_per_ruble','1');
    if ($rate<1||$rate>100000) wallet_reply(500,['success'=>false,'message'=>'Не задан корректный курс монет.']);
    $coins=$rub*$rate;
    $stmt=$db->prepare("INSERT INTO topup_requests(user_email,amount_rub,requested_coins) VALUES(?,?,?)");
    $stmt->execute([$email,$rub,$coins]);
    wallet_reply(201,['success'=>true,'request_id'=>(int)$db->lastInsertId(),'message'=>'Заявка отправлена администратору. Баланс изменится после подтверждения.']);
}
wallet_reply(404,['success'=>false,'message'=>'Неизвестное действие.']);
