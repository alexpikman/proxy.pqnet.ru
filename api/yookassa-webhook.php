<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/yookassa.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }

try {
    yookassa_ensure_schema($db);
    $raw = file_get_contents('php://input');
    $notification = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($notification) || ($notification['type'] ?? '') !== 'notification' || !is_array($notification['object'] ?? null)) {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }
    $event = (string) ($notification['event'] ?? '');
    $paymentId = (string) ($notification['object']['id'] ?? '');
    if (!in_array($event, ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture'], true) || $paymentId === '') {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }

    // Notification payload is only a hint: verify the current payment with YooKassa API.
    $payment = yookassa_request('GET', 'payments/' . rawurlencode($paymentId));
    if ((string) ($payment['id'] ?? '') !== $paymentId) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
    $orderId = (string) ($payment['metadata']['order_id'] ?? '');
    if ($orderId === '') { echo json_encode(['ok' => true]); exit; }

    $db->beginTransaction();
    $find = $db->prepare('SELECT * FROM payments WHERE order_id = ?');
    $find->execute([$orderId]);
    $order = $find->fetch(PDO::FETCH_ASSOC);
    if (!$order || (string) $order['provider_payment_id'] !== $paymentId) {
        $db->rollBack(); echo json_encode(['ok' => true]); exit;
    }

    $eventId = $event . ':' . $paymentId . ':' . (string) ($payment['status'] ?? '');
    $eventInsert = $db->prepare('INSERT INTO payment_events (provider_event_id, provider_payment_id, event_type) VALUES (?, ?, ?) ON CONFLICT(provider_event_id) DO NOTHING');
    $eventInsert->execute([$eventId, $paymentId, $event]);
    $actualKopecks = (int) round(((float) ($payment['amount']['value'] ?? 0)) * 100);
    $expectedKopecks = (int) $order['amount_kopecks'];

    if (($payment['status'] ?? '') === 'succeeded' && ($payment['paid'] ?? false) === true) {
        if ($actualKopecks !== $expectedKopecks || (string) ($payment['amount']['currency'] ?? '') !== 'RUB' ||
            (string) ($payment['metadata']['user_email'] ?? '') !== (string) $order['user_email']) {
            $db->prepare("UPDATE payments SET status = 'review', updated_at = CURRENT_TIMESTAMP WHERE order_id = ?")->execute([$orderId]);
        } else {
            $mark = $db->prepare("UPDATE payments SET status = 'succeeded', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status IN ('pending', 'creating')");
            $mark->execute([$orderId]);
            if ($mark->rowCount() === 1) {
                $ledger = $db->prepare('INSERT INTO wallet_ledger (user_email, payment_order_id, amount_kopecks) VALUES (?, ?, ?) ON CONFLICT(payment_order_id) DO NOTHING');
                $ledger->execute([$order['user_email'], $orderId, $expectedKopecks]);
                $db->prepare('UPDATE payments SET credited_at = CURRENT_TIMESTAMP WHERE order_id = ?')->execute([$orderId]);
            }
        }
    } elseif (($payment['status'] ?? '') === 'canceled') {
        $db->prepare("UPDATE payments SET status = 'canceled', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status IN ('pending', 'creating')")->execute([$orderId]);
    }
    $db->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('YooKassa webhook processing failed: ' . get_class($e));
    http_response_code(500); echo json_encode(['ok' => false]);
}
