<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once BASE_PATH . '/app/market_api.php';

$key = env('TWELVEDATA_API_KEY');
echo "API Key: $key\n";

foreach (['AUD/USD', 'USD/CAD'] as $symbol) {
    echo "\n--- Testing $symbol ---\n";
    try {
        $price = fetch_twelvedata_price($symbol, $key);
        echo "Price: $price\n";
    } catch (Throwable $e) {
        echo "Price Error: " . $e->getMessage() . "\n";
    }

    try {
        $candles = fetch_twelvedata_candles($symbol, 60, 5, null, $key);
        echo "Candle count: " . count($candles) . "\n";
    } catch (Throwable $e) {
        echo "Candles Error: " . $e->getMessage() . "\n";
    }
}
