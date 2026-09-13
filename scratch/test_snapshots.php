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

echo "=== Testing market_snapshot for all 11 symbols ===\n";
foreach ($symbols as $sym) {
    try {
        $snapshot = market_snapshot($sym, 5, 72, true);
        $candleCount = count($snapshot['candles']);
        $price = $snapshot['asset']['price'];
        $decimals = $snapshot['asset']['decimals'];
        $firstCandle = $candleCount > 0 ? $snapshot['candles'][0] : null;
        $lastCandle = $candleCount > 0 ? $snapshot['candles'][$candleCount - 1] : null;
        echo "[OK] {$sym}: price={$price}, decimals={$decimals}, candles={$candleCount}, first={$firstCandle['close']}, last={$lastCandle['close']}\n";
    } catch (Throwable $e) {
        echo "[FAIL] {$sym}: " . $e->getMessage() . "\n";
    }
}
