<?php
require __DIR__ . '/../app/bootstrap.php';

echo "1. Checking EURUSD in DB:\n";
$asset = market_asset('EURUSD', false);
print_r($asset);

echo "\n2. Testing Binance price for EURUSDT:\n";
try {
    $bp = fetch_binance_price('EURUSDT');
    echo "Binance EURUSDT price: $bp\n";
} catch (Throwable $e) {
    echo "Binance price error: " . $e->getMessage() . "\n";
}

echo "\n3. Testing Binance klines for EURUSDT:\n";
try {
    $bk = fetch_binance_klines('EURUSDT', 60, 5);
    echo "Binance EURUSDT klines count: " . count($bk) . "\n";
    print_r(array_slice($bk, 0, 2));
} catch (Throwable $e) {
    echo "Binance klines error: " . $e->getMessage() . "\n";
}

echo "\n4. Testing market_snapshot for EURUSD (interval 60s, count 10):\n";
try {
    $snap = market_snapshot('EURUSD', 60, 10, true);
    echo "Snapshot asset price: " . $snap['asset']['price'] . "\n";
    echo "Snapshot candles count: " . count($snap['candles']) . "\n";
    foreach ($snap['candles'] as $c) {
        echo date('Y-m-d H:i:s', (int)($c['time']/1000)) . " | O: {$c['open']} | H: {$c['high']} | L: {$c['low']} | C: {$c['close']}\n";
    }
} catch (Throwable $e) {
    echo "Snapshot error: " . $e->getMessage() . "\n";
}

echo "\n5. Testing market_snapshot for EURUSD (interval 5s, count 10):\n";
try {
    $snap5 = market_snapshot('EURUSD', 5, 10, true);
    echo "Snapshot 5s asset price: " . $snap5['asset']['price'] . "\n";
    foreach ($snap5['candles'] as $c) {
        echo date('Y-m-d H:i:s', (int)($c['time']/1000)) . " | O: {$c['open']} | H: {$c['high']} | L: {$c['low']} | C: {$c['close']}\n";
    }
} catch (Throwable $e) {
    echo "Snapshot 5s error: " . $e->getMessage() . "\n";
}
