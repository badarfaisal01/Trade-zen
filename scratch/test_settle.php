<?php
require __DIR__ . '/../app/bootstrap.php';
$settled = settle_open_trades(1);
echo "Settled count: " . $settled . "\n";
$trades = db()->query("SELECT t.id, t.public_id, a.symbol, t.direction, t.entry_price, t.close_price, t.status, t.payout_amount, t.profit_amount FROM trades t JOIN market_assets a ON a.id=t.asset_id WHERE t.user_id=1 ORDER BY t.id DESC LIMIT 11")->fetchAll();
foreach ($trades as $tr) {
    echo "Trade #{$tr['id']} ({$tr['symbol']}): Status={$tr['status']}, Entry={$tr['entry_price']}, Close={$tr['close_price']}, Profit={$tr['profit_amount']}\n";
}
