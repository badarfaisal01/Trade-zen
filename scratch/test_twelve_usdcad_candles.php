<?php
require 'app/bootstrap.php';
$key = (string)env('TWELVEDATA_API_KEY', '');
$res = fetch_twelvedata_candles('USD/CAD', 60, 50, time() * 1000, $key);
echo "Twelve Data 1m candles count: " . count($res) . "\n";
if (!empty($res)) {
    echo "First candle: " . json_encode($res[0]) . "\n";
    echo "Last candle: " . json_encode(end($res)) . "\n";
}
