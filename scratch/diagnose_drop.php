<?php
require __DIR__ . '/../app/bootstrap.php';

echo "1. Current USDCAD asset in DB:\n";
$asset = market_asset('USDCAD', false);
print_r($asset);

echo "\n2. Live price from market_price_at(USDCAD):\n";
$livePrice = market_price_at($asset, (int)(microtime(true)*1000));
echo "Live Price: $livePrice\n";

echo "\n3. Live price from fetch_market_price(USDCAD):\n";
echo "fetch_market_price: " . fetch_market_price('USDCAD') . "\n";

echo "\n4. Yahoo Forex price for USDCAD:\n";
echo "fetch_yahoo_forex_price: " . fetch_yahoo_forex_price('USDCAD') . "\n";

echo "\n5. Yahoo Forex candles for USDCAD (last 10):\n";
$yfCandles = fetch_yahoo_forex_candles('USDCAD', 60, 10);
foreach ($yfCandles as $c) {
    echo date('Y-m-d H:i:s', (int)($c['openTime']/1000)) . " | O: {$c['open']} | H: {$c['high']} | L: {$c['low']} | C: {$c['close']}\n";
}

echo "\n6. Market snapshot for USDCAD (interval 5s, count 20):\n";
$snap5 = market_snapshot('USDCAD', 5, 20, true);
echo "Snapshot Asset Price: " . $snap5['asset']['price'] . "\n";
$candles = $snap5['candles'];
echo "Total candles: " . count($candles) . "\n";
$lastCandles = array_slice($candles, -15);
foreach ($lastCandles as $c) {
    echo date('H:i:s', (int)($c['time']/1000)) . " | O: {$c['open']} | H: {$c['high']} | L: {$c['low']} | C: {$c['close']}\n";
}

echo "\n7. Market snapshot for USDCAD (interval 60s, count 20):\n";
$snap60 = market_snapshot('USDCAD', 60, 20, true);
echo "Snapshot Asset Price: " . $snap60['asset']['price'] . "\n";
$lastCandles60 = array_slice($snap60['candles'], -15);
foreach ($lastCandles60 as $c) {
    echo date('H:i:s', (int)($c['time']/1000)) . " | O: {$c['open']} | H: {$c['high']} | L: {$c['low']} | C: {$c['close']}\n";
}
