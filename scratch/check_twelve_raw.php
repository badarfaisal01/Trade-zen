<?php
require __DIR__ . '/../app/bootstrap.php';

$key = env('TWELVEDATA_API_KEY');
$symbols = ['USD/CAD', 'EUR/USD', 'USD/JPY', 'GBP/USD', 'AUD/USD'];

foreach ($symbols as $sym) {
    echo "======================= $sym =======================\n";
    
    // 1. Direct price endpoint
    $priceUrl = "https://api.twelvedata.com/price?symbol=" . urlencode($sym) . "&apikey=" . $key;
    $rawPrice = @file_get_contents($priceUrl);
    echo "Raw /price response: " . $rawPrice . "\n";
    
    // 2. Direct quote endpoint
    $quoteUrl = "https://api.twelvedata.com/quote?symbol=" . urlencode($sym) . "&apikey=" . $key;
    $rawQuote = @file_get_contents($quoteUrl);
    $quoteJson = json_decode($rawQuote, true);
    echo "Raw /quote close/price: " . ($quoteJson['close'] ?? $quoteJson['price'] ?? 'null') . "\n";
    
    // 3. Direct time_series endpoint
    $tsUrl = "https://api.twelvedata.com/time_series?symbol=" . urlencode($sym) . "&interval=1min&outputsize=5&apikey=" . $key;
    $rawTs = @file_get_contents($tsUrl);
    $tsJson = json_decode($rawTs, true);
    if (!empty($tsJson['values'])) {
        echo "Raw /time_series values[0]: datetime=" . $tsJson['values'][0]['datetime'] . ", close=" . $tsJson['values'][0]['close'] . "\n";
    } else {
        echo "Raw /time_series: " . $rawTs . "\n";
    }
}
