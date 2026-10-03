<?php
require 'app/bootstrap.php';
$k = fetch_binance_klines('PAXGUSDT', 180, 50);
echo "Total klines returned from Binance: " . count($k) . PHP_EOL;
for ($i = 0; $i < count($k); $i++) {
    $t = (int)($k[$i]['openTime']/1000);
    $prevT = ($i > 0) ? (int)($k[$i-1]['openTime']/1000) : 0;
    $diffMin = ($prevT > 0) ? ($t - $prevT)/60 : 0;
    echo date('Y-m-d H:i:s', $t) . " | diff: {$diffMin} min | O:{$k[$i]['open']} C:{$k[$i]['close']}" . ($diffMin > 3 ? " [GAP OF {$diffMin} MIN!]" : "") . PHP_EOL;
}
