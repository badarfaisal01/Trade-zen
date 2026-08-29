<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
settle_open_trades((int)$user['id']);
$accountType = trade_account_type();

$summaryStmt = db()->prepare("SELECT COUNT(*) total,COALESCE(SUM(status='won'),0) wins,COALESCE(SUM(status='lost'),0) losses,COALESCE(SUM(status='draw'),0) draws,COALESCE(SUM(amount),0) volume,COALESCE(SUM(profit_loss),0) net FROM trades WHERE user_id=? AND account_type=? AND status<>'open'");
$summaryStmt->execute([(int)$user['id'], $accountType]);
$summary = $summaryStmt->fetch();
$summary['win_rate'] = (int)$summary['total'] > 0 ? round(((int)$summary['wins'] / (int)$summary['total']) * 100, 1) : 0;

$dailyStmt = db()->prepare("SELECT DATE(COALESCE(settled_at,created_at)) day,COUNT(*) trades,COALESCE(SUM(profit_loss),0) net FROM trades WHERE user_id=? AND account_type=? AND status<>'open' AND created_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY DATE(COALESCE(settled_at,created_at)) ORDER BY day ASC");
$dailyStmt->execute([(int)$user['id'], $accountType]);
$dailyRows = $dailyStmt->fetchAll();
$dailyMap = [];
foreach ($dailyRows as $row) { $dailyMap[$row['day']] = $row; }
$daily = [];
$maxDaily = 1.0;
for ($days = 13; $days >= 0; $days--) {
    $date = date('Y-m-d', strtotime('-' . $days . ' days'));
    $row = $dailyMap[$date] ?? ['day' => $date, 'trades' => 0, 'net' => 0];
    $maxDaily = max($maxDaily, abs((float)$row['net']));
    $daily[] = $row;
}

$assetStmt = db()->prepare("SELECT a.symbol,a.display_name,COUNT(*) trades,COALESCE(SUM(t.status='won'),0) wins,COALESCE(SUM(t.profit_loss),0) net FROM trades t JOIN market_assets a ON a.id=t.asset_id WHERE t.user_id=? AND t.account_type=? AND t.status<>'open' GROUP BY a.id,a.symbol,a.display_name ORDER BY trades DESC,net DESC LIMIT 12");
$assetStmt->execute([(int)$user['id'], $accountType]);
$assets = $assetStmt->fetchAll();
$recentStmt = db()->prepare("SELECT t.public_id,t.direction,t.amount,t.status,t.profit_loss,t.opened_at,a.display_name FROM trades t JOIN market_assets a ON a.id=t.asset_id WHERE t.user_id=? AND t.account_type=? ORDER BY t.id DESC LIMIT 12");
$recentStmt->execute([(int)$user['id'], $accountType]);
$recent = $recentStmt->fetchAll();

render_document_start('Analytics', 'account-page black qx-account-shell qx-analytics-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('analytics');
render_flashes();
?>
<main class="account-main analytics-page qx-cabinet-page">
    <section class="page-heading qx-page-heading"><div><span class="eyebrow">Performance center · <?= e(ucfirst($accountType)) ?> Account</span><h1>Trading analytics</h1><p>Review server-settled performance by day and market.</p></div><a class="button button--primary" href="/en/trade/">Open trade room</a></section>
    <section class="stat-grid"><article><span>Settled trades</span><strong><?= number_format((int)$summary['total']) ?></strong><small><?= number_format((int)$summary['wins']) ?> won · <?= number_format((int)$summary['losses']) ?> lost</small></article><article><span>Win rate</span><strong><?= e(number_format((float)$summary['win_rate'], 1)) ?>%</strong><small>Draws excluded from wins</small></article><article><span>Trading volume</span><strong><?= e(money($summary['volume'], $user['currency'])) ?></strong><small>Total settled stake</small></article><article><span>Net P/L</span><strong class="<?= (float)$summary['net'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$summary['net'] > 0 ? '+' : '' ?><?= e(money($summary['net'], $user['currency'])) ?></strong><small>All settled results</small></article></section>
    <section class="analytics-grid"><article class="panel analytics-chart-panel"><div class="panel-heading"><div><span class="eyebrow">Last 14 days</span><h2>Daily P/L</h2></div></div><div class="pl-chart" aria-label="Daily profit and loss chart"><?php foreach ($daily as $row): $height = max(3, (abs((float)$row['net']) / $maxDaily) * 100); ?><div class="pl-day" title="<?= e(date('d M', strtotime($row['day']))) ?>: <?= e(money($row['net'], $user['currency'])) ?>"><span class="pl-value <?= (float)$row['net'] >= 0 ? 'positive' : 'negative' ?>" style="height:<?= e(number_format($height, 2, '.', '')) ?>%"></span><small><?= e(date('d', strtotime($row['day']))) ?></small></div><?php endforeach; ?></div></article><article class="panel"><div class="panel-heading"><div><span class="eyebrow">Market breakdown</span><h2>Asset performance</h2></div></div><div class="analytics-asset-list"><?php if (!$assets): ?><div class="empty-state"><?= icon('activity', 26) ?><strong>No settled data yet</strong><span>Complete a trade to populate analytics.</span></div><?php endif; ?><?php foreach ($assets as $asset): ?><div><span class="market-symbol-icon"><?= e(substr($asset['symbol'], 0, 1)) ?></span><span><strong><?= e($asset['display_name']) ?></strong><small><?= (int)$asset['wins'] ?>/<?= (int)$asset['trades'] ?> won</small></span><b class="<?= (float)$asset['net'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$asset['net'] > 0 ? '+' : '' ?><?= e(money($asset['net'], $user['currency'])) ?></b></div><?php endforeach; ?></div></article></section>
    <section class="panel history-panel"><div class="panel-heading"><div><span class="eyebrow">Recent activity</span><h2>Latest trades</h2></div><a href="/en/history/">Full history</a></div><div class="table-scroll"><table><thead><tr><th>Trade</th><th>Market</th><th>Direction</th><th>Amount</th><th>Result</th><th>P/L</th><th>Opened</th></tr></thead><tbody><?php if (!$recent): ?><tr><td colspan="7" class="table-empty">No trade activity yet.</td></tr><?php endif; ?><?php foreach ($recent as $trade): ?><tr><td><code><?= e($trade['public_id']) ?></code></td><td><strong><?= e($trade['display_name']) ?></strong></td><td><span class="direction direction--<?= e($trade['direction']) ?>"><?= icon($trade['direction']==='up'?'arrow-up':'arrow-down', 14) ?><?= e(strtoupper($trade['direction'])) ?></span></td><td><?= e(money($trade['amount'], $user['currency'])) ?></td><td><span class="status status--<?= e($trade['status']) ?>"><?= e($trade['status']) ?></span></td><td class="<?= (float)$trade['profit_loss'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$trade['profit_loss'] > 0 ? '+' : '' ?><?= e(money($trade['profit_loss'], $user['currency'])) ?></td><td><?= e(date('d M H:i', strtotime($trade['opened_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
<?php render_public_footer(); render_document_end(); ?>
