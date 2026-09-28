<?php
require __DIR__ . '/../app/bootstrap.php';

$asset = market_asset('EURUSD');
echo "market_is_currency: " . (market_is_currency($asset) ? 'YES' : 'NO') . "\n";
echo "price_source: " . $asset['price_source'] . "\n";
echo "api_symbol: " . $asset['api_symbol'] . "\n";

$candles = market_candles($asset, 60, 10);
$last = end($candles);
echo "market_candles last: " . $last['close'] . "\n";

$bPrice = fetch_binance_price('EURUSDT');
echo "fetch_binance_price(EURUSDT): $bPrice\n";

$mPrice = market_price_at($asset, time()*1000);
echo "market_price_at: $mPrice\n";
