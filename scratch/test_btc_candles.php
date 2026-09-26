<?php
require_once __DIR__ . '/../app/bootstrap.php';

$asset = market_asset('BTCUSD', false);
if (!$asset) {
    echo "BTCUSD asset not found\n";
    exit;
}

echo "Asset: " . $asset['symbol'] . " (source: " . ($asset['price_source'] ?? 'binance') . ")\n";

$candles = market_candles($asset, 60, 50);
echo "Fetched " . count($candles) . " 1m candles\n";

$allLows = [];
$allHighs = [];

foreach ($candles as $c) {
    $allLows[] = $c['low'];
    $allHighs[] = $c['high'];
}

$minLow = min($allLows);
$maxHigh = max($allHighs);
$range = $maxHigh - $minLow;

echo "Min Low: $minLow | Max High: $maxHigh | Range: $range\n";

if ($range > 500) {
    echo "ERROR: Abnormal spike range detected! Range = $range\n";
} else {
    echo "SUCCESS: Clean TradingView candle range! Range = $range\n";
}
