<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
settle_open_trades((int)$user['id']);

$status = (string)($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'open', 'won', 'lost', 'draw'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$page = max(1, (int)($_GET['p'] ?? 1));
$accountType = trade_account_type();
$where = ' WHERE t.user_id = ? AND t.account_type = ?';
$params = [(int)$user['id'], $accountType];
if ($status !== 'all') {
    $where .= ' AND t.status = ?';
    $params[] = $status;
}
$countStmt = db()->prepare('SELECT COUNT(*) FROM trades t' . $where);
$countStmt->execute($params);
$pager = pagination((int)$countStmt->fetchColumn(), $page, 25);
$stmt = db()->prepare('SELECT t.public_id, a.symbol, a.display_name, a.decimal_places, t.direction, t.amount, t.payout_percent, t.entry_price, t.close_price, t.opened_at, t.expires_at, t.settled_at, t.status, t.profit_loss FROM trades t JOIN market_assets a ON a.id = t.asset_id' . $where . ' ORDER BY t.id DESC LIMIT 25 OFFSET ' . (int)$pager['offset']);
$stmt->execute($params);
$trades = $stmt->fetchAll();

$statsStmt = db()->prepare("SELECT COUNT(*) total, SUM(status = 'won') won, SUM(status = 'lost') lost, COALESCE(SUM(profit_loss), 0) net FROM trades WHERE user_id = ? AND account_type = ? AND status <> 'open'");
$statsStmt->execute([(int)$user['id'], $accountType]);
$stats = $statsStmt->fetch();
$winRate = ((int)$stats['won'] + (int)$stats['lost']) > 0 ? ((int)$stats['won'] / ((int)$stats['won'] + (int)$stats['lost'])) * 100 : 0;

render_document_start('Trade history', 'account-page black qx-account-shell qx-history-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('history');
render_flashes();
?>
<main class="account-main qx-cabinet-page">
    <section class="page-heading qx-page-heading"><div><span class="eyebrow">Performance log · <?= e(ucfirst($accountType)) ?> Account</span><h1>Trade history</h1><p>Every entry and expiry price is retained for review.</p></div><a class="button button--primary" href="/en/trade/">Open trade room</a></section>
    <section class="stat-grid"><article><span>Total settled</span><strong><?= e((int)$stats['total']) ?></strong><small>All recorded trades</small></article><article><span>Wins</span><strong class="amount-positive"><?= e((int)$stats['won']) ?></strong><small><?= e(number_format($winRate, 1)) ?>% win rate</small></article><article><span>Losses</span><strong class="amount-negative"><?= e((int)$stats['lost']) ?></strong><small>Performance review</small></article><article><span>Net P/L</span><strong class="<?= (float)$stats['net'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$stats['net'] >= 0 ? '+' : '' ?><?= e(money($stats['net'])) ?></strong><small>Wallet result</small></article></section>
    <section class="panel history-panel"><div class="panel-heading history-heading"><div><span class="eyebrow">Detailed ledger</span><h2>Trades</h2></div><div class="filter-pills"><?php foreach ($allowedStatuses as $filter): ?><a class="<?= $status === $filter ? 'active' : '' ?>" href="?status=<?= e($filter) ?>"><?= e(ucfirst($filter)) ?></a><?php endforeach; ?></div></div><div class="table-scroll"><table><thead><tr><th>Trade</th><th>Market</th><th>Direction</th><th>Entry → Close</th><th>Investment</th><th>Result</th><th>P/L</th><th>Opened</th></tr></thead><tbody><?php if (!$trades): ?><tr><td colspan="8" class="table-empty">No trades match this filter.</td></tr><?php else: ?><?php foreach ($trades as $trade): ?><tr><td><code><?= e($trade['public_id']) ?></code></td><td><strong><?= e($trade['display_name']) ?></strong><small class="table-subtext"><?= e($trade['symbol']) ?></small></td><td><span class="direction direction--<?= e($trade['direction']) ?>"><?= icon($trade['direction'] === 'up' ? 'arrow-up' : 'arrow-down', 14) ?> <?= e(ucfirst($trade['direction'])) ?></span></td><td><span class="price-pair"><?= e(number_format((float)$trade['entry_price'], (int)$trade['decimal_places'], '.', '')) ?> <b>→</b> <?= $trade['close_price'] === null ? '—' : e(number_format((float)$trade['close_price'], (int)$trade['decimal_places'], '.', '')) ?></span></td><td><?= e(money($trade['amount'])) ?></td><td><span class="status status--<?= e($trade['status']) ?>"><?= e(ucfirst($trade['status'])) ?></span></td><td class="<?= (float)$trade['profit_loss'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$trade['profit_loss'] > 0 ? '+' : '' ?><?= e(money($trade['profit_loss'])) ?></td><td><?= e(date('d M, H:i:s', strtotime($trade['opened_at']))) ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div><?php if ($pager['pages'] > 1): ?><nav class="pagination"><?php for ($i = 1; $i <= $pager['pages']; $i++): ?><a class="<?= $i === $pager['page'] ? 'active' : '' ?>" href="?status=<?= e($status) ?>&p=<?= $i ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?></section>
</main>
<?php render_public_footer(); render_document_end(); ?>
