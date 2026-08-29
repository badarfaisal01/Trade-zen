<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

if (!is_post()) {
    json_response(['ok' => false, 'message' => 'POST is required.'], 405);
}

verify_csrf();
if (!session_rate_limit('fund-request:' . (int)$user['id'], 8, 3600)) {
    json_response(['ok' => false, 'message' => 'Too many requests. Try again later.'], 429);
}

$data = request_data();
try {
    $requestType = strtolower(trim((string)($data['type'] ?? '')));
    if ($requestType === 'deposit') {
        $request = create_deposit_order(
            (int)$user['id'],
            (float)($data['amount'] ?? 0),
            (int)($data['payment_method_id'] ?? 0),
            (string)($data['note'] ?? ''),
            (string)($data['promo_code'] ?? '')
        );
        json_response(['ok' => true, 'message' => 'Deposit order created. Continue to payment.', 'data' => $request], 201);
    }
    $request = create_fund_request(
        (int)$user['id'],
        $requestType,
        (float)($data['amount'] ?? 0),
        (string)($data['note'] ?? ''),
        (int)($data['payment_method_id'] ?? 0),
        '',
        null,
        is_array($data['destination'] ?? null) ? $data['destination'] : []
    );
    json_response(['ok' => true, 'message' => 'Withdrawal request submitted.', 'data' => $request], 201);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 409);
}
