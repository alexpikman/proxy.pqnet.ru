<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/notifications.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function notification_api_reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (empty($_SESSION['user'])) notification_api_reply(401, ['success'=>false,'message'=>'Необходимо войти в аккаунт.']);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
try { notifications_ensure_schema($db); } catch (Throwable $e) {
    error_log('Notification schema failed: '.get_class($e));
    notification_api_reply(500, ['success'=>false,'message'=>'Центр уведомлений временно недоступен.']);
}
$email = strtolower(trim((string) $_SESSION['user']));
$action = (string) ($_GET['action'] ?? 'list');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $action === 'list') {
    $stmt = $db->prepare('SELECT id,type,title,body,url,created_at,read_at FROM user_notifications WHERE user_email=? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$email]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($items as &$item) { if ($item['read_at'] === null) $count++; }
    unset($item);
    notification_api_reply(200, ['success'=>true,'notifications'=>$items,'unread_count'=>$count,'csrf_token'=>$_SESSION['csrf_token']]);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: GET, POST'); notification_api_reply(405,['success'=>false,'message'=>'Метод не поддерживается.']); }
$expected = (string) ($_SESSION['csrf_token'] ?? '');
$provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) notification_api_reply(403,['success'=>false,'message'=>'Проверка безопасности не пройдена.']);
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) notification_api_reply(400,['success'=>false,'message'=>'Некорректный JSON.']);
if ($action === 'read') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) notification_api_reply(422,['success'=>false,'message'=>'Некорректный идентификатор.']);
    $stmt = $db->prepare('UPDATE user_notifications SET read_at=CURRENT_TIMESTAMP WHERE id=? AND user_email=? AND read_at IS NULL');
    $stmt->execute([$id,$email]);
    notification_api_reply(200,['success'=>true]);
}
if ($action === 'read-all') {
    $stmt = $db->prepare('UPDATE user_notifications SET read_at=CURRENT_TIMESTAMP WHERE user_email=? AND read_at IS NULL');
    $stmt->execute([$email]);
    notification_api_reply(200,['success'=>true]);
}
notification_api_reply(404,['success'=>false,'message'=>'Неизвестное действие.']);
