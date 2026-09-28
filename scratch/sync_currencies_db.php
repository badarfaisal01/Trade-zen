<?php
require __DIR__ . '/../app/bootstrap.php';

$key = env('TWELVEDATA_API_KEY');
$pairs = [
    'EURUSD' => 'EUR/USD',
    'GBPUSD' => 'GBP/USD',
    'USDJPY' => 'USD/JPY',
    'AUDUSD' => 'AUD/USD',
    'USDCAD' => 'USD/CAD',
];

foreach ($pairs as $sym => $apiSym) {
    try {
        $p = fetch_twelvedata_price($apiSym, $key);
        echo "$sym ($apiSym): $p\n";
        if ($p > 0) {
            db()->prepare("UPDATE market_assets SET base_price = ? WHERE symbol = ?")->execute([$p, $sym]);
            $stmt = db()->prepare("SELECT id FROM market_assets WHERE symbol = ?");
            $stmt->execute([$sym]);
            $id = $stmt->fetchColumn();
            if ($id) {
                db()->prepare("INSERT INTO market_price_cache (asset_id, price, source, fetched_at) VALUES (?, ?, 'twelvedata.com', NOW()) ON DUPLICATE KEY UPDATE price = VALUES(price), source = VALUES(source), fetched_at = NOW()")->execute([$id, $p]);
            }
        }
    } catch (Throwable $e) {
        echo "$sym error: " . $e->getMessage() . "\n";
    }
}
