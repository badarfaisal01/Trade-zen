<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$user = current_user(true);
if (!$user) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Auto-settle any expired trades for the current user
try {
    settle_due_trades((int)$user['id']);
} catch (Throwable $e) {
    // Settle gracefully
}

// Re-fetch fresh user balance
$user = current_user(true) ?: $user;
$accountType = trade_account_type();
$balance = trade_balance_from_user($user, $accountType);

$now = time();
$stmt = db()->prepare(
    "SELECT t.*, a.display_name, a.symbol as asset_symbol, a.payout_percent 
     FROM trades t 
     LEFT JOIN market_assets a ON t.asset_id = a.id 
     WHERE t.user_id = ? 
     ORDER BY t.id DESC 
     LIMIT 20"
);
$stmt->execute([(int)$user['id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$trades = [];
foreach ($rows as $r) {
    $createdAtTs = strtotime((string)$r['created_at']);
    $duration = (int)($r['duration_seconds'] ?? ((float)$r['duration_minutes'] * 60));
    $closeTime = $createdAtTs + $duration;
    $leftSeconds = max(0, $closeTime - $now);

    $trades[] = [
        'id'               => (int)$r['id'],
        'symbol'           => $r['symbol'] ?? $r['asset_symbol'] ?? 'BTCUSD',
        'display_name'     => $r['display_name'] ?? $r['symbol'] ?? 'BTC/USD',
        'trade_type'       => $r['direction'] === 'up' ? 'call' : 'put',
        'direction'        => $r['direction'] ?? 'up',
        'amount'           => (float)$r['amount'],
        'entry_price'      => (float)$r['entry_price'],
        'close_price'      => $r['close_price'] !== null ? (float)$r['close_price'] : null,
        'status'           => $r['status'] ?? 'pending',
        'duration_seconds' => $duration,
        'duration_minutes' => round($duration / 60, 2),
        'left_seconds'     => $leftSeconds,
        'created_at'       => $r['created_at'],
        'account_type'     => $r['account_type'] ?? 'live',
    ];
}

json_response([
    'success'      => true,
    'balance'      => $balance,
    'account_type' => $accountType,
    'trades'       => $trades,
]);
