<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

echo "=======================================================\n";
echo " VERIFYING MULTI-TRADE PRECEDENCE (2-MIN vs 8-MIN)     \n";
echo "=======================================================\n\n";

$userStmt = db()->query("SELECT id, email FROM users ORDER BY id ASC LIMIT 1");
$testUser = $userStmt->fetch(PDO::FETCH_ASSOC);
$userId = (int)$testUser['id'];

$asset = market_asset('EURUSD', false);
$assetId = (int)$asset['id'];

db()->prepare("DELETE FROM trading_controls WHERE user_id = ? OR asset_id = ?")->execute([$userId, $assetId]);
db()->prepare("DELETE FROM trades WHERE user_id = ? AND status='open'")->execute([$userId]);

// Set trade control: 0% win rate (MUST LOSE)
db()->prepare("INSERT INTO trading_controls (asset_id, user_id, direction, win_rate, is_active, created_at, updated_at) VALUES (?, ?, 'none', 0.0, 1, NOW(), NOW())")
    ->execute([$assetId, $userId]);

$nowSec = time();
$entryPrice = 1.08500;

// Trade 1: 2-minute trade, expiring in 10s (in final steering window)
$t1Opened = date('Y-m-d H:i:s', $nowSec - 110);
$t1Expires = date('Y-m-d H:i:s', $nowSec + 10);
$t1Entry = 1.08500; // UP trade

// Trade 2: 8-minute trade, expiring in 360s (in early phase)
$t2Opened = date('Y-m-d H:i:s', $nowSec - 120);
$t2Expires = date('Y-m-d H:i:s', $nowSec + 360);
$t2Entry = 1.08700; // DOWN trade

$tradeStmt = db()->prepare("INSERT INTO trades (public_id, user_id, asset_id, direction, amount, payout_percent, entry_price, opened_at, expires_at, status, account_type, created_at, updated_at) VALUES (?, ?, ?, ?, 10.0, 80.0, ?, ?, ?, 'open', 'demo', NOW(), NOW())");
$tradeStmt->execute(['T_MULTI_1', $userId, $assetId, 'up', $t1Entry, $t1Opened, $t1Expires]);
$t1Id = (int)db()->lastInsertId();

$tradeStmt->execute(['T_MULTI_2', $userId, $assetId, 'down', $t2Entry, $t2Opened, $t2Expires]);
$t2Id = (int)db()->lastInsertId();

echo "Two concurrent trades open:\n";
echo "  Trade #1 (2-min UP trade, Entry: {$t1Entry}, Expires in 10s -> SHOULD STEER NOW)\n";
echo "  Trade #2 (8-min DOWN trade, Entry: {$t2Entry}, Expires in 360s -> SHOULD NOT INTERFERE)\n\n";

$nowMs = $nowSec * 1000;
$t1ExpiryMs = (int)strtotime($t1Expires) * 1000;
$controlledPriceExpiry = market_price_at($asset, $t1ExpiryMs, false, $userId);

echo "Controlled Price at Trade #1 Expiry (0s remaining): {$controlledPriceExpiry}\n";

// Trade 1 is UP with 0% win rate => steered below Trade 1 entry (1.08500)
if ($controlledPriceExpiry < $t1Entry) {
    echo "SUCCESS: Engine correctly prioritized Trade #1 (2-min closing trade) and secured Loss below entry!\n";
} else {
    echo "FAILED: Trade #2 interfered with Trade #1!\n";
}

// Clean up
db()->prepare("DELETE FROM trades WHERE id IN (?, ?)")->execute([$t1Id, $t2Id]);
db()->prepare("DELETE FROM trading_controls WHERE user_id=?")->execute([$userId]);
