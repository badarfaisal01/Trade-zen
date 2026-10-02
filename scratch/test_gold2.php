<?php
require 'app/bootstrap.php';
$klines = fetch_binance_klines('PAXGUSDT', 60, 20);
echo "Fetched PAXGUSDT klines count: " . count($klines) . PHP_EOL;
foreach ($klines as $k) {
    echo date('Y-m-d H:i:s', (int)($k['openTime']/1000)) . " O:" . $k['open'] . " H:" . $k['high'] . " L:" . $k['low'] . " C:" . $k['close'] . PHP_EOL;
}
