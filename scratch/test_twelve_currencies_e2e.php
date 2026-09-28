<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once BASE_PATH . '/app/market.php';
require_once BASE_PATH . '/app/market_api.php';

echo "=========================================================\n";
echo " TWELVE DATA 5 CURRENCIES END-TO-END INTEGRATION TEST\n";
echo "=========================================================\n\n";

// 1. Verify Active Currencies in DB
$assets = market_assets(true);
$currencyAssets = array_values(array_filter($assets, fn($a) => market_is_currency($a)));

echo "1. VERIFYING ACTIVE CURRENCIES IN PROJECT:\n";
echo "   Total active currencies found: " . count($currencyAssets) . "\n";
foreach ($currencyAssets as $idx => $curr) {
    echo "   [" . ($idx + 1) . "] Symbol: {$curr['symbol']} | Display: {$curr['display_name']} | API Symbol: {$curr['api_symbol']}\n";
}

if (count($currencyAssets) !== 5) {
    echo "❌ ERROR: Expected 5 active currencies, but found " . count($currencyAssets) . "\n";
    exit(1);
} else {
    echo "✅ PASS: Exactly 5 currencies are active.\n\n";
}

// 2. Test Live Price Fetching via Twelve Data for each Currency
echo "2. TESTING LIVE PRICE FETCHING (Twelve Data):\n";
$nowMs = (int)floor(microtime(true) * 1000);
foreach ($currencyAssets as $curr) {
    try {
        $price = market_price_at($curr, $nowMs);
        echo "   {$curr['symbol']} ({$curr['api_symbol']}) Live Price: {$price}\n";
        if ($price <= 0) {
            throw new Exception("Received non-positive price");
        }
    } catch (Throwable $e) {
        echo "❌ ERROR fetching price for {$curr['symbol']}: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "✅ PASS: All 5 live prices fetched successfully via Twelve Data API.\n\n";

// 3. Test Candlestick Chart Data Fetching via Twelve Data for each Currency
echo "3. TESTING CANDLESTICK CHART DATA (Twelve Data):\n";
foreach ($currencyAssets as $curr) {
    try {
        $candles = market_candles($curr, 60, 20);
        echo "   {$curr['symbol']} ({$curr['api_symbol']}) Candle Count: " . count($candles) . "\n";
        if (empty($candles)) {
            throw new Exception("Returned empty candles array");
        }
        $latest = end($candles);
        echo "      Latest Candle: Open={$latest['open']} High={$latest['high']} Low={$latest['low']} Close={$latest['close']}\n";
    } catch (Throwable $e) {
        echo "❌ ERROR fetching candles for {$curr['symbol']}: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "✅ PASS: All 5 currency candlestick charts generated successfully via Twelve Data API.\n\n";

echo "=========================================================\n";
echo " RESULT: ALL 5 CURRENCIES WORKING END-TO-END! 🎉\n";
echo "=========================================================\n";
