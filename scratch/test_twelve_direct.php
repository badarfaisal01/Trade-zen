<?php
require 'app/bootstrap.php';
$apiKey = (string)env('TWELVEDATA_API_KEY', '');
echo "API Key: " . substr($apiKey, 0, 6) . "...\n";

try {
    $price = fetch_twelvedata_price('USD/CAD', $apiKey);
    echo "fetch_twelvedata_price: " . $price . "\n";
} catch (Throwable $e) {
    echo "fetch_twelvedata_price failed: " . $e->getMessage() . "\n";
}

try {
    $candles = fetch_twelvedata_candles('USD/CAD', 60, 100, time() * 1000, $apiKey);
    echo "Candles count: " . count($candles) . "\n";
    if (!empty($candles)) {
        echo "Last candle: " . json_encode(end($candles)) . "\n";
    }
} catch (Throwable $e) {
    echo "fetch_twelvedata_candles failed: " . $e->getMessage() . "\n";
}
