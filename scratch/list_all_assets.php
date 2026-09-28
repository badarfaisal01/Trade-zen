<?php
require 'app/bootstrap.php';
$stmt = db()->query('SELECT id, symbol, display_name, category, price_source, api_symbol, is_active FROM market_assets ORDER BY id ASC');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['id'] . "\t" . str_pad($r['symbol'], 10) . "\t" . str_pad($r['category'], 15) . "\t" . str_pad($r['price_source'], 10) . "\t" . $r['is_active'] . "\n";
}
