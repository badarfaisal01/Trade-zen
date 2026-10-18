<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

// Fetch real market price from configured API
$symbol = trim((string)($_GET['symbol'] ?? ''));
$action = trim((string)($_GET['action'] ?? 'price'));

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($symbol)) {
        throw new InvalidArgumentException('Symbol parameter required');
    }

    if ($action === 'price') {
        // Return current price
        $price = fetch_market_price($symbol);
        echo json_encode([
            'ok' => true,
            'symbol' => $symbol,
            'price' => $price,
            'timestamp' => time(),
            'source' => 'twelvedata'
        ], JSON_UNESCAPED_SLASHES);
    } elseif ($action === 'test') {
        // Test API connection
        $testResults = test_market_api_connection();
        echo json_encode([
            'ok' => true,
            'message' => 'API test completed',
            'results' => $testResults
        ], JSON_UNESCAPED_SLASHES);
    } else {
        throw new InvalidArgumentException('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);
}
