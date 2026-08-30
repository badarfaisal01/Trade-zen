<?php
require 'C:\xampp\htdocs\app\bootstrap.php';
foreach (['TWELVEDATA_API_KEY','METALS_API_KEY','MARKET_API_PROVIDER'] as $key) {
    $value = getenv($key) ?: '';
    echo $key . '=' . ($value !== '' ? 'SET' : 'EMPTY') . PHP_EOL;
}

try {
    $price = fetch_twelvedata_price('EURUSD');
    echo 'TWELVE_EURUSD=' . $price . PHP_EOL;
} catch (Throwable $e) {
    echo 'TWELVE_ERR=' . $e->getMessage() . PHP_EOL;
}

try {
    $price = fetch_metals_api_price('XAU', 'USD');
    echo 'METALS_XAU=' . $price . PHP_EOL;
} catch (Throwable $e) {
    echo 'METALS_ERR=' . $e->getMessage() . PHP_EOL;
}
