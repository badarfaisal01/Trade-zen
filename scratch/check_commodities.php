<?php
require 'app/bootstrap.php';
$rows = db()->query("SELECT id, symbol, display_name, category, price_source, api_symbol, is_active FROM market_assets WHERE LOWER(category) LIKE '%commod%' OR symbol LIKE '%XAU%' OR symbol LIKE '%GOLD%' OR display_name LIKE '%Gold%'")->fetchAll(PDO::FETCH_ASSOC);
echo "Commodities in DB:\n";
print_r($rows);

$activeAssets = market_assets(true);
echo "\nAll Active Assets Categories:\n";
$catMap = [];
foreach ($activeAssets as $a) {
    $catMap[$a['category']][] = $a['symbol'] . ' (' . $a['display_name'] . ') [source: ' . $a['price_source'] . ', api: ' . $a['api_symbol'] . ']';
}
print_r($catMap);
