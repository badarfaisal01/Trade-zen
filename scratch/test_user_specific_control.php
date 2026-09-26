<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

echo "========================================================\n";
echo "       USER-SPECIFIC TRADE CONTROL END-TO-END TEST      \n";
echo "========================================================\n\n";

$asset = market_asset('BTCUSD', false);
if (!$asset) {
    echo "ERROR: Asset BTCUSD not found.\n";
    exit(1);
}

// Fetch two real user IDs from users table
$users = db()->query("SELECT id FROM users WHERE role='user' ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($users) < 2) {
    echo "Creating temporary test users...\n";
    db()->exec("INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at) VALUES ('Test User A', 'test_user_a_spec@example.com', 'hash', 'user', 'active', NOW(), NOW())");
    db()->exec("INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at) VALUES ('Test User B', 'test_user_b_spec@example.com', 'hash', 'user', 'active', NOW(), NOW())");
    $users = db()->query("SELECT id FROM users WHERE role='user' ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
}

$user1Id = (int)$users[0];
$user2Id = (int)$users[1];

echo "Testing with User 1 (ID {$user1Id}) and User 2 (ID {$user2Id})...\n\n";

$assetId = (int)$asset['id'];
$nowMs = (int)floor(microtime(true) * 1000);
$openTime = date('Y-m-d H:i:s', (int)floor($nowMs / 1000) - 10);
$expiryTime = date('Y-m-d H:i:s', (int)floor($nowMs / 1000) + 50);

// Clean previous test control & test trades for these IDs
db()->exec("DELETE FROM trades WHERE id = 999901");
db()->exec("DELETE FROM trading_controls WHERE user_id IN ($user1Id, $user2Id)");

// 1. Unbiased real price test (guest / user with no trade)
$rawPriceGuest = market_price_at($asset, $nowMs, false, null);
$rawPriceUser2 = market_price_at($asset, $nowMs, false, $user2Id);
echo "1. Uncontrolled price (Guest): {$rawPriceGuest}\n";
echo "   Uncontrolled price (User 2 - No Trade): {$rawPriceUser2}\n";

if ($rawPriceGuest != $rawPriceUser2) {
    echo "FAIL: Uncontrolled price differed between guest and User 2!\n";
    exit(1);
}
echo "   -> PASSED: Guest and User 2 receive identical unbiased market price.\n\n";

// 2. Open an active controlled trade for User 1
db()->prepare("INSERT INTO trades (id, user_id, asset_id, account_type, direction, amount, entry_price, payout_percent, status, opened_at, expires_at, created_at, updated_at) VALUES (999901, ?, ?, 'live', 'up', 100, 60000.00, 85, 'open', ?, ?, NOW(), NOW())")
    ->execute([$user1Id, $assetId, $openTime, $expiryTime]);

// Insert admin control forcing WIN for User 1
db()->prepare("INSERT INTO trading_controls (asset_id, user_id, direction, win_rate, is_active, created_at, updated_at) VALUES (?, ?, 'up', 100.0, 1, NOW(), NOW())")
    ->execute([$assetId, $user1Id]);

// 3. Test price for User 1 (controlled) vs User 2 (uncontrolled)
$priceUser1 = market_price_at($asset, $nowMs, false, $user1Id);
$priceUser2After = market_price_at($asset, $nowMs, false, $user2Id);
$priceGuestAfter = market_price_at($asset, $nowMs, false, null);

echo "2. Controlled trade active for User 1 (ID {$user1Id}):\n";
echo "   User 1 Price (Controlled): {$priceUser1}\n";
echo "   User 2 Price (No Trade):   {$priceUser2After}\n";
echo "   Guest Price (No Trade):    {$priceGuestAfter}\n";

if ($priceUser1 == $priceUser2After) {
    echo "FAIL: User 1 price was NOT controlled or User 2 price was polluted!\n";
    exit(1);
}

if ($priceUser2After != $priceGuestAfter) {
    echo "FAIL: User 2 price was polluted by User 1's active trade!\n";
    exit(1);
}
echo "   -> PASSED: User 1 receives controlled price, User 2 & Guest receive clean real market price!\n\n";

// 4. Test Candles for User 1 vs User 2
$candlesUser1 = market_candles($asset, 5, 10, $nowMs, $user1Id);
$candlesUser2 = market_candles($asset, 5, 10, $nowMs, $user2Id);

$lastCandle1Close = $candlesUser1[count($candlesUser1) - 1]['close'];
$lastCandle2Close = $candlesUser2[count($candlesUser2) - 1]['close'];

echo "3. Candle rendering check:\n";
echo "   User 1 Latest Candle Close: {$lastCandle1Close}\n";
echo "   User 2 Latest Candle Close: {$lastCandle2Close}\n";

if ($lastCandle1Close == $lastCandle2Close) {
    echo "FAIL: Candles for User 1 and User 2 were identical despite User 1's trade control!\n";
    exit(1);
}
echo "   -> PASSED: User 1 candles are user-specifically steered; User 2 candles remain unbiased real market candles!\n\n";

// Clean test data
db()->exec("DELETE FROM trades WHERE id = 999901");
db()->exec("DELETE FROM trading_controls WHERE user_id IN ($user1Id, $user2Id)");

echo "========================================================\n";
echo "  ALL USER-SPECIFIC TRADE CONTROL VERIFICATIONS PASSED!  \n";
echo "========================================================\n";
