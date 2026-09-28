<?php
require __DIR__ . '/../app/bootstrap.php';

$key = env('TWELVEDATA_API_KEY');
$symbols = ['USD/CAD', 'EUR/USD', 'USD/JPY', 'GBP/USD', 'AUD/USD'];

function curlGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'res' => $res, 'err' => $err];
}

foreach ($symbols as $sym) {
    echo "======================= $sym =======================\n";
    
    // 1. Direct price endpoint
    $priceUrl = "https://api.twelvedata.com/price?symbol=" . urlencode($sym) . "&apikey=" . $key;
    $r1 = curlGet($priceUrl);
    echo "HTTP {$r1['code']} /price: " . substr($r1['res'], 0, 100) . "\n";
    
    // 2. Direct quote endpoint
    $quoteUrl = "https://api.twelvedata.com/quote?symbol=" . urlencode($sym) . "&apikey=" . $key;
    $r2 = curlGet($quoteUrl);
    echo "HTTP {$r2['code']} /quote: " . substr($r2['res'], 0, 100) . "\n";
    
    // 3. Direct time_series endpoint
    $tsUrl = "https://api.twelvedata.com/time_series?symbol=" . urlencode($sym) . "&interval=1min&outputsize=5&apikey=" . $key;
    $r3 = curlGet($tsUrl);
    echo "HTTP {$r3['code']} /time_series: " . substr($r3['res'], 0, 150) . "\n";
}
