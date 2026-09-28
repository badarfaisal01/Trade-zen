<?php
require __DIR__ . '/../app/bootstrap.php';
$assets = db()->query("SELECT id, symbol, display_name, category, base_price, decimal_places, price_source, api_symbol, is_active FROM market_assets WHERE category='Currencies' OR symbol IN ('EURUSD','GBPUSD','USDJPY','AUDUSD')")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($assets, JSON_PRETTY_PRINT);
