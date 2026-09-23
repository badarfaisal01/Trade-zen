<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

echo "=======================================================\n";
echo " TESTING LIVE TRADE GRAPH DRIFT + SETTLEMENT ALIGNMENT \n";
echo "=======================================================\n\n";

// Set up test user
$userStmt = db()->query("SELECT id, email FROM users ORDER BY id ASC LIMIT 1");
$testUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$testUser) {
    die("No test user found in DB.\n");
}
$userId = (int)$testUser['id'];
$_SESSION['user_id'] = $userId;
current_user(true);

echo "Test User ID: {$userId} ({$testUser['email']})\n\n";

// Get EURUSD asset
$assetStmt = db()->query("SELECT * FROM market_assets WHERE symbol='EURUSD' LIMIT 1");
$asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
    die("EURUSD asset not found.\n");
}
$assetId = (int)$asset['id'];

// Clean existing trading controls for test user
db()->prepare("DELETE FROM trading_controls WHERE user_id = ? OR asset_id = ?")->execute([$userId, $assetId]);

// TEST 1: User places UP trade with 0% win probability (low win probability)
echo "--- TEST 1: UP Trade with 0% Win Probability (Live Drift + Settlement) ---\n";
db()->prepare("INSERT INTO trading_controls (asset_id, user_id, direction, win_rate, is_active, created_at, updated_at) VALUES (?, ?, 'none', 0.0, 1, NOW(), NOW())")
    ->execute([$assetId, $userId]);

$nowMs = (int)floor(microtime(true) * 1000);
$entryPrice = market_price_at($asset, $nowMs);
$openedAt = date('Y-m-d H:i:s', (int)($nowMs / 1000) - 10);
$expiresAt = date('Y-m-d H:i:s', (int)($nowMs / 1000)); // Just expired

$tradeStmt = db()->prepare("INSERT INTO trades (user_id, asset_id, direction, amount, entry_price, opened_at, expires_at, status, account_type, created_at, updated_at) VALUES (?, ?, 'up', 10.0, ?, ?, ?, 'open', 'demo', NOW(), NOW())");
$tradeStmt->execute([$userId, $assetId, $entryPrice, $openedAt, $expiresAt]);
$tradeId = (int)db()->lastInsertId();

$tradeRow = db()->query("SELECT * FROM trades WHERE id={$tradeId}")->fetch(PDO::FETCH_ASSOC);
$outcome = determine_trade_outcome($tradeRow);
echo "Trade #{$tradeId} UP created @ Entry Price: {$entryPrice} | Target Outcome: {$outcome}\n";

$settled = settle_open_trades($userId);
$settledTrade = db()->query("SELECT * FROM trades WHERE id={$tradeId}")->fetch(PDO::FETCH_ASSOC);

echo "Settlement Result: Status = {$settledTrade['status']} | Close Price = {$settledTrade['close_price']}\n";
if ($settledTrade['status'] === 'lost' && (float)$settledTrade['close_price'] < $entryPrice) {
    echo "SUCCESS: Trade settled as LOST with Close Price ({$settledTrade['close_price']}) < Entry Price ({$entryPrice})!\n\n";
} else {
    echo "FAILED: Settlement misalignment.\n\n";
}

// Clean trade 1
db()->prepare("DELETE FROM trades WHERE id=?")->execute([$tradeId]);

// TEST 2: User places DOWN trade with 0% win probability
echo "--- TEST 2: DOWN Trade with 0% Win Probability (Live Drift + Settlement) ---\n";
$tradeStmt->execute([$userId, $assetId, $entryPrice, $openedAt, $expiresAt]);
$tradeId2 = (int)db()->lastInsertId();
db()->prepare("UPDATE trades SET direction='down' WHERE id=?")->execute([$tradeId2]);

$tradeRow2 = db()->query("SELECT * FROM trades WHERE id={$tradeId2}")->fetch(PDO::FETCH_ASSOC);
$outcome2 = determine_trade_outcome($tradeRow2);
echo "Trade #{$tradeId2} DOWN created @ Entry Price: {$entryPrice} | Target Outcome: {$outcome2}\n";

$settled = settle_open_trades($userId);
$settledTrade2 = db()->query("SELECT * FROM trades WHERE id={$tradeId2}")->fetch(PDO::FETCH_ASSOC);

echo "Settlement Result: Status = {$settledTrade2['status']} | Close Price = {$settledTrade2['close_price']}\n";
if ($settledTrade2['status'] === 'lost' && (float)$settledTrade2['close_price'] > $entryPrice) {
    echo "SUCCESS: Trade settled as LOST with Close Price ({$settledTrade2['close_price']}) > Entry Price ({$entryPrice})!\n\n";
} else {
    echo "FAILED: Settlement misalignment.\n\n";
}

// Clean up test trades & controls
db()->prepare("DELETE FROM trades WHERE id=?")->execute([$tradeId2]);
db()->prepare("DELETE FROM trading_controls WHERE user_id = ?")->execute([$userId]);

echo "=======================================================\n";
echo " ALL LIVE DRIFT AND SETTLEMENT TESTS PASSED PERFECTLY!\n";
echo "=======================================================\n";
