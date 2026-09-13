<?php
require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// Ensure market_assets table enum supports 'binance' and 'api'
try {
    $pdo->exec("ALTER TABLE market_assets MODIFY price_source ENUM('internal','manual','api','binance') NOT NULL DEFAULT 'binance'");
} catch (Throwable $e) {
    // Ignore if already modified
}

$assets = [
    [
        'symbol' => 'XAUUSD',
        'display_name' => 'Gold / USD',
        'category' => 'Commodities',
        'base_price' => 4350.00,
        'decimal_places' => 2,
        'payout_percent' => 85.00,
        'volatility' => 0.0025,
        'market_seed' => 88101,
        'price_source' => 'binance',
        'api_symbol' => 'PAXGUSDT',
        'sort_order' => 1,
        'is_active' => 1,
    ],
    [
        'symbol' => 'EURUSD',
        'display_name' => 'EUR/USD',
        'category' => 'Currencies',
        'base_price' => 1.1590,
        'decimal_places' => 5,
        'payout_percent' => 88.00,
        'volatility' => 0.0015,
        'market_seed' => 88102,
        'price_source' => 'binance',
        'api_symbol' => 'EURUSDT',
        'sort_order' => 2,
        'is_active' => 1,
    ],
    [
        'symbol' => 'GBPUSD',
        'display_name' => 'GBP/USD',
        'category' => 'Currencies',
        'base_price' => 1.1800,
        'decimal_places' => 5,
        'payout_percent' => 87.00,
        'volatility' => 0.0018,
        'market_seed' => 88103,
        'price_source' => 'binance',
        'api_symbol' => 'GBPUSDT',
        'sort_order' => 3,
        'is_active' => 1,
    ],
    [
        'symbol' => 'XAGUSD',
        'display_name' => 'Silver / USD',
        'category' => 'Commodities',
        'base_price' => 33.50,
        'decimal_places' => 3,
        'payout_percent' => 82.00,
        'volatility' => 0.0035,
        'market_seed' => 88104,
        'price_source' => 'binance',
        'api_symbol' => 'XAGUSD',
        'sort_order' => 4,
        'is_active' => 1,
    ],
    [
        'symbol' => 'JPYUSD',
        'display_name' => 'JPY/USD',
        'category' => 'Currencies',
        'base_price' => 0.00651,
        'decimal_places' => 6,
        'payout_percent' => 82.00,
        'volatility' => 0.0020,
        'market_seed' => 88105,
        'price_source' => 'binance',
        'api_symbol' => 'JPYUSD',
        'sort_order' => 5,
        'is_active' => 1,
    ],
    [
        'symbol' => 'USDJPY',
        'display_name' => 'USD/JPY',
        'category' => 'Currencies',
        'base_price' => 153.50,
        'decimal_places' => 3,
        'payout_percent' => 82.00,
        'volatility' => 0.0020,
        'market_seed' => 88106,
        'price_source' => 'binance',
        'api_symbol' => 'USDJPY',
        'sort_order' => 6,
        'is_active' => 1,
    ],
    [
        'symbol' => 'US30',
        'display_name' => 'US30 (Dow Jones)',
        'category' => 'Indices',
        'base_price' => 43500.00,
        'decimal_places' => 2,
        'payout_percent' => 85.00,
        'volatility' => 0.0025,
        'market_seed' => 88107,
        'price_source' => 'binance',
        'api_symbol' => 'US30',
        'sort_order' => 7,
        'is_active' => 1,
    ],
    [
        'symbol' => 'US500',
        'display_name' => 'US500 (S&P 500)',
        'category' => 'Indices',
        'base_price' => 5850.00,
        'decimal_places' => 2,
        'payout_percent' => 85.00,
        'volatility' => 0.0025,
        'market_seed' => 88108,
        'price_source' => 'binance',
        'api_symbol' => 'US500',
        'sort_order' => 8,
        'is_active' => 1,
    ],
    [
        'symbol' => 'BTCUSD',
        'display_name' => 'Bitcoin / USD',
        'category' => 'Crypto',
        'base_price' => 77000.00,
        'decimal_places' => 2,
        'payout_percent' => 90.00,
        'volatility' => 0.0040,
        'market_seed' => 88109,
        'price_source' => 'binance',
        'api_symbol' => 'BTCUSDT',
        'sort_order' => 9,
        'is_active' => 1,
    ],
    [
        'symbol' => 'ETHUSD',
        'display_name' => 'Ethereum / USD',
        'category' => 'Crypto',
        'base_price' => 2500.00,
        'decimal_places' => 2,
        'payout_percent' => 88.00,
        'volatility' => 0.0045,
        'market_seed' => 88110,
        'price_source' => 'binance',
        'api_symbol' => 'ETHUSDT',
        'sort_order' => 10,
        'is_active' => 1,
    ],
    [
        'symbol' => 'BNBUSD',
        'display_name' => 'BNB / USD',
        'category' => 'Crypto',
        'base_price' => 720.00,
        'decimal_places' => 2,
        'payout_percent' => 86.00,
        'volatility' => 0.0040,
        'market_seed' => 88111,
        'price_source' => 'binance',
        'api_symbol' => 'BNBUSDT',
        'sort_order' => 11,
        'is_active' => 1,
    ],
];

// First, deactivate any other assets that are not in this 11 list
$symbolsList = array_map(fn($a) => $a['symbol'], $assets);
$placeholders = implode(',', array_fill(0, count($symbolsList), '?'));
$deactStmt = $pdo->prepare("UPDATE market_assets SET is_active=0 WHERE symbol NOT IN ($placeholders)");
$deactStmt->execute($symbolsList);

$upsertSql = "INSERT INTO market_assets 
    (symbol, display_name, category, base_price, decimal_places, payout_percent, volatility, market_seed, price_source, api_symbol, sort_order, is_active, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        display_name = VALUES(display_name),
        category = VALUES(category),
        base_price = VALUES(base_price),
        decimal_places = VALUES(decimal_places),
        payout_percent = VALUES(payout_percent),
        volatility = VALUES(volatility),
        price_source = VALUES(price_source),
        api_symbol = VALUES(api_symbol),
        sort_order = VALUES(sort_order),
        is_active = VALUES(is_active),
        updated_at = NOW()";

$stmt = $pdo->prepare($upsertSql);

foreach ($assets as $asset) {
    $stmt->execute([
        $asset['symbol'],
        $asset['display_name'],
        $asset['category'],
        $asset['base_price'],
        $asset['decimal_places'],
        $asset['payout_percent'],
        $asset['volatility'],
        $asset['market_seed'],
        $asset['price_source'],
        $asset['api_symbol'],
        $asset['sort_order'],
        $asset['is_active'],
    ]);
    echo "Upserted {$asset['symbol']}\n";
}

echo "Database assets updated successfully.\n";
