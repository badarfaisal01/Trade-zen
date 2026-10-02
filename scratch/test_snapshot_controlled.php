<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

echo "=======================================================\n";
echo " VERIFYING MARKET_SNAPSHOT RETURNING CONTROLLED PRICE  \n";
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
$nowMs = $nowSec * 1000;
// Trade opened 55s ago, expires in 5s (in final steering window)
$openedAt = date('Y-m-d H:i:s', $nowSec - 55);
$expiresAt = date('Y-m-d H:i:s', $nowSec + 5);
$entryPrice = market_price_at($asset, ($nowSec - 55) * 1000, true, $userId);

$tradeStmt = db()->prepare("INSERT INTO trades (public_id, user_id, asset_id, direction, amount, payout_percent, entry_price, opened_at, expires_at, status, account_type, created_at, updated_at) VALUES ('T_SNAP_TEST', ?, ?, 'up', 10.0, 80.0, ?, ?, ?, 'open', 'demo', NOW(), NOW())");
$tradeStmt->execute([$userId, $assetId, $entryPrice, $openedAt, $expiresAt]);
$tradeId = (int)db()->lastInsertId();

$snap = market_snapshot('EURUSD', 5, 10, true, null, $userId);
$assetPrice = (float)($snap['asset']['price'] ?? 0);
$candles = $snap['candles'] ?? [];
$lastCandleClose = !empty($candles) ? (float)($candles[count($candles) - 1]['close'] ?? 0) : 0;

echo "Entry Price: {$entryPrice} (UP trade, 0% win rate -> target below entry)\n";
echo "Snapshot Asset Price:        {$assetPrice}\n";
echo "Snapshot Last Candle Close:  {$lastCandleClose}\n\n";

if ($assetPrice < $entryPrice && $lastCandleClose < $entryPrice) {
    echo "SUCCESS: market_snapshot() correctly returned the steered price in both asset.price and candles!\n";
} else {
    echo "FAILED: market_snapshot() was overwritten by raw market price!\n";
}

// Clean up
db()->prepare("DELETE FROM trades WHERE id=?")->execute([$tradeId]);
db()->prepare("DELETE FROM trading_controls WHERE user_id=?")->execute([$userId]);
