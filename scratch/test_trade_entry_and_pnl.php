<?php
require_once __DIR__ . '/../app/market.php';

$asset = [
    'id' => 10,
    'symbol' => 'GOLDUSD',
    'display_name' => 'Gold / USD',
    'category' => 'commodities',
    'base_price' => 4283.00,
    'decimal_places' => 2,
    'volatility' => 0.0003,
    'market_seed' => 7777,
    'price_source' => 'internal',
    'is_active' => 1,
];

$t0 = (int)floor(microtime(true) * 1000);

echo "1. Initial price when opening Trade #1: " . market_price_at($asset, $t0) . "\n";

// Simulate 10 seconds later
$t1 = $t0 + 10000;
echo "2. Market price 10s later (Trade #2 entry point): " . market_price_at($asset, $t1) . "\n";

// Simulate 20 seconds later
$t2 = $t0 + 20000;
echo "3. Market price 20s later (Trade #3 entry point): " . market_price_at($asset, $t2) . "\n";

// Simulate 30 seconds later
$t3 = $t0 + 30000;
echo "4. Market price 30s later (Trade #4 entry point): " . market_price_at($asset, $t3) . "\n";
