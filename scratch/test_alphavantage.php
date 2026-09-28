<?php
require __DIR__ . '/../app/bootstrap.php';

$apiKey = 'F8Y3Y16L2S3US7B6';
$symbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD', 'USDCHF', 'EURGBP'];

foreach ($symbols as $sym) {
    $from = substr($sym, 0, 3);
    $to = substr($sym, 3, 3);
    $url = "https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency={$from}&to_currency={$to}&apikey={$apiKey}";
    $res = @file_get_contents($url);
    $data = json_decode((string)$res, true);
    if (isset($data['Realtime Currency Exchange Rate']['5. Exchange Rate'])) {
        echo "{$sym}: " . $data['Realtime Currency Exchange Rate']['5. Exchange Rate'] . "\n";
    } else {
        echo "{$sym} FAILED: " . json_encode($data) . "\n";
    }
}
