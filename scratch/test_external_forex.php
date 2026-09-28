<?php
require __DIR__ . '/../app/bootstrap.php';

$finnhubKey = env('FINNHUB_API_KEY');

echo "--- 1. Testing Finnhub Forex quote for OANDA:EUR_USD ---\n";
$fhUrl = "https://finnhub.io/api/v1/quote?symbol=OANDA:EUR_USD&token=" . $finnhubKey;
$ch = curl_init($fhUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$fhRes = curl_exec($ch);
curl_close($ch);
echo "Finnhub OANDA:EUR_USD: " . $fhRes . "\n";

echo "--- 2. Testing Finnhub Forex candle for OANDA:EUR_USD ---\n";
$now = time();
$fhCandleUrl = "https://finnhub.io/api/v1/forex/candle?symbol=OANDA:EUR_USD&resolution=1&from=" . ($now - 3600) . "&to=" . $now . "&token=" . $finnhubKey;
$ch = curl_init($fhCandleUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$fhCRes = curl_exec($ch);
curl_close($ch);
echo "Finnhub Forex candles: " . substr((string)$fhCRes, 0, 300) . "\n";

echo "--- 3. Testing Yahoo Finance chart for EURUSD=X ---\n";
$yfUrl = "https://query1.finance.yahoo.com/v8/finance/chart/EURUSD=X?interval=1m&range=1h";
$ch = curl_init($yfUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$yfRes = curl_exec($ch);
curl_close($ch);
echo "Yahoo Finance EURUSD=X: " . substr((string)$yfRes, 0, 300) . "\n";
