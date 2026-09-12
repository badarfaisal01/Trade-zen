<?php
require dirname(__DIR__) . '/app/bootstrap.php';

$asset = market_asset('EURUSD');
$now = (int)floor(microtime(true) * 1000);
$initialCandles = market_candles($asset, 5, 50, $now);

echo "Initial count: " . count($initialCandles) . "\n";
echo "Initial first candle time: " . $initialCandles[0]['time'] . " (" . date('Y-m-d H:i:s', (int)($initialCandles[0]['time']/1000)) . ")\n";
echo "Initial last candle time: " . end($initialCandles)['time'] . " (" . date('Y-m-d H:i:s', (int)(end($initialCandles)['time']/1000)) . ")\n";

$oldestTime = (int)$initialCandles[0]['time'];
$olderCandles = market_candles($asset, 5, 50, $oldestTime - 1);

echo "Older count: " . count($olderCandles) . "\n";
echo "Older first candle time: " . $olderCandles[0]['time'] . " (" . date('Y-m-d H:i:s', (int)($olderCandles[0]['time']/1000)) . ")\n";
echo "Older last candle time: " . end($olderCandles)['time'] . " (" . date('Y-m-d H:i:s', (int)(end($olderCandles)['time']/1000)) . ")\n";
