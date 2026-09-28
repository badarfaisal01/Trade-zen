<?php
require __DIR__ . '/../app/bootstrap.php';

$key = env('ALPHAVANTAGE_API_KEY');
$symbols = [
    'EURUSD' => ['from' => 'EUR', 'to' => 'USD'],
    'USDCAD' => ['from' => 'USD', 'to' => 'CAD'],
    'USDJPY' => ['from' => 'USD', 'to' => 'JPY'],
    'GBPUSD' => ['from' => 'GBP', 'to' => 'USD'],
    'AUDUSD' => ['from' => 'AUD', 'to' => 'USD'],
];

foreach ($symbols as $sym => $pair) {
    $url = "https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency={$pair['from']}&to_currency={$pair['to']}&apikey={$key}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    
    $json = json_decode($res, true);
    $rate = $json['Realtime Currency Exchange Rate']['5. Exchange Rate'] ?? null;
    echo "$sym Exchange Rate: $rate\n";
    usleep(1200000); // 1.2s delay for AV rate limit
}
