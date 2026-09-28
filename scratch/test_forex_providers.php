<?php
require __DIR__ . '/../app/bootstrap.php';

$twelveKey = env('TWELVEDATA_API_KEY');
$alphaKey = env('ALPHAVANTAGE_API_KEY');

echo "--- 1. Testing TwelveData time_series for EUR/USD ---\n";
$tdUrl = "https://api.twelvedata.com/time_series?symbol=EUR/USD&interval=1min&outputsize=10&apikey=" . $twelveKey;
$tdRes = @file_get_contents($tdUrl);
echo "TwelveData response: " . substr((string)$tdRes, 0, 500) . "\n";

echo "\n--- 2. Testing AlphaVantage FX_INTRADAY for EURUSD ---\n";
$avUrl = "https://www.alphavantage.co/query?function=FX_INTRADAY&from_symbol=EUR&to_symbol=USD&interval=1min&apikey=" . $alphaKey;
$avRes = @file_get_contents($avUrl);
echo "AlphaVantage response: " . substr((string)$avRes, 0, 500) . "\n";

echo "\n--- 3. Testing AlphaVantage CURRENCY_EXCHANGE_RATE for EUR -> USD ---\n";
$avRateUrl = "https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency=EUR&to_currency=USD&apikey=" . $alphaKey;
$avRateRes = @file_get_contents($avRateUrl);
echo "AlphaVantage rate response: " . substr((string)$avRateRes, 0, 500) . "\n";
