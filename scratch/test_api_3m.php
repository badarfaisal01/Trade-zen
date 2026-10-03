<?php
require 'app/bootstrap.php';
$_GET['symbol'] = 'XAUUSD';
$_GET['interval'] = '180';
$_GET['count'] = '400';
$_GET['history'] = '1';
ob_start();
require 'en/api/market.php';
$out = ob_get_clean();
$json = json_decode($out, true);
$data = $json['data'] ?? [];
echo "Total candles returned: " . count($data['candles'] ?? []) . PHP_EOL;
$candles = $data['candles'] ?? [];
for ($i = count($candles) - 10; $i < count($candles); $i++) {
    if ($i < 0) continue;
    $c = $candles[$i];
    $t = date('Y-m-d H:i:s', (int)($c['time']/1000));
    $ticksCount = count($c['ticks'] ?? []);
    echo "$t | O:{$c['open']} H:{$c['high']} L:{$c['low']} C:{$c['close']} | ticks: $ticksCount" . PHP_EOL;
}
