<?php
require __DIR__ . '/../app/bootstrap.php';

$intervals = [5, 15, 60, 300];

foreach ($intervals as $iv) {
    echo "\n==================== EUR/USD (Interval: {$iv}s) ====================\n";
    $snap = market_snapshot('EURUSD', $iv, 10, true);
    echo "Asset Symbol: " . $snap['asset']['symbol'] . " (" . $snap['asset']['display_name'] . ")\n";
    echo "Current Asset Price: " . $snap['asset']['price'] . " | Decimals: " . $snap['asset']['decimals'] . "\n";
    echo "Total Candles: " . count($snap['candles']) . "\n";
    
    $last10 = array_slice($snap['candles'], -10);
    echo sprintf("%-10s | %-9s | %-9s | %-9s | %-9s | %-10s | %-10s\n", "Time", "Open", "High", "Low", "Close", "Body (pips)", "Range (pips)");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($last10 as $c) {
        $body = ($c['close'] - $c['open']) * 10000;
        $range = ($c['high'] - $c['low']) * 10000;
        echo sprintf(
            "%-10s | %9.5f | %9.5f | %9.5f | %9.5f | %+9.2f | %10.2f\n",
            date('H:i:s', (int)($c['time']/1000)),
            $c['open'],
            $c['high'],
            $c['low'],
            $c['close'],
            $body,
            $range
        );
    }
}
