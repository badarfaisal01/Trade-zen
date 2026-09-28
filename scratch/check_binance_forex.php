<?php
require __DIR__ . '/../app/bootstrap.php';

$res = file_get_contents('https://api.binance.com/api/v3/ticker/price');
$all = json_decode($res, true);
$symbols = array_column($all, 'symbol');

$search = ['EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'NZD', 'USD'];
foreach ($search as $curr) {
    $matches = array_filter($symbols, fn($s) => strpos($s, $curr) !== false && (strpos($s, 'USDT') !== false || strpos($s, 'USD') !== false || strpos($s, 'FDUSD') !== false));
    echo "=== Matches for $curr ===\n";
    echo implode(', ', array_slice($matches, 0, 15)) . "\n\n";
}
