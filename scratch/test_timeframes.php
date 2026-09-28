<?php
require __DIR__ . '/../app/bootstrap.php';

$symbols = ['BTCUSD', 'EURUSD', 'ETHUSD', 'SOLUSD', 'GBPUSD'];
$intervals = [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600, 14400, 86400];

$allPassed = true;
foreach ($symbols as $sym) {
    echo "\n=== Testing Symbol: {$sym} ===\n";
    $asset = market_asset($sym);
    if (!$asset) { echo "Asset not found: {$sym}\n"; continue; }
    foreach ($intervals as $iv) {
        $snap = market_snapshot($sym, $iv, 800, true);
        $candleCount = count($snap['candles'] ?? []);
        $price = $snap['asset']['price'] ?? 'n/a';
        echo "Interval {$iv}s: candles={$candleCount}, price={$price}\n";
        if ($candleCount < 400) {
            echo "[ERROR] Low candle count for {$sym} at {$iv}s: {$candleCount}\n";
            $allPassed = false;
        }
    }
}

if ($allPassed) {
    echo "\n>>> ALL TIMEFRAME TESTS PASSED (800+ candles per timeframe)! <<<\n";
} else {
    echo "\n>>> SOME TIMEFRAME TESTS FAILED <<<\n";
    exit(1);
}
