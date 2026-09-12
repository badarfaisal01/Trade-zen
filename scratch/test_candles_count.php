<?php
require __DIR__ . '/../app/bootstrap.php';

$intervals = [5, 60];
foreach ($intervals as $interval) {
    echo "=== Testing Interval {$interval}s ===\n";
    $snap = market_snapshot('EURUSD', $interval, 10000, true);
    $candles = $snap['candles'] ?? [];
    echo "Candle count returned: " . count($candles) . "\n";
    if (!empty($candles)) {
        echo "First candle: " . date('Y-m-d H:i:s', (int)($candles[0]['time'] / 1000)) . " (time: {$candles[0]['time']})\n";
        echo "Last candle:  " . date('Y-m-d H:i:s', (int)(end($candles)['time'] / 1000)) . " (time: " . end($candles)['time'] . ")\n";
        $totalTicks = 0;
        foreach ($candles as $c) {
            $totalTicks += count($c['ticks'] ?? []);
        }
        echo "Total ticks: {$totalTicks}\n";
    }
}
