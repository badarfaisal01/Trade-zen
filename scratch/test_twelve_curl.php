<?php
require __DIR__ . '/../app/bootstrap.php';

$key = env('TWELVEDATA_API_KEY');
$url = "https://api.twelvedata.com/time_series?symbol=EUR/USD&interval=1min&outputsize=10&apikey=" . $key;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

echo "TwelveData cURL result:\n";
echo "Error: $err\n";
echo "Response: $res\n";
