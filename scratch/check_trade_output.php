<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../app/bootstrap.php';
$initialOutput = ob_get_clean();

if ($initialOutput !== '') {
    echo "WARNING: bootstrap.php produced output:\n";
    var_dump($initialOutput);
} else {
    echo "bootstrap.php is clean (no output).\n";
}

$user = db()->query("SELECT id, email FROM users ORDER BY id ASC LIMIT 1")->fetch();
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['csrf_token'] = 'test_token';

ob_start();
// Simulate what en/api/trade.php runs
$requestedAccount = 'demo';
set_trade_account_type($requestedAccount, (int)$user['id']);
$trade = place_trade(
    (int)$user['id'],
    'EURUSD',
    'up',
    10.0,
    60,
    $requestedAccount,
    'test-req-' . time()
);
$tradeOutput = ob_get_clean();

if ($tradeOutput !== '') {
    echo "WARNING: place_trade produced output:\n";
    var_dump($tradeOutput);
} else {
    echo "place_trade is clean (no output).\n";
}

$payload = ['ok' => true, 'message' => 'Trade opened.', 'data' => $trade];
$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
$decoded = json_decode($encoded, true);

if ($decoded === null) {
    echo "ERROR: json_encode failed: " . json_last_error_msg() . "\n";
} else {
    echo "JSON encoding is valid. Length: " . strlen($encoded) . " bytes\n";
}
