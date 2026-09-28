<?php
require __DIR__ . '/../app/bootstrap.php';
$assets = market_assets(false);
foreach ($assets as $a) {
    echo sprintf(
        "%-10s | %-12s | base_price: %-10s | source_price: %-10s\n",
        $a['symbol'],
        $a['category'],
        $a['base_price'] ?? 'null',
        $a['source_price'] ?? 'null'
    );
}
