<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

if (!is_post()) {
    json_response(['ok' => false, 'message' => 'POST is required.'], 405);
}

verify_csrf();
if (!session_rate_limit('trade:' . (int)$user['id'], 20, 60)) {
    json_response(['ok' => false, 'message' => 'Too many trade requests. Wait a moment and try again.'], 429);
}

$data = request_data();
try {
    $requestedAccount = strtolower(trim((string)($data['account'] ?? trade_account_type())));
    set_trade_account_type($requestedAccount, (int)$user['id']);
    $targetCloseTime = isset($data['targetCloseTime']) && is_numeric($data['targetCloseTime']) && (int)$data['targetCloseTime'] > 0
        ? (int)$data['targetCloseTime']
        : null;
    $clientEntryPrice = isset($data['entryPrice']) && is_numeric($data['entryPrice']) && (float)$data['entryPrice'] > 0
        ? (float)$data['entryPrice']
        : (isset($data['price']) && is_numeric($data['price']) && (float)$data['price'] > 0 ? (float)$data['price'] : null);
    $trade = place_trade(
        (int)$user['id'],
        (string)($data['symbol'] ?? ''),
        (string)($data['direction'] ?? ''),
        (float)($data['amount'] ?? 0),
        (int)($data['duration'] ?? 0),
        $requestedAccount,
        (string)($data['requestId'] ?? ''),
        $targetCloseTime,
        $clientEntryPrice
    );
    json_response(['ok' => true, 'message' => 'Trade opened.', 'data' => $trade], 201);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    if ($message === 'Insufficient demo balance.') {
        json_response([
            'ok' => false,
            'message' => 'Demo balance is too low. Ask an administrator to top up the Demo Account or switch to Live Account.',
            'code' => 'INSUFFICIENT_DEMO_BALANCE',
        ], 409);
    }
    if ($message === 'Insufficient wallet balance.') {
        json_response([
            'ok' => false,
            'message' => 'Insufficient balance. Please deposit funds to place a Real trade.',
            'code' => 'INSUFFICIENT_BALANCE',
            'redirect' => '/en/balance/?deposit=true&from=trade',
        ], 409);
    }
    json_response(['ok' => false, 'message' => $message], 409);
}
