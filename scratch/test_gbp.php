<?php
require __DIR__ . '/../app/bootstrap.php';
$p = fetch_binance_all_prices();
$gbp = [];
foreach ($p as $k => $v) {
    if (strpos($k, 'GBP') !== false) {
        $gbp[$k] = $v;
    }
}
print_r($gbp);
