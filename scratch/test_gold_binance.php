<?php
require 'app/bootstrap.php';
try {
    $paxg = fetch_binance_price('PAXGUSDT');
    echo "PAXGUSDT Binance price: " . $paxg . "\n";
} catch (Throwable $e) {
    echo "PAXGUSDT failed: " . $e->getMessage() . "\n";
}

try {
    $xaut = fetch_binance_price('XAUTUSDT');
    echo "XAUTUSDT Binance price: " . $xaut . "\n";
} catch (Throwable $e) {
    echo "XAUTUSDT failed: " . $e->getMessage() . "\n";
}
