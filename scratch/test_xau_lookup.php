<?php
require 'app/bootstrap.php';
$asset = market_asset('XAUUSD', false);
echo "market_asset('XAUUSD', false): \n";
print_r($asset);

$assetActive = market_asset('XAUUSD', true);
echo "market_asset('XAUUSD', true): \n";
print_r($assetActive);
