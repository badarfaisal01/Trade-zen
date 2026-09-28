<?php
require __DIR__ . '/../app/bootstrap.php';

$symbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD'];
$intervals = [5, 60];

foreach ($symbols as $sym) {
    echo "=================== $sym ===================\n";
    foreach ($intervals as $interval) {
        $snap = market_snapshot($sym, $interval, 20, true);
        $cCount = count($snap['candles']);
        $lastC = end($snap['candles']);
        $firstC = reset($snap['candles']);
        $gap = abs($snap['asset']['price'] - $lastC['close']);
        $gapPct = ($gap / max($lastC['close'], 0.0001)) * 100;
        
        echo sprintf(
            "Interval: %3ds | Candles: %2d | First: %.5f | LastClose: %.5f | AssetPrice: %.5f | Gap: %.4f%%\n",
            $interval,
            $cCount,
            $firstC['open'] ?? 0,
            $lastC['close'] ?? 0,
            $snap['asset']['price'] ?? 0,
            $gapPct
        );
        
        // Check for flat / straight lines (consecutive identical candles)
        $flatStreak = 0;
        $maxFlatStreak = 0;
        $prevC = null;
        foreach ($snap['candles'] as $c) {
            if ($prevC !== null && $c['open'] == $c['close'] && $c['high'] == $c['low'] && $c['close'] == $prevC['close']) {
                $flatStreak++;
                $maxFlatStreak = max($maxFlatStreak, $flatStreak);
            } else {
                $flatStreak = 0;
            }
            $prevC = $c;
        }
        if ($maxFlatStreak > 0) {
            echo "  ⚠️ Warning: Max flat streak of identical candles: $maxFlatStreak\n";
        }
    }
}
