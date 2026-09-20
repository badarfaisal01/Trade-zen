<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
$forcedAction = defined('WALLET_FORCE_ACTION') ? (string)WALLET_FORCE_ACTION : '';
$action = $forcedAction === 'withdrawal' ? 'withdrawal' : ($forcedAction === 'deposit' ? 'deposit' : ((($_GET['action'] ?? '') === 'withdrawal') ? 'withdrawal' : 'deposit'));
$requestPath = rtrim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
$dedicatedFlow = in_array($requestPath, ['/en/balance', '/en/withdrawal'], true) || $forcedAction !== '';
$canonicalUrl = $action === 'withdrawal' ? '/en/withdrawal/' : '/en/balance/';
$fromTrade = $action === 'deposit' && (string)($_GET['from'] ?? '') === 'trade';
$requestedAmount = round((float)($_GET['amount'] ?? 100), 2);
$depositFloor = max(0.01, (float)setting('minimum_demo_deposit', 10));
$suggestedAmount = $action === 'deposit' && $requestedAmount > 0 ? min(100000000.0, max($depositFloor, $requestedAmount)) : max(100.0, $depositFloor);
$prefillPromo = $action === 'deposit' ? strtoupper(trim((string)($_GET['promo'] ?? ''))) : '';
if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $prefillPromo)) $prefillPromo = '';
if (!$forcedAction && $requestPath === '/en/wallet' && !is_post()) {
    redirect($canonicalUrl);
}

