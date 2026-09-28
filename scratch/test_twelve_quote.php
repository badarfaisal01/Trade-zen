<?php
require 'app/bootstrap.php';
$apiKey = (string)env('TWELVEDATA_API_KEY', '');
$url = "https://api.twelvedata.com/quote?symbol=" . urlencode('USD/CAD') . "&apikey=" . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $code . "\n";
echo "Response: " . $res . "\n";
