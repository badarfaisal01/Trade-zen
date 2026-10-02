<?php
require 'app/bootstrap.php';

$intervals = [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600];
$symbols = ['XAUUSD', 'EURUSD', 'BTCUSD'];

foreach ($symbols as $sym) {
    echo "=== SYMBOL: $sym ===" . PHP_EOL;
    foreach ($intervals as $inv) {
        $start = microtime(true);
        $snap = market_snapshot($sym, $inv, 400);
        $elapsed = round((microtime(true) - $start) * 1000, 1);
        $candles = $snap['candles'] ?? [];
        $cCount = count($candles);
        
        // Check for gaps in timestamps
        $hasGap = false;
        $gapDetails = '';
        for ($i = 1; $i < $cCount; $i++) {
            $expectedTime = $candles[$i-1]['time'] + ($inv * 1000);
            $actualTime = $candles[$i]['time'];
            if ($actualTime !== $expectedTime) {
                $hasGap = true;
                $gapDetails = "Gap at index $i: expected " . date('H:i:s', (int)($expectedTime/1000)) . " but got " . date('H:i:s', (int)($actualTime/1000));
                break;
            }
        }
        
        $firstTime = !empty($candles) ? date('Y-m-d H:i:s', (int)($candles[0]['time']/1000)) : 'none';
        $lastTime = !empty($candles) ? date('Y-m-d H:i:s', (int)($candles[$cCount-1]['time']/1000)) : 'none';
        
        echo "Interval {$inv}s ({$cCount} candles, {$elapsed}ms): First=$firstTime, Last=$lastTime" . ($hasGap ? " [ERROR: $gapDetails]" : " [PERFECT - 0 GAPS]") . PHP_EOL;
    }
}
