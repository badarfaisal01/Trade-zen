<?php
require 'app/bootstrap.php';
$asset = market_asset('XAUUSD');
var_dump($asset);
$snap = market_snapshot('XAUUSD', 60, 50);
echo "Candles count: " . count($snap['candles']) . PHP_EOL;
foreach (array_slice($snap['candles'], -15) as $c) {
    echo date('Y-m-d H:i:s', (int)($c['time']/1000)) . " O:" . $c['open'] . " H:" . $c['high'] . " L:" . $c['low'] . " C:" . $c['close'] . " Ticks:" . count($c['ticks']) . PHP_EOL;
}
