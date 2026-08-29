<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

if (is_post()) {
    verify_csrf();
    $code = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
    try {
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            throw new InvalidArgumentException('Enter a valid promo code.');
        }
        $stmt = db()->prepare("SELECT code,code_type FROM promo_codes WHERE code=? AND is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) LIMIT 1");
        $stmt->execute([$code]);
        $promo = $stmt->fetch();
        if (!$promo) throw new RuntimeException('Promo code is invalid or inactive.');
        if ($promo['code_type'] === 'gift') {
            $credit = redeem_gift_code((int)$user['id'], $code);
            flash('success', 'Promo applied. ' . money($credit, $user['currency']) . ' was added to the Live wallet.');
            redirect('/en/market/');
        }
        flash('success', 'Deposit bonus selected. Choose a method and complete the deposit order.');
        redirect('/en/balance/?deposit=true&promo=' . rawurlencode($code));
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('/en/market/');
    }
}

$promos = db()->query("SELECT id,code,title,code_type,value_type,value_amount,minimum_amount,maximum_bonus,usage_limit,usage_count,starts_at,expires_at FROM promo_codes WHERE is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) ORDER BY code_type,id DESC")->fetchAll();
$giftPromos = array_values(array_filter($promos, static fn(array $promo): bool => $promo['code_type'] === 'gift'));
$depositPromos = array_values(array_filter($promos, static fn(array $promo): bool => $promo['code_type'] === 'deposit_bonus'));
$redeemedStmt = db()->prepare("SELECT p.code,p.title,r.credit_amount,r.created_at FROM promo_redemptions r JOIN promo_codes p ON p.id=r.promo_code_id WHERE r.user_id=? AND r.status='credited' ORDER BY r.id DESC LIMIT 12");
$redeemedStmt->execute([(int)$user['id']]);
$redeemed = $redeemedStmt->fetchAll();

render_document_start('Market', 'account-page black qx-account-shell qx-market-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('account');
render_flashes();
?>
<main class="account-main qx-cabinet-page qx-market-page">
    <?php render_account_switcher('market'); ?>
    <section class="page-heading qx-page-heading"><div><span class="eyebrow">Rewards market</span><h1>Promo codes &amp; benefits</h1><p>Review active wallet gifts and deposit bonuses configured by the administrator.</p></div><a class="button button--primary" href="/en/balance/?deposit=true"><?= icon('plus', 16) ?> Deposit</a></section>
    <section class="qx-promo-grid">
        <?php
        $cards = [
            ['risk','shield','Risk Free',$giftPromos,'Gift wallet credits'],
            ['cashback','activity','Cashback',[],'No cashback campaign is active'],
            ['deposit','wallet','Deposit Bonus',$depositPromos,'Applied during a deposit order'],
            ['turnover','chart','Percentage of turnover',[],'No turnover campaign is active'],
        ];
        foreach ($cards as [$tone,$iconName,$title,$items,$emptyText]):
        ?>
        <article class="qx-promo-card is-<?= e($tone) ?>">
            <header><span><?= icon($iconName, 25) ?></span><div><h2><?= e($title) ?></h2><small><?= count($items) ?> promo code<?= count($items)===1?'':'s' ?> available</small></div><?= icon('support', 18) ?></header>
            <div class="qx-promo-card__head"><span>Promo code</span><span>Status</span><span>Using</span></div>
            <div class="qx-promo-card__body">
                <?php if(!$items): ?><div class="qx-promo-empty"><?= icon('request', 28) ?><p><?= e($emptyText) ?>. You can enter a code using the button below.</p></div><?php endif; ?>
                <?php foreach($items as $promo): ?><div class="qx-promo-row"><span><strong><?= e($promo['code']) ?></strong><small><?= e($promo['title']) ?></small></span><b>Active</b><em><?= $promo['value_type']==='percent'?e(number_format((float)$promo['value_amount'],0)).'%':e(money($promo['value_amount'],$user['currency'])) ?></em></div><?php endforeach; ?>
            </div>
            <footer><button type="button" data-show-promo-list><?= icon('clock', 15) ?> Show all</button><button class="button button--primary button--small" type="button" data-open-promo-code>Enter promo code</button></footer>
        </article>
        <?php endforeach; ?>
    </section>
    <?php if($redeemed): ?><section class="panel qx-promo-history"><div class="panel-heading"><div><span class="eyebrow">Account history</span><h2>Used promo codes</h2></div></div><div class="request-list"><?php foreach($redeemed as $row): ?><div class="request-row"><span class="transaction-icon transaction-icon--deposit"><?= icon('coupon',17) ?></span><div><strong><?= e($row['code']) ?> · <?= e($row['title']) ?></strong><small><?= e(date('d M Y H:i',strtotime($row['created_at']))) ?></small></div><b class="amount-positive">+<?= e(money($row['credit_amount'],$user['currency'])) ?></b></div><?php endforeach; ?></div></section><?php endif; ?>
    <dialog class="qx-promo-dialog" data-promo-dialog><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button type="button" data-close-promo aria-label="Close"><?= icon('x',18) ?></button><span class="qx-promo-dialog__icon"><?= icon('coupon',26) ?></span><h2>Enter promo code</h2><p>Gift codes credit the Live wallet immediately. Deposit-bonus codes continue to the secure Deposit flow.</p><label class="field"><span>Promo code</span><input name="promo_code" minlength="3" maxlength="40" autocomplete="off" required autofocus></label><button class="button button--primary button--wide">Apply code</button></form></dialog>
</main>
<?php render_public_footer(); render_document_end(['js/account.js']); ?>
