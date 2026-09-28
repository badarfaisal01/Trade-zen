<?php
require_once __DIR__ . '/../app/env.php';
$key = env('TWELVEDATA_API_KEY');
$url = "https://api.twelvedata.com/time_series?symbol=USD/JPY&interval=1min&outputsize=10&apikey=" . $key;
$res = file_get_contents($url);
echo "RESPONSE FOR USD/JPY:\n" . $res . "\n";
