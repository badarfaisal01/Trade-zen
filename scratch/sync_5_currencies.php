<?php
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// Check if USDCAD exists in market_assets
$stmt = $pdo->prepare("SELECT id FROM market_assets WHERE symbol='USDCAD'");
$stmt->execute();
$usdcad = $stmt->fetch();

if (!$usdcad) {
    echo "Inserting USDCAD into market_assets...\n";
    $pdo->prepare("
        INSERT INTO market_assets (symbol, display_name, category, base_price, decimal_places, payout_percent, volatility, market_seed, price_source, api_symbol, is_active, sort_order, updated_at)
        VALUES ('USDCAD', 'USD/CAD', 'Currencies', 1.3950, 5, 85.00, 0.00180000, 88406, 'api', 'USD/CAD', 1, 45, NOW())
    ")->execute();
}

// Check if AUDUSD exists in market_assets
$stmt = $pdo->prepare("SELECT id FROM market_assets WHERE symbol='AUDUSD'");
$stmt->execute();
$audusd = $stmt->fetch();

if (!$audusd) {
    echo "Inserting AUDUSD into market_assets...\n";
    $pdo->prepare("
        INSERT INTO market_assets (symbol, display_name, category, base_price, decimal_places, payout_percent, volatility, market_seed, price_source, api_symbol, is_active, sort_order, updated_at)
        VALUES ('AUDUSD', 'AUD/USD', 'Currencies', 0.6550, 5, 85.00, 0.00180000, 88405, 'api', 'AUD/USD', 1, 44, NOW())
    ")->execute();
}

// Ensure all 5 are active and configured with price_source='api' and correct api_symbol
$pdo->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='currencies' OR LOWER(category)='currency' OR category LIKE '%currenc%') AND symbol NOT IN ('EURUSD','GBPUSD','USDJPY','AUDUSD','USDCAD')");
$pdo->exec("UPDATE market_assets SET is_active=1, price_source='api' WHERE symbol IN ('EURUSD','GBPUSD','USDJPY','AUDUSD','USDCAD')");
$pdo->exec("UPDATE market_assets SET api_symbol='EUR/USD' WHERE symbol='EURUSD'");
$pdo->exec("UPDATE market_assets SET api_symbol='GBP/USD' WHERE symbol='GBPUSD'");
$pdo->exec("UPDATE market_assets SET api_symbol='USD/JPY' WHERE symbol='USDJPY'");
$pdo->exec("UPDATE market_assets SET api_symbol='AUD/USD' WHERE symbol='AUDUSD'");
$pdo->exec("UPDATE market_assets SET api_symbol='USD/CAD' WHERE symbol='USDCAD'");

echo "\n--- CURRENT ACTIVE CURRENCIES ---\n";
$currencies = $pdo->query("SELECT id, symbol, display_name, category, price_source, api_symbol, is_active FROM market_assets WHERE is_active=1 AND (LOWER(category)='currencies' OR LOWER(category)='currency')")->fetchAll(PDO::FETCH_ASSOC);

foreach ($currencies as $idx => $c) {
    echo ($idx + 1) . ". Symbol: {$c['symbol']} | Name: {$c['display_name']} | API Symbol: {$c['api_symbol']} | Source: {$c['price_source']}\n";
}
