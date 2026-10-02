<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Find a test user or demo user
$stmt = db()->query("SELECT id, email FROM users ORDER BY id ASC LIMIT 1");
$user = $stmt->fetch();
if (!$user) {
    echo "No user found!\n";
    exit(1);
}

$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['csrf_token'] = 'test_token_123';

$data = [
    'symbol' => 'BTCUSD',
    'direction' => 'up',
    'amount' => 10,
    'duration' => 60,
    'account' => 'demo',
    'requestId' => 'TEST_' . time(),
    '_csrf' => 'test_token_123',
];

echo "Testing place_trade for user ID {$user['id']} ({$user['email']})...\n";

try {
    $trade = place_trade(
        (int)$user['id'],
        $data['symbol'],
        $data['direction'],
        (float)$data['amount'],
        (int)$data['duration'],
        $data['account'],
        $data['requestId']
    );
    echo "SUCCESS! Placed trade payload:\n";
    echo json_encode($trade, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
