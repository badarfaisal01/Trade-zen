<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
expire_deposit_orders((int)$user['id']);

if (is_post()) {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? 'create_ticket');
        if ($action === 'create_ticket') {
            $id = create_support_ticket(
                (int)$user['id'],
                (string)($_POST['category'] ?? ''),
                (string)($_POST['subject'] ?? ''),
                (string)($_POST['message'] ?? ''),
                (int)($_POST['fund_request_id'] ?? 0)
            );
            flash('success', 'Support request ' . $id . ' was created.');
        } elseif ($action === 'close_ticket') {
            close_support_ticket((int)$user['id'], (int)($_POST['ticket_id'] ?? 0));
            flash('success', 'Support request closed.');
        } else {
            throw new InvalidArgumentException('Unknown support action.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect('/en/support/');
}

$ordersStmt = db()->prepare('SELECT id,public_id,request_type,amount,status,created_at FROM fund_requests WHERE user_id=? ORDER BY id DESC LIMIT 80');
$ordersStmt->execute([(int)$user['id']]);
$orders = $ordersStmt->fetchAll();
$requestedOrder = strtoupper(trim((string)($_GET['order'] ?? '')));
$selectedOrderId = 0;
foreach ($orders as $orderRow) {
    if ($requestedOrder !== '' && hash_equals((string)$orderRow['public_id'], $requestedOrder)) {
        $selectedOrderId = (int)$orderRow['id'];
        break;
    }
}

$stmt = db()->prepare('SELECT s.*,r.public_id order_public_id,r.request_type order_type,r.amount order_amount,r.status order_status FROM support_tickets s LEFT JOIN fund_requests r ON r.id=s.fund_request_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 30');
$stmt->execute([(int)$user['id']]);
$tickets = $stmt->fetchAll();

render_document_start('Support', 'account-page black qx-account-shell qx-support-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('support');
render_flashes();
?>
<main class="account-main support-page qx-cabinet-page">
    <?php render_account_switcher('support'); ?>

    <section class="page-heading qx-page-heading">
        <div><span class="eyebrow">Help center</span><h1>Customer support</h1><p>Discuss a deposit, withdrawal, trade, account, or technical issue with the administrator team.</p></div>
        <a class="button button--ghost" href="mailto:<?= e(setting('support_email', 'support@55ak.xyz')) ?>"><?= e(setting('support_email', 'support@55ak.xyz')) ?></a>
    </section>

    <section class="account-grid support-grid">
        <article class="panel request-panel">
            <div class="panel-heading"><div><span class="eyebrow">New request</span><h2>Contact support</h2></div><?= icon('support', 23) ?></div>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_ticket">
                <label class="field"><span>Category</span><select name="category" required><option value="account">Account</option><option value="payment" <?= $selectedOrderId > 0 ? 'selected' : '' ?>>Deposit / withdrawal</option><option value="trade">Trade</option><option value="technical">Technical</option><option value="other">Other</option></select></label>
                <label class="field"><span>Related deposit or withdrawal order <small>(optional)</small></span><select name="fund_request_id"><option value="0">No order selected</option><?php foreach ($orders as $order): ?><option value="<?= (int)$order['id'] ?>" <?= (int)$order['id'] === $selectedOrderId ? 'selected' : '' ?>><?= e($order['public_id']) ?> · <?= e(ucfirst($order['request_type'])) ?> · <?= e(money($order['amount'], $user['currency'])) ?> · <?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></option><?php endforeach; ?></select><small>All your deposit and withdrawal orders appear here.</small></label>
                <label class="field"><span>Subject</span><input name="subject" minlength="5" maxlength="140" value="<?= $selectedOrderId > 0 ? e('Help with order ' . $requestedOrder) : '' ?>" required></label>
                <label class="field"><span>Message</span><textarea name="message" minlength="10" maxlength="5000" rows="7" placeholder="Describe what happened and what you need help with" required></textarea></label>
                <button class="button button--primary">Send request</button>
            </form>
        </article>

        <article class="panel">
            <div class="panel-heading"><div><span class="eyebrow">Your requests</span><h2>Status &amp; replies</h2></div></div>
            <div class="ticket-list">
                <?php if (!$tickets): ?><div class="empty-state"><?= icon('support', 26) ?><strong>No support requests</strong><span>Your messages and administrator replies will appear here.</span></div><?php endif; ?>
                <?php foreach ($tickets as $ticket): ?>
                    <article class="ticket-card">
                        <header><span><strong><?= e($ticket['subject']) ?></strong><small><?= e($ticket['public_id']) ?> · <?= e(ucfirst($ticket['category'])) ?> · <?= e(date('d M Y H:i', strtotime($ticket['created_at']))) ?></small></span><b class="status status--<?= e($ticket['status']) ?>"><?= e($ticket['status']) ?></b></header>
                        <?php if ($ticket['order_public_id']): ?><a class="ticket-order-link" href="<?= $ticket['order_type'] === 'deposit' ? '/en/pay/?order=' . e(rawurlencode($ticket['order_public_id'])) : '/en/withdrawal/' ?>"><?= icon('payment', 16) ?><span><strong><?= e($ticket['order_public_id']) ?></strong><small><?= e(ucfirst($ticket['order_type'])) ?> · <?= e(money($ticket['order_amount'], $user['currency'])) ?> · <?= e(ucwords(str_replace('_', ' ', $ticket['order_status']))) ?></small></span></a><?php endif; ?>
                        <p><?= nl2br(e($ticket['message'])) ?></p>
                        <?php if ($ticket['admin_response']): ?><div class="ticket-response"><strong>Administrator reply</strong><p><?= nl2br(e($ticket['admin_response'])) ?></p><small><?= $ticket['answered_at'] ? e(date('d M Y H:i', strtotime($ticket['answered_at']))) : '' ?></small></div><?php endif; ?>
                        <?php if ($ticket['status'] !== 'closed'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_ticket"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><button>Close request</button></form><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
    <div class="security-banner"><?= icon('shield', 22) ?><div><strong>Do not include passwords or payment secrets.</strong><p>Support does not need your OTP, PIN, card CVV, private key, or wallet seed phrase.</p></div></div>
</main>
<?php render_public_footer(); render_document_end(['js/account.js']); ?>
