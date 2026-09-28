<?php
require __DIR__ . '/../app/bootstrap.php';

foreach (['EURUSDT', 'GBPUSDT', 'AUDUSDT'] as $sym) {
    $k = fetch_binance_klines($sym, 60, 50);
    $last = end($k);
    echo "$sym klines count: " . count($k) . ", LastClose: " . ($last['close'] ?? 'null') . "\n";
}
