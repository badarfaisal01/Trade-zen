<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/market.php';

$testSymbols = ['EURUSD', 'BTCUSD', 'ETHUSD', 'XAUUSD', 'US30', 'AAPL', 'EURUSD_otc'];
$testIntervals = [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600];

$db = db();
foreach ($testSymbols as $sym) {
    $plain = str_replace('_otc', '', $sym);
    $stmt = $db->prepare("SELECT * FROM market_assets WHERE symbol = ? OR symbol = ? LIMIT 1");
    $stmt->execute([$sym, $plain]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        $asset = ['symbol' => $sym, 'name' => $sym, 'category' => 'Currencies', 'decimal_places' => 5, 'volatility' => 0.002];
    }
    
    echo "\n=== Testing Symbol: {$sym} (Category: " . ($asset['category'] ?? 'N/A') . ", Price Source: " . ($asset['price_source'] ?? 'N/A') . ") ===\n";
    foreach ($testIntervals as $interval) {
        $start = microtime(true);
        $candles = market_candles($asset, $interval, 50);
        $elapsed = round((microtime(true) - $start) * 1000, 1);
        $count = count($candles);
        $firstTime = $count > 0 ? $candles[0]['time'] : 0;
        $lastTime = $count > 0 ? $candles[$count - 1]['time'] : 0;
        $lastClose = $count > 0 ? $candles[$count - 1]['close'] : 0;
        
        // Check for gaps or timestamp ordering
        $hasGaps = false;
        $stepMs = $interval * 1000;
        for ($i = 1; $i < $count; $i++) {
            $diff = $candles[$i]['time'] - $candles[$i-1]['time'];
            if ($diff !== $stepMs) {
                $hasGaps = true;
                break;
            }
        }
        
        echo "Interval {$interval}s: {$count} candles in {$elapsed}ms | First: {$firstTime}, Last: {$lastTime}, LastClose: {$lastClose}" . ($hasGaps ? " [GAP DETECTED!]" : " [OK]") . "\n";
    }
}
