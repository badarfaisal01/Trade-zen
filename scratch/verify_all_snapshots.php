<?php
require __DIR__ . '/../app/bootstrap.php';

$pairs = ['USDCAD', 'EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD'];

foreach ($pairs as $s) {
    $snap = market_snapshot($s, 5, 20, true);
    $lastC = end($snap['candles']);
    $firstC = reset($snap['candles']);
    $assetPrice = (float)$snap['asset']['price'];
    $lastClose = (float)$lastC['close'];
    $diff = abs($assetPrice - $lastClose);
    
    echo sprintf(
        "[%-6s] Asset Price: %9.5f | Last Candle Close: %9.5f | Delta: %.6f | 1st Candle: %9.5f\n",
        $s,
        $assetPrice,
        $lastClose,
        $diff,
        (float)$firstC['open']
    );
    
    // Check for any sudden intra-candle jump > 0.003
    $prev = null;
    $maxStep = 0;
    foreach ($snap['candles'] as $c) {
        if ($prev !== null) {
            $step = abs($c['open'] - $prev['close']);
            $maxStep = max($maxStep, $step);
            $intraStep = abs($c['close'] - $c['open']);
            $maxStep = max($maxStep, $intraStep);
        }
        $prev = $c;
    }
    echo sprintf("       Max single step/jump in series: %.6f\n", $maxStep);
}
