<?php
require 'app/bootstrap.php';
$xau = market_asset('XAUUSD');
$xaut = market_asset('XAUTUSD');

echo "XAUUSD status:\n";
print_r(market_status($xau));

echo "\nXAUTUSD status:\n";
print_r(market_status($xaut));
