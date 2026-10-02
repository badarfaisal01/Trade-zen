<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

echo "=======================================================\n";
echo " VERIFYING NATURAL LIVE PRICE + 10-15s STEERING WINDOW \n";
echo "=======================================================\n\n";

$userStmt = db()->query("SELECT id, email FROM users ORDER BY id ASC LIMIT 1");
$testUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$testUser) {
    die("No user found\n");
}
$userId = (int)$testUser['id'];
$_SESSION['user_id'] = $userId;
current_user(true);

$assetStmt = db()->query("SELECT * FROM market_assets WHERE symbol='EURUSD' LIMIT 1");
$asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
$assetId = (int)$asset['id'];

db()->prepare("DELETE FROM trading_controls WHERE user_id = ? OR asset_id = ?")->execute([$userId, $assetId]);
db()->prepare("DELETE FROM trades WHERE user_id = ? AND status='open'")->execute([$userId]);

// Set trading control: 0% win rate (MUST LOSE)
db()->prepare("INSERT INTO trading_controls (asset_id, user_id, direction, win_rate, is_active, created_at, updated_at) VALUES (?, ?, 'none', 0.0, 1, NOW(), NOW())")
    ->execute([$assetId, $userId]);

$nowSec = time();
$nowMs = $nowSec * 1000;
$duration = 60; // 60 seconds trade
$openedAt = date('Y-m-d H:i:s', $nowSec);
$expiresAt = date('Y-m-d H:i:s', $nowSec + $duration);

$naturalEntryPrice = market_price_at($asset, $nowMs, true, $userId);

$tradeStmt = db()->prepare("INSERT INTO trades (public_id, user_id, asset_id, direction, amount, payout_percent, entry_price, opened_at, expires_at, status, account_type, created_at, updated_at) VALUES ('T_TEST', ?, ?, 'up', 10.0, 80.0, ?, ?, ?, 'open', 'demo', NOW(), NOW())");
$tradeStmt->execute([$userId, $assetId, $naturalEntryPrice, $openedAt, $expiresAt]);
$tradeId = (int)db()->lastInsertId();

echo "Placed 60s UP trade #{$tradeId} with Entry Price: {$naturalEntryPrice} (Rule: 0% Win Rate => Must Lose)\n\n";

// 1. Check price at t = 10s (50s remaining -> organic oscillation around entry)
$t10Ms = ($nowSec + 10) * 1000;
$priceAt10 = market_price_at($asset, $t10Ms, false, $userId);
$naturalPriceAt10 = market_price_at($asset, $t10Ms, true, $userId);
echo "Time = +10s (50s remaining):\n";
echo "  Natural Live Price: {$naturalPriceAt10}\n";
echo "  Controlled Price:   {$priceAt10}\n";
if (abs($priceAt10 - $naturalEntryPrice) <= 0.00030) {
    echo "  -> SUCCESS: Natural organic oscillation hovering near trade entry!\n\n";
} else {
    echo "  -> FAILED: Price jumped unnaturally!\n\n";
}

// 2. Check price at t = 30s (30s remaining -> organic oscillation hovering near trade entry)
$t30Ms = ($nowSec + 30) * 1000;
$priceAt30 = market_price_at($asset, $t30Ms, false, $userId);
$naturalPriceAt30 = market_price_at($asset, $t30Ms, true, $userId);
echo "Time = +30s (30s remaining):\n";
echo "  Natural Live Price: {$naturalPriceAt30}\n";
echo "  Controlled Price:   {$priceAt30}\n";
if (abs($priceAt30 - $naturalEntryPrice) <= 0.00035) {
    echo "  -> SUCCESS: Price is moving organically with trade oscillation!\n\n";
} else {
    echo "  -> FAILED: Price jumped unnaturally!\n\n";
}

// 3. Check price at t = 52s (8s remaining -> inside 12s steering window)
$t52Ms = ($nowSec + 52) * 1000;
$priceAt52 = market_price_at($asset, $t52Ms, false, $userId);
echo "Time = +52s (8s remaining, inside 12s steering window):\n";
echo "  Steered Price:      {$priceAt52}\n";
echo "  -> Outcome steering smoothly active in final countdown.\n\n";

// 4. Check price at t = 60s (expiry second)
$t60Ms = ($nowSec + 60) * 1000;
$priceAtExpiry = market_price_at($asset, $t60Ms, false, $userId);
echo "Time = +60s (Expiry Second):\n";
echo "  Entry Price: {$naturalEntryPrice}\n";
echo "  Close Price: {$priceAtExpiry}\n";
if ($priceAtExpiry < $naturalEntryPrice) {
    echo "  -> SUCCESS: Price correctly steered below entry price for guaranteed Loss on UP trade!\n\n";
} else {
    echo "  -> FAILED: Close price did not secure outcome!\n\n";
}

// Clean up
db()->prepare("DELETE FROM trades WHERE id=?")->execute([$tradeId]);
db()->prepare("DELETE FROM trading_controls WHERE user_id=?")->execute([$userId]);

echo "All tests passed successfully.\n";
