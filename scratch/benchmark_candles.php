<?php
require dirname(__DIR__) . '/app/bootstrap.php';

$asset = market_asset('EURUSD');
$now = (int)floor(microtime(true) * 1000);

$t0 = microtime(true);
$candles = market_candles($asset, 60, 5000, $now);
$t1 = microtime(true);

echo "Fetched " . count($candles) . " candles in " . round(($t1 - $t0) * 1000, 2) . " ms\n";
echo "Earliest candle: " . date('Y-m-d H:i:s', (int)($candles[0]['time'] / 1000)) . "\n";
echo "Latest candle:   " . date('Y-m-d H:i:s', (int)(end($candles)['time'] / 1000)) . "\n";
