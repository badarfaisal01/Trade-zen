<?php
$key = 'dbea1ffa9e754a76b52a90728fed90b3';
echo "--- PRICE ENDPOINT ---\n";
echo file_get_contents("https://api.twelvedata.com/price?symbol=USD/CAD&apikey=$key") . "\n\n";

echo "--- QUOTE ENDPOINT ---\n";
echo file_get_contents("https://api.twelvedata.com/quote?symbol=USD/CAD&apikey=$key") . "\n\n";
