<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

if (!is_post()) {
    json_response(['ok' => false, 'message' => 'POST is required.'], 405);
}
verify_csrf();
$data = request_data();
try {
    $requested = strtolower(trim((string)($data['account'] ?? '')));
    if ($requested === 'demo' && !(bool)setting('demo_trading_enabled', true)) {
        throw new RuntimeException('Demo Account is currently disabled by the administrator.');
    }
    $type = set_trade_account_type($requested, (int)$user['id']);
    $state = user_trade_state((int)$user['id'], 25);
    json_response(['ok' => true, 'message' => $type === 'demo' ? 'Demo Account selected.' : 'Live Account selected.', 'data' => $state]);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 409);
}
