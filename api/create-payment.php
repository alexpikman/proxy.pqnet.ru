<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/yookassa.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function payment_json_error(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); payment_json_error(405, 'Метод не поддерживается.'); }
if (empty($_SESSION['user'])) payment_json_error(401, 'Необходимо войти в аккаунт.');

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    payment_json_error(403, 'Проверка безопасности не пройдена. Обновите страницу.');
}
$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
$amountRubles = filter_var(is_array($input) ? ($input['amount_rub'] ?? null) : null, FILTER_VALIDATE_INT);
if (!is_int($amountRubles) || $amountRubles < 1 || $amountRubles > 100000) {
    payment_json_error(422, 'Сумма должна быть целым числом от 1 до 100000 рублей.');
}

try {
    yookassa_ensure_schema($db);
    $email = strtolower(trim((string) $_SESSION['user']));
    $userStmt = $db->prepare('SELECT email FROM users WHERE LOWER(email) = ?');
    $userStmt->execute([$email]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) payment_json_error(403, 'Пользователь не найден.');

    $orderId = bin2hex(random_bytes(16));
    $amountKopecks = $amountRubles * 100;
    $db->beginTransaction();
    $insert = $db->prepare("INSERT INTO payments (order_id, user_email, amount_kopecks, status) VALUES (?, ?, ?, 'creating')");
    $insert->execute([$orderId, $email, $amountKopecks]);
    $db->commit();

    $config = yookassa_config();
    try {
        $payment = yookassa_request('POST', 'payments', [
            'amount' => ['value' => number_format($amountRubles, 2, '.', ''), 'currency' => 'RUB'],
            'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $config['return_url']],
            'description' => 'Пополнение баланса Proxy PQNET',
            'metadata' => ['order_id' => $orderId, 'user_email' => $email],
        ], $orderId);
    } catch (Throwable $e) {
        $db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status = 'creating'")->execute([$orderId]);
        throw $e;
    }

    $paymentId = (string) ($payment['id'] ?? '');
    $confirmationUrl = (string) ($payment['confirmation']['confirmation_url'] ?? '');
    if ($paymentId === '' || $confirmationUrl === '' || ($payment['status'] ?? '') !== 'pending') {
        $db->prepare("UPDATE payments SET status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE order_id = ?")->execute([$orderId]);
        throw new RuntimeException('Платёжный сервис не создал ожидаемый платёж.');
    }
    $db->prepare("UPDATE payments SET provider_payment_id = ?, confirmation_url = ?, status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE order_id = ?")->execute([$paymentId, $confirmationUrl, $orderId]);
    echo json_encode(['ok' => true, 'order_id' => $orderId, 'confirmation_url' => $confirmationUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('Payment creation failed: ' . get_class($e));
    payment_json_error(500, 'Не удалось создать платёж. Попробуйте позже.');
}
