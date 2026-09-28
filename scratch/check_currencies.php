<?php
require_once __DIR__ . '/../app/bootstrap.php';

$assets = market_assets();
echo "--- ALL CURRENCIES ---\n";
foreach ($assets as $a) {
    if (strtolower($a['category'] ?? '') === 'currencies') {
        echo "ID: {$a['id']} | Symbol: {$a['symbol']} | Name: {$a['display_name']} | Active: {$a['is_active']} | Source: {$a['price_source']} | API Symbol: {$a['api_symbol']}\n";
    }
}
