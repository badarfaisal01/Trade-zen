<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
expire_deposit_orders((int)$user['id']);

$publicId = strtoupper(trim((string)($_GET['order'] ?? $_POST['order'] ?? '')));
if (!preg_match('/^[A-Z0-9]{8,32}$/', $publicId)) {
    http_response_code(404);
    exit('Payment order not found.');
}

$loadOrder = static function () use ($user, $publicId): ?array {
    $stmt = db()->prepare("SELECT r.*,m.code method_code,m.name method_name,m.category method_category,m.icon_key,m.account_name,m.account_identifier,m.network,m.instructions,m.qr_filename,m.require_reference,m.require_proof,p.code promo_code FROM fund_requests r JOIN payment_methods m ON m.id=r.payment_method_id LEFT JOIN promo_codes p ON p.id=r.promo_code_id WHERE r.public_id=? AND r.user_id=? AND r.request_type='deposit' LIMIT 1");
    $stmt->execute([$publicId, (int)$user['id']]);
    $row = $stmt->fetch();
    return $row ?: null;
};

$order = $loadOrder();
if (!$order) {
    http_response_code(404);
    exit('Payment order not found.');
}

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'confirm_payment');
    try {
        if ($action === 'confirm_payment') {
            $proof = store_protected_image($_FILES['proof_image'] ?? [], 'payment-proofs', 'proof');
            try {
                $result = confirm_deposit_order(
                    (int)$user['id'],
                    (int)$order['id'],
                    (string)($_POST['user_reference'] ?? ''),
                    $proof,
                    (string)($_POST['note'] ?? '')
                );
                if (!empty($result['duplicate']) && $proof) {
                    remove_protected_image($proof, 'payment-proofs');
                }
            } catch (Throwable $exception) {
                if ($proof) {
                    remove_protected_image($proof, 'payment-proofs');
                }
                throw $exception;
            }
            flash('success', 'Payment confirmed. Deposit order ' . $publicId . ' was sent to the administrator for verification.');
        } elseif ($action === 'cancel_order') {
            cancel_fund_request((int)$user['id'], (int)$order['id']);
            flash('success', 'Deposit order ' . $publicId . ' was cancelled.');
        } else {
            throw new InvalidArgumentException('Unknown payment action.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect('/en/pay/?order=' . rawurlencode($publicId));
}

$order = $loadOrder() ?: $order;
$paymentTotal = round((float)$order['amount'] + (float)$order['fee_amount'], 2);
$methodHint = strtolower((string)$order['method_code'] . ' ' . (string)$order['method_name'] . ' ' . (string)$order['network']);
$isBinance = strpos($methodHint, 'binance') !== false;
$isUpi = in_array((string)$order['method_category'], ['upi', 'wallet'], true) || preg_match('/upi|phonepe|paytm/', $methodHint);
$destinationLabel = $isBinance ? 'Binance Pay ID / wallet address' : ($isUpi ? 'UPI ID / PhonePe payment ID' : 'Payment address / account');
$referenceLabel = $isBinance ? 'Binance order ID / transaction ID' : ($isUpi ? 'UTR / transaction reference' : 'Transaction hash / reference');
$statusLabel = ucwords(str_replace('_', ' ', (string)$order['status']));
$expiresAtMs = $order['payment_expires_at'] ? (int)strtotime((string)$order['payment_expires_at']) * 1000 : 0;
$canPay = (string)$order['status'] === 'awaiting_payment';

render_document_start('Pay deposit order', 'account-page black qx-account-shell qx-payment-order-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('wallet');
render_flashes();
?>
<main class="account-main qx-cabinet-page payment-order-page">
    <?php render_account_switcher('deposit'); ?>

    <section class="page-heading qx-page-heading qx-funds-heading">
        <div><span class="eyebrow">Secure payment</span><h1>Complete deposit</h1><p>Pay only to the details shown for this order, then confirm the transaction for administrator review.</p></div>
        <a class="qx-funds-close" href="/en/balance/" aria-label="Back to deposit"><?= icon('x', 20) ?></a>
        <div class="balance-card"><span>Order</span><strong><?= e($publicId) ?></strong><small><span class="status-dot"></span><?= e($order['method_name']) ?></small></div>
    </section>

    <section class="payment-order-summary panel">
        <div><small>Order ID</small><strong><?= e($publicId) ?></strong></div>
        <div><small>Deposit amount</small><strong><?= e(money($order['amount'], $user['currency'])) ?></strong></div>
        <?php if ((float)$order['fee_amount'] > 0): ?><div><small>Method fee</small><strong><?= e(money($order['fee_amount'], $user['currency'])) ?></strong></div><?php endif; ?>
        <div class="payment-order-summary__total"><small>Amount to pay</small><strong><?= e(money($paymentTotal, $user['currency'])) ?></strong></div>
        <div><small>Status</small><b class="status status--<?= e($order['status']) ?>"><?= e($statusLabel) ?></b></div>
    </section>

    <?php if ($canPay): ?>
        <section class="payment-order-grid" data-payment-order data-payment-expires-at="<?= $expiresAtMs ?>">
            <article class="panel payment-destination-card">
                <div class="panel-heading"><div><span class="eyebrow"><?= $isBinance ? 'Binance' : ($isUpi ? 'UPI / PhonePe' : 'Payment') ?></span><h2><?= e($order['method_name']) ?> details</h2></div><?= payment_method_mark($order) ?></div>
                <div class="payment-destination-body">
                    <div class="payment-countdown"><span><?= icon('clock', 20) ?></span><div><small>Complete before order expires</small><strong data-payment-countdown>--:--</strong></div></div>
                    <?php if ($order['qr_filename']): ?><figure class="payment-order-qr"><img src="/en/payment-method-image/?id=<?= (int)$order['payment_method_id'] ?>&amp;order=<?= e(rawurlencode($publicId)) ?>" alt="<?= e($order['method_name']) ?> payment QR"><figcaption>Scan and pay exactly <?= e(money($paymentTotal, $user['currency'])) ?></figcaption></figure><?php endif; ?>
                    <?php if ($order['account_name']): ?><div class="payment-detail-line"><small>Account name</small><strong><?= e($order['account_name']) ?></strong></div><?php endif; ?>
                    <?php if ($order['account_identifier']): ?><div class="payment-detail-line"><small><?= e($destinationLabel) ?></small><span><code><?= e($order['account_identifier']) ?></code><button type="button" data-payment-copy="<?= e($order['account_identifier']) ?>">Copy</button></span></div><?php endif; ?>
                    <?php if ($order['network']): ?><div class="payment-detail-line"><small>Network / channel</small><strong><?= e($order['network']) ?></strong></div><?php endif; ?>
                    <?php if ($order['instructions']): ?><div class="payment-instructions"><?= nl2br(e($order['instructions'])) ?></div><?php endif; ?>
                    <div class="security-banner payment-security-note"><?= icon('shield', 20) ?><div><strong>Never share OTP, PIN, password or private key.</strong><p>Confirm only after the payment app shows a successful transaction.</p></div></div>
                </div>
            </article>

            <article class="panel request-panel payment-confirm-card">
                <div class="panel-heading"><div><span class="eyebrow">Final step</span><h2>Confirm payment</h2></div><?= icon('check', 23) ?></div>
                <form method="post" enctype="multipart/form-data" data-payment-confirm-form>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="confirm_payment">
                    <input type="hidden" name="order" value="<?= e($publicId) ?>">
                    <label class="field"><span><?= e($referenceLabel) ?><?= (int)$order['require_reference'] === 1 ? '' : ' (optional)' ?></span><input name="user_reference" minlength="3" maxlength="120" autocomplete="off" placeholder="Enter the successful payment reference" <?= (int)$order['require_reference'] === 1 ? 'required' : '' ?>></label>
                    <label class="field qx-file-field"><span>Payment screenshot<?= (int)$order['require_proof'] === 1 ? '' : ' (optional)' ?></span><input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp" <?= (int)$order['require_proof'] === 1 ? 'required' : '' ?>><small>JPG, PNG or WebP; administrator uses this only for verification.</small></label>
                    <label class="field"><span>Payment note <small>(optional)</small></span><textarea name="note" maxlength="500" rows="3" placeholder="Anything the administrator should verify"><?= e((string)$order['user_note']) ?></textarea></label>
                    <button class="button button--primary button--wide" type="submit">I have paid · Confirm payment</button>
                </form>
                <form method="post" class="cancel-payment-order" data-cancel-payment-order>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="cancel_order">
                    <input type="hidden" name="order" value="<?= e($publicId) ?>">
                    <button type="submit">Cancel this order</button>
                </form>
            </article>
        </section>
    <?php else: ?>
        <section class="panel payment-order-result payment-order-result--<?= e($order['status']) ?>">
            <span class="payment-order-result__icon"><?= icon(in_array($order['status'], ['pending', 'approved', 'paid'], true) ? 'check' : 'clock', 34) ?></span>
            <div>
                <span class="eyebrow">Order status</span>
                <h2><?= e($statusLabel) ?></h2>
                <?php if ($order['status'] === 'pending'): ?><p>Your confirmation has reached the administrator. The Live balance is credited only after verification and approval.</p>
                <?php elseif (in_array($order['status'], ['approved', 'paid'], true)): ?><p>This deposit has been approved and credited to your Live account.</p>
                <?php elseif ($order['status'] === 'expired'): ?><p>The payment window ended before confirmation. Create a new order to receive current payment details and a fresh timer.</p>
                <?php elseif ($order['status'] === 'cancelled'): ?><p>This order was cancelled and cannot be reused.</p>
                <?php else: ?><p>Administrator review is complete. Check the note or contact support if you need help.</p><?php endif; ?>
                <div class="payment-result-actions"><a class="button button--primary" href="/en/balance/">New deposit order</a><a class="button button--ghost" href="/en/support/?order=<?= e(rawurlencode($publicId)) ?>">Discuss this order</a><a class="button button--ghost" href="/en/trade/">Back to trade</a></div>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php render_public_footer(); render_document_end(['js/account.js', 'js/payment.js']); ?>
