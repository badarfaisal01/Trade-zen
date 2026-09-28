<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once BASE_PATH . '/app/market.php';
require_once BASE_PATH . '/app/market_api.php';

$asset = market_asset('USDCAD');
echo "Asset: " . json_encode($asset) . "\n\n";

$nowMs = (int)floor(microtime(true) * 1000);
echo "Testing market_price_at...\n";
$price = market_price_at($asset, $nowMs);
echo "Result Price: $price\n\n";

echo "Testing market_candles...\n";
$candles = market_candles($asset, 60, 10);
echo "Candles count: " . count($candles) . "\n";
if (!empty($candles)) {
    echo "Sample candle: " . json_encode(end($candles)) . "\n";
}
