<?php
require __DIR__ . '/../app/bootstrap.php';

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

echo "Testing 11 symbols:\n";
foreach ($symbols as $sym) {
    try {
        $price = fetch_market_price($sym);
        echo "[OK] Price for $sym = $price\n";
    } catch (Throwable $e) {
        echo "[FAIL] Price for $sym: " . $e->getMessage() . "\n";
    }
}
