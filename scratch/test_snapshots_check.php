<?php
require 'app/bootstrap.php';

echo "Testing USDCAD interval 60:\n";
$snap60 = market_snapshot('USDCAD', 60, 50);
$c60 = $snap60['candles'];
echo "Candle count: " . count($c60) . "\n";
echo "First candle: " . json_encode($c60[0]) . "\n";
echo "Last candle: " . json_encode(end($c60)) . "\n";
echo "Asset price: " . $snap60['asset']['price'] . "\n\n";

echo "Testing USDCAD interval 5:\n";
$snap5 = market_snapshot('USDCAD', 5, 50);
$c5 = $snap5['candles'];
echo "Candle count: " . count($c5) . "\n";
echo "First candle: " . json_encode($c5[0]) . "\n";
echo "Last candle: " . json_encode(end($c5)) . "\n";
echo "Asset price: " . $snap5['asset']['price'] . "\n\n";

echo "Testing USDJPY interval 60:\n";
$snapJPY = market_snapshot('USDJPY', 60, 50);
$cJPY = $snapJPY['candles'];
echo "Candle count: " . count($cJPY) . "\n";
echo "First candle: " . json_encode($cJPY[0]) . "\n";
echo "Last candle: " . json_encode(end($cJPY)) . "\n";
echo "Asset price: " . $snapJPY['asset']['price'] . "\n";
