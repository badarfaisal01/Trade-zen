<?php
require __DIR__ . '/../app/bootstrap.php';

function curlGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'res' => $res];
}

$alphaKey = env('ALPHAVANTAGE_API_KEY');
$finnhubKey = env('FINNHUB_API_KEY');

echo "=== Testing AlphaVantage for USDCAD ===\n";
$avUrl = "https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency=USD&to_currency=CAD&apikey=" . $alphaKey;
$rAv = curlGet($avUrl);
echo "AV HTTP {$rAv['code']}: " . substr($rAv['res'], 0, 300) . "\n\n";

echo "=== Testing Finnhub for USDCAD ===\n";
$fhUrl = "https://finnhub.io/api/v1/forex/candle?symbol=OANDA:USD_CAD&resolution=1&count=5&token=" . $finnhubKey;
$rFh = curlGet($fhUrl);
echo "Finnhub HTTP {$rFh['code']}: " . substr($rFh['res'], 0, 300) . "\n\n";

echo "=== Testing Binance USDT pairs for EUR, GBP, AUD ===\n";
foreach (['EURUSDT', 'GBPUSDT', 'AUDUSDT'] as $bSym) {
    $bUrl = "https://api.binance.com/api/v3/ticker/price?symbol=" . $bSym;
    $rB = curlGet($bUrl);
    echo "$bSym: " . $rB['res'] . "\n";
}
