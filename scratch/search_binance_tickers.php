<?php
require __DIR__ . '/../app/bootstrap.php';
$tickers = fetch_binance_all_prices();
foreach (['JPY', 'XAG', 'SILVER', 'US30', 'US500', 'SPX', 'DJI', 'DOW'] as $keyword) {
    echo "=== Matching '$keyword' in Binance spot: ===" . PHP_EOL;
    $matches = [];
    foreach ($tickers as $sym => $p) {
        if (strpos($sym, $keyword) !== false) {
            $matches[$sym] = $p;
        }
    }
    print_r($matches);
}