if (is_post()) {
    verify_csrf();
    $postAction = (string)($_POST['action'] ?? 'fund_request');
    $redirectTo = $action === 'withdrawal' ? '/en/withdrawal/' : '/en/balance/';
    try {
        if ($postAction === 'fund_request') {
            $requestType = $dedicatedFlow ? $action : (string)($_POST['request_type'] ?? '');
            if ($requestType === 'deposit') {
                $request = create_deposit_order(
                    (int)$user['id'],
                    (float)($_POST['amount'] ?? 0),
                    (int)($_POST['payment_method_id'] ?? 0),
                    (string)($_POST['note'] ?? ''),
                    (string)($_POST['promo_code'] ?? '')
                );
                flash('success', 'Deposit order ' . $request['id'] . ' created. Complete payment before the timer expires.');
                $redirectTo = (string)$request['redirect'];
            } else {
                $request = create_fund_request(
                    (int)$user['id'],
                    $requestType,
                    (float)($_POST['amount'] ?? 0),
                    (string)($_POST['note'] ?? ''),
                    (int)($_POST['payment_method_id'] ?? 0),
                    '',
                    null,
                    [
                        'account_name' => (string)($_POST['account_name'] ?? ''),
                        'account_identifier' => (string)($_POST['account_identifier'] ?? ''),
                    ]
                );
                flash('success', ucfirst($request['type']) . ' request ' . $request['id'] . ' was sent to the administrator.');
            }
            $action = $request['type'];
            if ($action === 'withdrawal') {
                $redirectTo = '/en/withdrawal/';
            }
        } elseif ($postAction === 'cancel_request') {
            cancel_fund_request((int)$user['id'], (int)($_POST['request_id'] ?? 0));
            flash('success', 'Request cancelled. Any reserved withdrawal amount was returned.');
        } elseif ($postAction === 'redeem_gift') {
            $credit = redeem_gift_code((int)$user['id'], (string)($_POST['gift_code'] ?? ''));
            flash('success', money($credit, $user['currency']) . ' was added to your wallet.');
        } else {
            throw new InvalidArgumentException('Unknown wallet action.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect($redirectTo);
}

expire_deposit_orders((int)$user['id']);
$depositMethods = payment_methods_for('deposit');
$withdrawalMethods = payment_methods_for('withdrawal');
$allMethodsById = [];
$methodSource = $dedicatedFlow ? ($action === 'withdrawal' ? $withdrawalMethods : $depositMethods) : array_merge($depositMethods, $withdrawalMethods);
foreach ($methodSource as $method) {
    $allMethodsById[(int)$method['id']] = $method;
}
$allMethods = array_values($allMethodsById);
if ($dedicatedFlow) {
    $requestStmt = db()->prepare('SELECT r.*,m.name method_name,m.network,p.code promo_code FROM fund_requests r LEFT JOIN payment_methods m ON m.id=r.payment_method_id LEFT JOIN promo_codes p ON p.id=r.promo_code_id WHERE r.user_id=? AND r.request_type=? ORDER BY r.id DESC LIMIT 40');
    $requestStmt->execute([(int)$user['id'], $action]);
} else {
    $requestStmt = db()->prepare('SELECT r.*,m.name method_name,m.network,p.code promo_code FROM fund_requests r LEFT JOIN payment_methods m ON m.id=r.payment_method_id LEFT JOIN promo_codes p ON p.id=r.promo_code_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 40');
    $requestStmt->execute([(int)$user['id']]);
}
$requests = $requestStmt->fetchAll();
$withdrawalHeld = 0.0;
$withdrawalInReview = 0;
if ($action === 'withdrawal') {
    try {
        $withdrawalSummaryStmt = db()->prepare("SELECT COUNT(*) request_count,COALESCE(SUM(amount+fee_amount),0) held_amount FROM fund_requests WHERE user_id=? AND request_type='withdrawal' AND status IN ('pending','approved') AND balance_reserved=1");
        $withdrawalSummaryStmt->execute([(int)$user['id']]);
        $withdrawalSummary = $withdrawalSummaryStmt->fetch() ?: [];
        $withdrawalHeld = (float)($withdrawalSummary['held_amount'] ?? 0);
        $withdrawalInReview = (int)($withdrawalSummary['request_count'] ?? 0);
    } catch (Throwable $ignored) {
        $withdrawalHeld = 0.0;
        $withdrawalInReview = 0;
    }
}
$ledgerStmt = db()->prepare("SELECT transaction_type,amount,balance_after,description,created_at FROM wallet_transactions WHERE user_id=? AND account_type='live' ORDER BY id DESC LIMIT 50");
$ledgerStmt->execute([(int)$user['id']]);
$ledger = $ledgerStmt->fetchAll();
$featuredPromo = null;
try {
    $promoStmt = db()->query("SELECT code,value_type,value_amount,maximum_bonus,minimum_amount FROM promo_codes WHERE code_type='deposit_bonus' AND is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) ORDER BY value_amount DESC,id DESC LIMIT 1");
    $featuredPromo = $promoStmt->fetch() ?: null;
} catch (Throwable $exception) {
    $featuredPromo = null;
}

render_document_start($action === 'withdrawal' ? 'Withdrawal' : 'Deposit', 'account-page black qx-account-shell qx-wallet-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('wallet');
render_flashes();
?>
<main class="account-main wallet-page qx-cabinet-page <?= $action === 'withdrawal' ? 'qx-withdraw-page' : 'qx-deposit-page' ?>" data-initial-flow="<?= e($action) ?>" data-wallet-balance="<?= e(number_format((float)$user['demo_balance'], 2, '.', '')) ?>">
    <?php render_account_switcher($action); ?>

    <section class="qx-cabinet-banner" data-dismissible-banner>
        <span class="qx-cabinet-banner__icon"><?= icon($featuredPromo ? 'coupon' : 'shield', 21) ?></span>
        <div>
            <?php if ($featuredPromo && $action === 'deposit'): ?>
                <strong><?= $featuredPromo['value_type'] === 'percent' ? e(number_format((float)$featuredPromo['value_amount'], 0)) . '% deposit bonus' : e(money($featuredPromo['value_amount'], $user['currency'])) . ' deposit bonus' ?></strong>
                <span>Use code <b><?= e($featuredPromo['code']) ?></b><?= (float)$featuredPromo['minimum_amount'] > 0 ? ' from ' . e(money($featuredPromo['minimum_amount'], $user['currency'])) : '' ?>.</span>
                <button type="button" data-apply-promo="<?= e($featuredPromo['code']) ?>">Use coupon</button>
            <?php elseif ($fromTrade): ?>
                <strong>Add balance to continue trading</strong>
                <span>Your trade was not opened because the wallet balance was too low. Deposit first, then return to Trade.</span>
            <?php else: ?>
                <strong><?= $action === 'withdrawal' ? 'Withdrawal protection' : 'Secure deposit request' ?></strong>
                <span><?= $action === 'withdrawal' ? 'Withdrawal requests are reviewed before wallet credit is released.' : 'Use only the payment details shown inside your signed-in account.' ?></span>
            <?php endif; ?>
        </div>
        <button class="qx-cabinet-banner__close" type="button" data-dismiss-banner aria-label="Dismiss banner"><?= icon('x', 15) ?></button>
    </section>

    <section class="page-heading qx-page-heading qx-funds-heading">
        <div><span class="eyebrow">Finance</span><h1><?= $action === 'withdrawal' ? 'Withdrawal' : 'Deposit' ?></h1><p><?= $action === 'withdrawal' ? 'Choose where your wallet request should be sent and track its review status.' : 'Choose a payment method and amount, review the order price, then continue to the secure Pay page.' ?></p></div>
        <a class="qx-funds-close" href="/en/trade/" aria-label="Back to trade"><?= icon('x', 20) ?></a>
        <div class="balance-card"><span>Available balance</span><strong><?= e(money($user['demo_balance'], $user['currency'])) ?></strong><small><span class="status-dot"></span><?= e($user['currency']) ?> account</small></div>
    </section>

    <?php if ($action === 'withdrawal'): ?>
    <section class="qx-withdraw-overview" aria-label="Withdrawal summary">
        <article class="qx-withdraw-stat qx-withdraw-stat--available">
            <span class="qx-withdraw-stat__icon"><?= icon('wallet', 19) ?></span>
            <div><small>Available to request</small><strong><?= e(money($user['demo_balance'], $user['currency'])) ?></strong><em>Live wallet balance</em></div>
        </article>
        <article class="qx-withdraw-stat">
            <span class="qx-withdraw-stat__icon"><?= icon('clock', 19) ?></span>
            <div><small>Reserved / in review</small><strong><?= e(money($withdrawalHeld, $user['currency'])) ?></strong><em><?= $withdrawalInReview ?> active request<?= $withdrawalInReview === 1 ? '' : 's' ?></em></div>
        </article>
        <article class="qx-withdraw-stat">
            <span class="qx-withdraw-stat__icon"><?= icon('shield', 19) ?></span>
            <div><small>Request protection</small><strong>Admin review</strong><em>Destination checked before release</em></div>
        </article>
    </section>
    <section class="qx-withdraw-steps" aria-label="Withdrawal steps">
        <div class="active"><b>1</b><span><strong>Choose payout method</strong><small>UPI, wallet, bank or crypto</small></span></div>
        <i></i>
        <div><b>2</b><span><strong>Add destination</strong><small>Enter account / address carefully</small></span></div>
        <i></i>
        <div><b>3</b><span><strong>Review & release</strong><small>Track status in this page</small></span></div>
    </section>
    <?php endif; ?>

    <section class="wallet-layout qx-wallet-layout">
        <article class="panel request-panel wallet-request-panel qx-payment-panel">
            <div class="panel-heading qx-payment-heading">
                <div><span class="eyebrow">New request</span><h2 id="paymentFlowTitle"><?= $action === 'withdrawal' ? 'Withdrawal' : 'Deposit' ?></h2></div>
                <?php if (!$dedicatedFlow): ?>
                    <div class="segmented"><button class="<?= $action === 'deposit' ? 'active' : '' ?>" type="button" data-request-type="deposit">Deposit</button><button class="<?= $action === 'withdrawal' ? 'active' : '' ?>" type="button" data-request-type="withdrawal">Withdraw</button></div>
                <?php else: ?>
                    <span class="qx-flow-badge"><?= $action === 'withdrawal' ? 'Withdrawal only' : 'Deposit only' ?></span>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" id="fundRequestForm">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="fund_request">
                <input type="hidden" name="request_type" id="requestType" value="<?= e($action) ?>">

                <section class="payment-method-section qx-payment-picker" aria-labelledby="paymentMethodLabel">
                    <div class="qx-step-title"><span>1</span><div><strong id="paymentMethodLabel"><?= $action === 'withdrawal' ? 'Payout method' : 'Payment method' ?></strong><small><?= $action === 'withdrawal' ? 'Choose where you want to receive funds' : 'Select an available option' ?></small></div></div>
                    <label class="qx-region-row qx-region-select"><span><?= icon('globe', 15) ?></span><select aria-label="Payment country" data-payment-country>
                            <?php foreach (app_all_countries() as $countryCode => $countryName): ?>
                                <option value="<?= e($countryCode) ?>" <?= strtoupper((string)($user['country'] ?: 'IN')) === $countryCode ? 'selected' : '' ?>><?= e($countryName) ?></option>
                            <?php endforeach; ?>
                        </select><small>Payment region</small></label>
                    <div class="payment-category-tabs" role="tablist" aria-label="Payment categories">
                        <button class="active" type="button" data-method-filter="popular" role="tab" aria-selected="true"><?= icon('payment', 18) ?><span>Popular</span></button>
                        <button type="button" data-method-filter="epay" role="tab" aria-selected="false"><?= icon('wallet', 18) ?><span>E-pay</span></button>
                        <button type="button" data-method-filter="crypto" role="tab" aria-selected="false"><?= icon('asset', 18) ?><span>Crypto</span></button>
                    </div>
                    <div class="qx-method-caption" data-method-caption>Popular in your region</div>
                    <?php $flowsToRender = $dedicatedFlow ? [$action => ($action === 'withdrawal' ? $withdrawalMethods : $depositMethods)] : ['deposit' => $depositMethods, 'withdrawal' => $withdrawalMethods]; ?>
                    <?php foreach ($flowsToRender as $flow => $methods): ?>
                        <div class="payment-method-list" data-method-flow="<?= e($flow) ?>" <?= $flow !== $action ? 'hidden' : '' ?>>
                            <?php if (!$methods): ?><div class="method-empty">No <?= e($flow) ?> method is available right now.</div><?php endif; ?>
                            <?php foreach ($methods as $index => $method): $methodCategory = strtolower((string)$method['category']) === 'crypto' ? 'crypto' : 'epay'; ?>
                                <label class="payment-method-card payment-method-row" data-method-card="<?= (int)$method['id'] ?>" data-method-category="<?= e($methodCategory) ?>" data-method-popular="<?= $index < 8 ? '1' : '0' ?>">
                                    <input type="radio" name="payment_method_id" value="<?= (int)$method['id'] ?>" data-flow="<?= e($flow) ?>" data-require-reference="<?= (int)$method['require_reference'] ?>" data-require-proof="<?= (int)$method['require_proof'] ?>" data-network="<?= e((string)$method['network']) ?>" data-method-name="<?= e($method['name']) ?>" data-minimum="<?= e($method['minimum_amount']) ?>" data-maximum="<?= e($method['maximum_amount']) ?>" data-fee-percent="<?= e($method['fee_percent']) ?>" data-fee-fixed="<?= e($method['fee_fixed']) ?>" <?= $flow === $action && $index === 0 ? 'checked' : '' ?>>
                                    <?= payment_method_mark($method) ?>
                                    <span class="payment-method-row__copy"><strong><?= e($method['name']) ?></strong><small><?= $method['network'] ? e($method['network']) . ' · ' : '' ?>Min <?= e(money($method['minimum_amount'], $user['currency'])) ?></small></span>
                                    <span class="payment-method-row__state"><?= icon('check', 16) ?><?= icon('arrow-right', 16) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <div class="method-filter-empty" data-method-filter-empty hidden>No methods are configured in this category.</div>
                        </div>
                    <?php endforeach; ?>
                </section>

                <?php if ($action === 'withdrawal'): ?><div class="method-detail-stack">
                    <?php foreach ($allMethods as $method): ?>
                        <section class="method-detail" data-method-detail="<?= (int)$method['id'] ?>" hidden>
                            <?php if ($method['qr_filename']): ?><img class="method-qr" src="/en/payment-method-image/?id=<?= (int)$method['id'] ?>" alt="<?= e($method['name']) ?> payment QR"><?php endif; ?>
                            <div class="method-detail-copy"><strong><?= e($method['name']) ?><?= $method['network'] ? ' · ' . e($method['network']) : '' ?></strong>
                                <?php if ($method['account_name']): ?><span>Account name: <b><?= e($method['account_name']) ?></b></span><?php endif; ?>
                                <?php if ($method['account_identifier']): ?><span class="copy-line">Payment ID / address: <code><?= e($method['account_identifier']) ?></code><button type="button" data-copy="<?= e($method['account_identifier']) ?>">Copy</button></span><?php endif; ?>
                                <?php if ($method['instructions']): ?><p><?= nl2br(e($method['instructions'])) ?></p><?php endif; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div><?php endif; ?>

                <section class="qx-payment-fields" aria-labelledby="paymentDetailsLabel">
                    <div class="qx-step-title"><span>2</span><div><strong id="paymentDetailsLabel"><?= $action === 'withdrawal' ? 'Amount & destination' : 'Order & price' ?></strong><small id="selectedMethodSummary"><?= $action === 'withdrawal' ? 'Confirm amount and receiving account' : 'Review your selected method and deposit amount' ?></small></div></div>
                    <label class="field"><span>Amount</span><span class="money-input"><b><?= e(original_currency_symbol($user['currency'])) ?></b><input type="number" name="amount" min="0.01" max="100000000" step="0.01" value="<?= e(number_format($suggestedAmount, 2, '.', '')) ?>" required></span></label>
                    <div class="quick-amounts"><?php if ($action === 'withdrawal'): ?><button type="button" data-wallet-percent="0.25">25%</button><button type="button" data-wallet-percent="0.50">50%</button><button type="button" data-wallet-percent="0.75">75%</button><button type="button" data-wallet-percent="1">Max</button><?php else: ?><button type="button" data-amount="50">50</button><button type="button" data-amount="100">100</button><button type="button" data-amount="500">500</button><button type="button" data-amount="1000">1,000</button><?php endif; ?></div>
                    <div class="fee-summary" id="methodFeeSummary" hidden></div>

                    <div data-flow-fields="deposit">
                        <label class="field"><span>Deposit coupon <small>(optional)</small></span><input name="promo_code" maxlength="40" autocomplete="off" placeholder="Enter coupon code" value="<?= e($prefillPromo) ?>"></label>
                        <div class="deposit-order-preview" data-deposit-order-preview><?= icon('shield', 18) ?><span><strong>Secure payment page</strong><small>QR, UPI/PhonePe or Binance details appear only after you create the order. UTR and proof are submitted on that page.</small></span></div>
                    </div>

                    <div data-flow-fields="withdrawal" hidden class="qx-withdraw-destination">
                        <div class="qx-destination-security"><?= icon('shield', 17) ?><span><strong>Check the destination carefully.</strong><small>Approved requests are released to the details entered below.</small></span></div>
                        <label class="field"><span>Account holder name <small>(optional)</small></span><input name="account_name" maxlength="120" autocomplete="name" placeholder="Name on payment account"></label>
                        <label class="field"><span id="withdrawDestinationLabel">UPI ID, account, or wallet address</span><input name="account_identifier" maxlength="190" autocomplete="off" placeholder="Where the withdrawal should be sent"></label>
                    </div>

                    <label class="field"><span>Note <small>(optional)</small></span><textarea name="note" maxlength="500" rows="3" placeholder="Add any detail the administrator should check"></textarea></label>
                    <button class="button button--primary button--wide qx-submit-request" type="submit" id="requestSubmit"><?= $action === 'deposit' ? 'Pay' : 'Submit withdrawal request' ?></button>
                </section>
            </form>
        </article>

        <aside class="wallet-side-stack">
            <article class="panel qx-request-history"><div class="panel-heading"><div><span class="eyebrow">Review status</span><h2>Recent requests</h2></div><a href="#walletLedger">View activity</a></div><div class="request-list">
                <?php if (!$requests): ?><div class="empty-state"><?= icon('request', 26) ?><strong>No requests yet</strong><span>Your submitted requests will appear here.</span></div><?php endif; ?>
                <?php foreach ($requests as $request): ?>
                    <div class="request-row">
                        <span class="transaction-icon transaction-icon--<?= e($request['request_type']) ?>"><?= icon($request['request_type'] === 'deposit' ? 'plus' : 'minus', 18) ?></span>
                        <div><strong><?= e($request['method_name'] ?: ucfirst($request['request_type'])) ?> · <?= e($request['public_id']) ?></strong><small><?= e(date('d M Y, h:i A', strtotime($request['created_at']))) ?><?= $request['user_reference'] ? ' · Ref ' . e($request['user_reference']) : '' ?></small><?php if ((float)$request['fee_amount'] > 0): ?><em>Method fee: <?= e(money($request['fee_amount'], $user['currency'])) ?></em><?php endif; ?><?php if ($request['admin_note']): ?><em><?= e($request['admin_note']) ?></em><?php endif; ?><?php if ($request['provider_reference']): ?><em>Provider ref: <?= e($request['provider_reference']) ?></em><?php endif; ?></div>
                        <span class="request-amount"><?= e(money($request['amount'], $user['currency'])) ?><?php if ((float)$request['bonus_amount'] > 0): ?><small>+<?= e(money($request['bonus_amount'], $user['currency'])) ?> bonus</small><?php endif; ?><b class="status status--<?= e($request['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $request['status']))) ?></b><?php if ($request['status'] === 'awaiting_payment'): ?><a class="proof-link" href="/en/pay/?order=<?= e(rawurlencode($request['public_id'])) ?>">Pay now</a><?php endif; ?><?php if ($request['proof_filename']): ?><a class="proof-link" href="/en/payment-proof/?id=<?= (int)$request['id'] ?>" target="_blank" rel="noopener">View proof</a><?php endif; ?><?php if (in_array($request['status'], ['awaiting_payment', 'pending'], true)): ?><form method="post" class="cancel-request-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_request"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button type="submit">Cancel</button></form><?php endif; ?></span>
                    </div>
                <?php endforeach; ?>
            </div></article>

            <article class="panel gift-card qx-gift-card"><div class="panel-heading"><div><span class="eyebrow">Gift code</span><h2>Redeem wallet credit</h2></div><?= icon('coupon', 23) ?></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="redeem_gift"><label class="field"><span>Code</span><input name="gift_code" minlength="3" maxlength="40" autocomplete="off" placeholder="Enter gift code" required></label><button class="button button--ghost button--wide">Redeem code</button></form></article>

            <div class="security-banner qx-wallet-security"><?= icon('shield', 22) ?><div><strong>Pay only to details inside this account.</strong><p>Never share an OTP, UPI PIN, card PIN, password, private key, or seed phrase.</p></div></div>
        </aside>
    </section>

    <section class="panel ledger-panel" id="walletLedger"><div class="panel-heading"><div><span class="eyebrow">Account activity</span><h2>Wallet ledger</h2></div></div><div class="table-scroll"><table><thead><tr><th>Time</th><th>Activity</th><th>Description</th><th>Amount</th><th>Balance</th></tr></thead><tbody><?php if (!$ledger): ?><tr><td colspan="5" class="table-empty">No wallet activity yet.</td></tr><?php else: foreach ($ledger as $entry): ?><tr><td><?= e(date('d M Y H:i', strtotime($entry['created_at']))) ?></td><td class="ledger-type"><?= e(ucwords(str_replace('_', ' ', $entry['transaction_type']))) ?></td><td><?= e($entry['description'] ?: 'Wallet update') ?></td><td class="<?= (float)$entry['amount'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$entry['amount'] > 0 ? '+' : '' ?><?= e(money($entry['amount'], $user['currency'])) ?></td><td><strong><?= e(money($entry['balance_after'], $user['currency'])) ?></strong></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
</main>
<?php render_public_footer(); render_document_end(['js/account.js']); ?>
