<?php
require __DIR__ . '/../app/bootstrap.php';

$tickers = fetch_binance_all_prices();
echo 'Total Binance tickers: ' . count($tickers) . PHP_EOL;

$symbolsToTest = [
    'BTCUSD' => 'BTCUSDT',
    'ETHUSD' => 'ETHUSDT',
    'BNBUSD' => 'BNBUSDT',
    'XAUUSD' => 'PAXGUSDT',
    'EURUSD' => 'EURUSDT',
    'GBPUSD' => 'GBPUSDT',
    'XAGUSD' => 'XAGUSDT',
    'JPYUSD' => 'JPYUSDT',
    'USDJPY' => 'USDJPY',
    'US30'   => 'US30',
    'US500'  => 'US500',
];

foreach ($symbolsToTest as $name => $binanceSym) {
    $price = $tickers[$binanceSym] ?? 'NOT FOUND';
    echo "{$name} (Binance: {$binanceSym}) => {$price}" . PHP_EOL;
}
