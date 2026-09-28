<?php
define('BASE_PATH', dirname(__DIR__));
require_once __DIR__ . '/../app/env.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/market_api.php';
require_once __DIR__ . '/../app/market.php';

$pdo = db();

// 1. Set price_source = 'api' and api_symbol for the 4 core currency pairs
$coreCurrencies = [
    'EURUSD' => 'EUR/USD',
    'GBPUSD' => 'GBP/USD',
    'USDJPY' => 'USD/JPY',
    'AUDUSD' => 'AUD/USD',
];

foreach ($coreCurrencies as $symbol => $apiSym) {
    $stmt = $pdo->prepare("UPDATE market_assets SET price_source='api', api_symbol=:api_sym, is_active=1 WHERE symbol=:symbol");
    $stmt->execute([':api_sym' => $apiSym, ':symbol' => $symbol]);
}

// 2. Deactivate all other currencies
$pdo->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='currencies' OR LOWER(category)='currency' OR category LIKE '%curr%') AND symbol NOT IN ('EURUSD','GBPUSD','USDJPY','AUDUSD')");

echo "Updated currency assets in database.\n\n";

// 3. Test market_candles() and market_price_at() for all 4 pairs
$activeCurrencies = market_assets(true);
foreach ($activeCurrencies as $asset) {
    if (in_array($asset['symbol'], array_keys($coreCurrencies), true)) {
        echo "Testing {$asset['symbol']} ({$asset['display_name']}):\n";
        echo "  DB price_source: {$asset['price_source']} | api_symbol: {$asset['api_symbol']}\n";

        // Test live price
        try {
            $livePrice = market_reference_price($asset);
            echo "  Live Twelve Data Price: {$livePrice}\n";
        } catch (Exception $e) {
            echo "  Live Price Error: {$e->getMessage()}\n";
        }

        // Test candles
        try {
            $candles = market_candles($asset, 60, 100);
            echo "  Generated Candles Count: " . count($candles) . "\n";
            if (!empty($candles)) {
                $last = end($candles);
                echo "  First Candle: " . date('Y-m-d H:i:s', (int)($candles[0]['time'] / 1000)) . " | Open: {$candles[0]['open']} | Close: {$candles[0]['close']}\n";
                echo "  Last Candle:  " . date('Y-m-d H:i:s', (int)($last['time'] / 1000)) . " | Open: {$last['open']} | Close: {$last['close']}\n";
            }
        } catch (Exception $e) {
            echo "  Candles Error: {$e->getMessage()}\n";
        }
        echo "\n";
    }
}
