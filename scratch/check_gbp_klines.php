<?php
require __DIR__ . '/../app/bootstrap.php';
$k = fetch_binance_klines('GBPUSD', 5, 10);
echo "GBPUSD klines count: " . count($k) . "\n";
if (!empty($k)) {
    echo "First openTime: " . $k[0]['openTime'] . " (" . date('Y-m-d H:i:s', (int)($k[0]['openTime']/1000)) . ")\n";
    echo "Last openTime: " . $k[count($k)-1]['openTime'] . " (" . date('Y-m-d H:i:s', (int)($k[count($k)-1]['openTime']/1000)) . ")\n";
    echo "Now ms: " . floor(microtime(true)*1000) . " (" . date('Y-m-d H:i:s') . ")\n";
}
