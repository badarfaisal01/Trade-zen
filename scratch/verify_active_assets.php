<?php
require __DIR__ . '/../app/bootstrap.php';

$assets = market_assets(true);
echo "Total Active Assets: " . count($assets) . "\n";
foreach ($assets as $a) {
    echo sprintf(
        "%-10s | %-12s | %-15s | price_source: %-10s | is_active: %d\n",
        $a['symbol'],
        $a['category'],
        $a['display_name'],
        $a['price_source'] ?? 'null',
        $a['is_active']
    );
}
