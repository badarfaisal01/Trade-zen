<?php
$url = 'http://localhost/en/api/market.php?symbol=XAUUSD&interval=180&count=800&history=1';
$res = file_get_contents($url);
$json = json_decode($res, true);
echo "JSON keys: " . implode(', ', array_keys($json)) . "\n";
if (isset($json['data'])) {
    echo "data keys: " . implode(', ', array_keys($json['data'])) . "\n";
}
if (isset($json['asset'])) {
    echo "asset: " . print_r($json['asset'], true) . "\n";
}
if (isset($json['candles'])) {
    echo "candles count: " . count($json['candles']) . "\n";
}
