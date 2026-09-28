<?php
require __DIR__ . '/../app/bootstrap.php';

$asset = market_asset('EURUSD', false);
var_dump($asset);

$snap = market_snapshot('EURUSD', 60, 10, true);
echo "Asset Price: " . $snap['asset']['price'] . "\n";
echo "Candles count: " . count($snap['candles']) . "\n";
foreach (array_slice($snap['candles'], 0, 5) as $c) {
    echo "Time: " . date('Y-m-d H:i:s', (int)($c['time']/1000)) . " | Open: {$c['open']} | Close: {$c['close']}\n";
}
$last = end($snap['candles']);
echo "LAST Time: " . date('Y-m-d H:i:s', (int)($last['time']/1000)) . " | Open: {$last['open']} | Close: {$last['close']}\n";
