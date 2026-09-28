<?php
require __DIR__ . '/../app/bootstrap.php';

$key = env('ALPHAVANTAGE_API_KEY');
$symbols = [
    'EURUSD' => ['from' => 'EUR', 'to' => 'USD'],
    'USDCAD' => ['from' => 'USD', 'to' => 'CAD'],
    'USDJPY' => ['from' => 'USD', 'to' => 'JPY'],
    'GBPUSD' => ['from' => 'GBP', 'to' => 'USD'],
    'AUDUSD' => ['from' => 'AUD', 'to' => 'USD'],
];

foreach ($symbols as $sym => $pair) {
    $price = fetch_alphavantage_price($sym, $key);
    echo "$sym: $price\n";
    if ($price > 0) {
        db()->prepare("UPDATE market_assets SET base_price = ? WHERE symbol = ?")->execute([$price, $sym]);
        $stmt = db()->prepare("SELECT id FROM market_assets WHERE symbol = ?");
        $stmt->execute([$sym]);
        $id = $stmt->fetchColumn();
        if ($id) {
            db()->prepare("INSERT INTO market_price_cache (asset_id, price, source, fetched_at) VALUES (?, ?, 'alphavantage.co', NOW()) ON DUPLICATE KEY UPDATE price = VALUES(price), source = VALUES(source), fetched_at = NOW()")->execute([$id, $price]);
        }
    }
}
