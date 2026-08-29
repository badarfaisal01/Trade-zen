<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

$methodId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT qr_filename, is_active FROM payment_methods WHERE id=? LIMIT 1');
$stmt->execute([$methodId]);
$method = $stmt->fetch();
$canReadInactive = is_admin($user);
if ($method && !$canReadInactive && !(bool)$method['is_active']) {
    $orderId = strtoupper(trim((string)($_GET['order'] ?? '')));
    if (preg_match('/^[A-Z0-9]{8,32}$/', $orderId)) {
        $order = db()->prepare("SELECT COUNT(*) FROM fund_requests WHERE public_id=? AND user_id=? AND payment_method_id=? AND request_type='deposit' AND status IN ('awaiting_payment','pending')");
        $order->execute([$orderId, (int)$user['id'], $methodId]);
        $canReadInactive = (int)$order->fetchColumn() === 1;
    }
}
if (!$method || !$method['qr_filename'] || (!(bool)$method['is_active'] && !$canReadInactive)) {
    http_response_code(404);
    exit('Image not found.');
}
stream_protected_image('payment-methods', (string)$method['qr_filename']);
