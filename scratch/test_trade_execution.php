<?php
require __DIR__ . '/../app/bootstrap.php';

$user = db()->query("SELECT * FROM users WHERE role='user' ORDER BY id ASC LIMIT 1")->fetch();
if (!$user) {
    $user = db()->query("SELECT * FROM users ORDER BY id ASC LIMIT 1")->fetch();
}

$symbols = [
    'XAUUSD',
    'EURUSD',
    'GBPUSD',
    'XAGUSD',
    'JPYUSD',
    'USDJPY',
    'US30',
    'US500',
    'BTCUSD',
    'ETHUSD',
    'BNBUSD'
];

echo "Testing place_trade for user #{$user['id']} across all 11 symbols in demo mode:\n";
foreach ($symbols as $sym) {
    try {
        $trade = place_trade(
            (int)$user['id'],
            $sym,
            'up',
            10.0,
            5,
            'demo',
            'req_' . bin2hex(random_bytes(4))
        );
        echo "[OK] Placed trade for $sym: ID #{$trade['id']}, Entry={$trade['entry_price']}, Payout={$trade['payout_amount']}\n";
    } catch (Throwable $e) {
        echo "[FAIL] $sym: " . $e->getMessage() . "\n";
    }
}
