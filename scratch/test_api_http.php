<?php
$url = 'http://localhost/en/api/market.php?symbol=XAUUSD&interval=180&count=800&history=1';
$opts = [
    'http' => [
        'timeout' => 5
    ]
];
$context = stream_context_create($opts);
$start = microtime(true);
$res = @file_get_contents($url, false, $context);
$elapsed = round((microtime(true) - $start) * 1000, 1);

echo "HTTP Request to {$url}\n";
echo "Elapsed: {$elapsed}ms\n";
if ($res === false) {
    echo "ERROR: Failed to fetch\n";
    $headers = $http_response_header ?? [];
    print_r($headers);
} else {
    echo "Response length: " . strlen($res) . "\n";
    $json = json_decode($res, true);
    if ($json) {
        echo "Valid JSON: status=" . ($json['status'] ?? 'unknown') . " | candles count=" . count($json['candles'] ?? []) . " | asset=" . ($json['asset']['symbol'] ?? 'none') . "\n";
    } else {
        echo "Invalid JSON response:\n" . substr($res, 0, 500) . "\n";
    }
}
