<?php
require_once __DIR__ . '/../app/market.php';

$assetsToTest = [
    [
        'id' => 1,
        'symbol' => 'EURUSD',
        'display_name' => 'EUR/USD',
        'category' => 'currencies',
        'base_price' => 1.0850,
        'decimal_places' => 5,
        'volatility' => 0.00008,
        'market_seed' => 12345,
        'price_source' => 'internal',
    ],
    [
        'id' => 2,
        'symbol' => 'GBPUSD',
        'display_name' => 'GBP/USD',
        'category' => 'currencies',
        'base_price' => 1.3050,
        'decimal_places' => 5,
        'volatility' => 0.00010,
        'market_seed' => 99999,
        'price_source' => 'internal',
    ],
    [
        'id' => 3,
        'symbol' => 'BTCUSD',
        'display_name' => 'BTC/USD',
        'category' => 'crypto',
        'base_price' => 65000.0,
        'decimal_places' => 2,
        'volatility' => 0.001,
        'market_seed' => 67890,
        'price_source' => 'internal',
    ],
];

foreach ($assetsToTest as $asset) {
    echo "=== Real app/market.php Output for {$asset['symbol']} ({$asset['category']}) ===\n";
    $candles = market_candles($asset, 5, 800);
    $first = $candles[0];
    $last = end($candles);
    $minPrice = min(array_column($candles, 'low'));
    $maxPrice = max(array_column($candles, 'high'));
    $avgBody = array_sum(array_map(fn($c) => abs($c['close'] - $c['open']), $candles)) / count($candles);
    $avgWick = array_sum(array_map(fn($c) => ($c['high'] - $c['low']) - abs($c['close'] - $c['open']), $candles)) / count($candles);

    echo "Count: " . count($candles) . "\n";
    echo "First Close: {$first['close']}, Last Close: {$last['close']}\n";
    echo "Range: Min = {$minPrice}, Max = {$maxPrice} (Delta: " . ($maxPrice - $minPrice) . ")\n";
    echo "Average Body: {$avgBody}\n";
    echo "Average Wick Total: {$avgWick}\n";

    echo "Sample prices across 800 candles:\n";
    for ($i = 0; $i < count($candles); $i += 100) {
        $c = $candles[$i];
        echo sprintf("Candle %3d: Open=%.5f Close=%.5f High=%.5f Low=%.5f Body=%.5f\n", $i, $c['open'], $c['close'], $c['high'], $c['low'], abs($c['close'] - $c['open']));
    }
    echo "\n";
}
