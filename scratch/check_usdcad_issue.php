<?php
require 'app/bootstrap.php';
$asset = market_asset('USDCAD');
echo "Asset: \n";
print_r($asset);

$tdKey = (string)env('TWELVEDATA_API_KEY', '');
echo "TWELVEDATA_API_KEY: " . ($tdKey !== '' ? 'Present' : 'Empty') . "\n";

$candles = market_candles($asset, 60, 20);
echo "Last 3 candles from market_candles:\n";
print_r(array_slice($candles, -3));

$snap = market_snapshot('USDCAD');
echo "market_snapshot:\n";
print_r($snap);
