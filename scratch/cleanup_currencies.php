<?php
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// Deactivate all currency assets except EURUSD, GBPUSD, USDJPY
$pdo->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='currencies' OR LOWER(category)='currency' OR category LIKE '%currenc%') AND symbol NOT IN ('EURUSD','GBPUSD','USDJPY')");

// Ensure the 3 currencies are active and configured for API Twelve Data
$pdo->exec("UPDATE market_assets SET is_active=1, price_source='api' WHERE symbol IN ('EURUSD','GBPUSD','USDJPY')");
$pdo->exec("UPDATE market_assets SET api_symbol='EUR/USD' WHERE symbol='EURUSD'");
$pdo->exec("UPDATE market_assets SET api_symbol='GBP/USD' WHERE symbol='GBPUSD'");
$pdo->exec("UPDATE market_assets SET api_symbol='USD/JPY' WHERE symbol='USDJPY'");

echo "DB Cleanup Completed.\n\nActive Currencies:\n";
$activeAssets = market_assets(true);
$count = 0;
foreach ($activeAssets as $asset) {
    if (market_is_currency($asset)) {
        $count++;
        echo "{$count}. Symbol: {$asset['symbol']} | Display: {$asset['display_name']} | API Symbol: {$asset['api_symbol']} | Source: {$asset['price_source']}\n";
    }
}
echo "Total Active Currencies: $count\n";
