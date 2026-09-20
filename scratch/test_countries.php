<?php
require_once __DIR__ . '/../app/helpers.php';

$all = app_all_countries();
echo "Total countries in database: " . count($all) . "\n";
echo "Pakistan (PK): " . (isset($all['PK']) ? $all['PK'] : 'NOT FOUND') . "\n";
echo "Germany (DE): " . (isset($all['DE']) ? $all['DE'] : 'NOT FOUND') . "\n";
echo "United States (US): " . (isset($all['US']) ? $all['US'] : 'NOT FOUND') . "\n";
echo "United Kingdom (GB): " . (isset($all['GB']) ? $all['GB'] : 'NOT FOUND') . "\n";

$options = app_country_options('PK');
if (strpos($options, 'value="PK" selected>Pakistan') !== false) {
    echo "SUCCESS: Pakistan is included and selected by default!\n";
} else {
    echo "ERROR: Pakistan option not generated correctly.\n";
}
