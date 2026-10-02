<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/trading.php';
require_once __DIR__ . '/../app/market.php';

echo "=======================================================\n";
echo " VERIFYING TARGET CLOSE PRICE & SETTLEMENT ALIGNMENT   \n";
echo "=======================================================\n\n";

$userId = 1;
$asset = market_asset('BTCUSD', false);
if (!$asset) {
    $asset = market_asset('EURUSD', false);
}
$assetId = (int)$asset['id'];
$symbol = (string)$asset['symbol'];
$decimals = (int)$asset['decimal_places'];

// Set 0% win rate control
db()->prepare("DELETE FROM trading_controls WHERE user_id = ?")->execute([$userId]);
db()->prepare("INSERT INTO trading_controls (asset_id, user_id, direction, win_rate, is_active, created_at, updated_at) VALUES (?, ?, 'none', 0.0, 1, NOW(), NOW())")->execute([$assetId, $userId]);

// Clean any open trades
db()->prepare("DELETE FROM trades WHERE user_id = ? AND status='open'")->execute([$userId]);

// Place trade for 60 seconds
$trade = place_trade($userId, $symbol, 'up', 10.0, 60, 'demo');
$publicId = $trade['id'];
$entryPrice = (float)$trade['entryPrice'];
$targetClosePrice = (float)$trade['targetClosePrice'];
$expiresAt = (int)$trade['expiresAt'];

echo "Trade Placed: ID={$publicId} | Direction=UP | Entry Price={$entryPrice}\n";
echo "Target Close Price from payload: {$targetClosePrice}\n";

// Verify user_trade_state also returns targetClosePrice
$state = user_trade_state($userId, 10);
$openTrade = null;
foreach ($state['trades'] as $t) {
    if ($t['id'] === $publicId) {
        $openTrade = $t;
        break;
    }
}
echo "Target Close Price from state.php: " . ($openTrade['targetClosePrice'] ?? 'N/A') . "\n";

// Force expiry timestamp
$expiryDateStr = date('Y-m-d H:i:s', (int)($expiresAt / 1000));
db()->prepare("UPDATE trades SET opened_at = DATE_SUB(NOW(), INTERVAL 65 SECOND), expires_at = DATE_SUB(NOW(), INTERVAL 5 SECOND) WHERE public_id = ?")->execute([$publicId]);

// Settle
$settledCount = settle_open_trades($userId);
$settledRow = db()->query("SELECT * FROM trades WHERE public_id = '{$publicId}'")->fetch(PDO::FETCH_ASSOC);

$actualClosePrice = (float)$settledRow['close_price'];
$status = $settledRow['status'];

echo "\nSettled Result: Status={$status} | DB close_price={$actualClosePrice}\n";

if ($status === 'lost' && abs($actualClosePrice - $targetClosePrice) < 0.001) {
    echo "\nSUCCESS: targetClosePrice ({$targetClosePrice}) EXACTLY MATCHES settled close_price ({$actualClosePrice})!\n";
} else {
    echo "\nMISMATCH or UNEXPECTED: target={$targetClosePrice} vs settled={$actualClosePrice} status={$status}\n";
}

// Clean up
db()->prepare("DELETE FROM trades WHERE public_id = ?")->execute([$publicId]);
db()->prepare("DELETE FROM trading_controls WHERE user_id = ?")->execute([$userId]);
