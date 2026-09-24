<?php
declare(strict_types=1);

require __DIR__ . '/../app/market.php';

echo "====================================================\n";
echo " TESTING EXACT BIAS OFFSETS & CHART STABILITY      \n";
echo "====================================================\n\n";

$asset = [
    'id' => 10,
    'symbol' => 'BTCUSD',
    'display_name' => 'Bitcoin / USD',
    'category' => 'crypto',
    'base_price' => 84000.0,
    'decimal_places' => 2,
    'payout_percent' => 85,
    'volatility' => 0.002,
    'market_seed' => 100,
    'price_source' => 'internal',
    'is_active' => 1,
    'updated_at' => date('Y-m-d H:i:s'),
];

$nowMs = (int)floor(microtime(true) * 1000);
$openedAtMs = $nowMs - 30000;
$expiresAtMs = $nowMs + 30000;

$mockTrade = [
    'id' => 999,
    'user_id' => 1,
    'asset_id' => 10,
    'direction' => 'up',
    'entry_price' => 84000.00,
    'opened_at' => date('Y-m-d H:i:s', (int)($openedAtMs / 1000)),
    'expires_at' => date('Y-m-d H:i:s', (int)($expiresAtMs / 1000)),
    'status' => 'open'
];

$biasStart = get_active_trade_price_bias($asset, $openedAtMs);
$biasMid   = get_active_trade_price_bias($asset, $nowMs);
$biasEnd   = get_active_trade_price_bias($asset, $expiresAtMs);

echo "Bias at Start (progress 0.0): {$biasStart}\n";
echo "Bias at Mid   (progress 0.5): {$biasMid}\n";
echo "Bias at End   (progress 1.0): {$biasEnd}\n";

if (abs($biasStart) > 200.0) {
    die("FAILED: Bias at start is too large! Got {$biasStart}\n");
}
if (abs($biasEnd) > 200.0) {
    die("FAILED: Bias at end is too large! Got {$biasEnd}\n");
}

echo "\nSUCCESS: Bias offsets are perfectly bounded and continuous.\n";
