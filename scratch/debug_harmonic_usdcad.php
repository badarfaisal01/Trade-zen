<?php
require 'app/bootstrap.php';
$asset = market_asset('USDCAD');
$now = (int)floor(microtime(true) * 1000);

echo "Base price: " . $asset['base_price'] . "\n";
echo "Volatility: " . $asset['volatility'] . "\n";
echo "Market seed: " . $asset['market_seed'] . "\n";

for ($t = $now - (60 * 60 * 1000); $t <= $now; $t += (5 * 60 * 1000)) {
    $price = market_price_at($asset, $t, true);
    echo date('H:i:s', (int)($t / 1000)) . " => " . $price . "\n";
}

$live = market_price_at($asset, $now, false);
echo "Live at now: " . $live . "\n";
