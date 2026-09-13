<?php
require __DIR__ . '/../app/bootstrap.php';

$key = 'da9vrlpr01qlciegvcfgda9vrlpr01qlciegvcg0';
$urls = [
    'US30' => "https://finnhub.io/api/v1/quote?symbol=DIA&token=$key",
    'US500' => "https://finnhub.io/api/v1/quote?symbol=SPY&token=$key",
    'XAGUSD' => "https://finnhub.io/api/v1/quote?symbol=SLV&token=$key",
    'USDJPY_forex' => "https://finnhub.io/api/v1/quote?symbol=OANDA:USD_JPY&token=$key",
];

foreach ($urls as $name => $url) {
    $res = @file_get_contents($url);
    echo "$name: $res\n";
}
