<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

$requestId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT user_id,proof_filename FROM fund_requests WHERE id=? LIMIT 1');
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request || !$request['proof_filename'] || ((int)$request['user_id'] !== (int)$user['id'] && !is_admin($user))) {
    http_response_code(404);
    exit('Image not found.');
}
stream_protected_image('payment-proofs', (string)$request['proof_filename']);
