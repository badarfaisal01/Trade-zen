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
    $url = "https://www.alphavantage.co/query?function=FX_INTRADAY&from_symbol={$pair['from']}&to_symbol={$pair['to']}&interval=1min&outputsize=compact&apikey={$key}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "$sym FX_INTRADAY HTTP $code: " . substr($res, 0, 200) . "\n";
    $json = json_decode($res, true);
    if (isset($json['Time Series FX (1min)'])) {
        $first = reset($json['Time Series FX (1min)']);
        echo "  Latest 1m: close=" . ($first['4. close'] ?? 'null') . "\n";
    }
}
