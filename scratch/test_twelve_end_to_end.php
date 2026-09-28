<?php
require_once __DIR__ . '/../app/bootstrap.php';

$key = env('TWELVEDATA_API_KEY');
echo "TWELVEDATA_API_KEY: $key\n";

$symbols = ['EUR/USD', 'GBP/USD', 'USD/JPY'];

foreach ($symbols as $sym) {
    echo "\n--- Testing $sym ---\n";
    try {
        $price = fetch_twelvedata_price($sym, $key);
        echo "Price for $sym: $price\n";
    } catch (Throwable $e) {
        echo "Price error for $sym: " . $e->getMessage() . "\n";
    }

    try {
        $candles = fetch_twelvedata_candles($sym, 60, 5, null, $key);
        echo "Fetched " . count($candles) . " candles for $sym\n";
        if (!empty($candles)) {
            echo "Sample candle: " . json_encode($candles[0]) . "\n";
        }
    } catch (Throwable $e) {
        echo "Candles error for $sym: " . $e->getMessage() . "\n";
    }
}
