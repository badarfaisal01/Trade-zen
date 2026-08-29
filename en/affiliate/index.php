<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
$agent = ensure_user_affiliate($user);

$stats = [
    'referrals' => 0,
    'qualified_referrals' => 0,
    'pending_referrals' => 0,
    'earned_bonus' => 0.0,
    'live_volume' => 0.0,
];
$statsStmt = db()->prepare("SELECT COUNT(r.id) referrals,SUM(CASE WHEN r.reward_status='credited' THEN 1 ELSE 0 END) qualified_referrals,SUM(CASE WHEN r.reward_status='pending' THEN 1 ELSE 0 END) pending_referrals,COALESCE(SUM(r.reward_amount),0) earned_bonus,COALESCE(SUM(tv.live_volume),0) live_volume FROM agent_referrals r LEFT JOIN (SELECT user_id,SUM(amount) live_volume FROM trades WHERE account_type='live' GROUP BY user_id) tv ON tv.user_id=r.user_id WHERE r.agent_id=?");
$statsStmt->execute([(int)$agent['id']]);
$stats = array_merge($stats, $statsStmt->fetch() ?: []);

$recentStmt = db()->prepare("SELECT r.created_at,r.reward_status,r.reward_amount,r.credited_at,u.name,u.email,COALESCE(tv.trade_count,0) trade_count,COALESCE(tv.live_volume,0) live_volume,COALESCE(fd.approved_deposits,0) approved_deposits FROM agent_referrals r JOIN users u ON u.id=r.user_id LEFT JOIN (SELECT user_id,COUNT(*) trade_count,SUM(amount) live_volume FROM trades WHERE account_type='live' GROUP BY user_id) tv ON tv.user_id=r.user_id LEFT JOIN (SELECT user_id,SUM(amount) approved_deposits FROM fund_requests WHERE request_type='deposit' AND status IN ('approved','paid') GROUP BY user_id) fd ON fd.user_id=r.user_id WHERE r.agent_id=? ORDER BY r.id DESC LIMIT 50");
$recentStmt->execute([(int)$agent['id']]);
$recent = $recentStmt->fetchAll();

$domain = rtrim((string)setting('platform_domain', app_config('url')), '/');
$referralCode = (string)$agent['referral_code'];
$referralUrl = $domain . '/en/sign-up/?ref=' . rawurlencode($referralCode);
$rewardEnabled = (bool)setting('referral_program_enabled', true);
$rewardAmount = max(0.0, (float)setting('referral_reward_amount', 5));
$minimumDeposit = max(0.01, (float)setting('referral_minimum_deposit', 10));
$agentActive = $agent['status'] === 'active';

render_document_start('Affiliate', 'account-page black qx-account-shell qx-affiliate-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('affiliate');
render_flashes();
?>
<main class="account-main affiliate-page qx-cabinet-page">
    <?php render_account_switcher('affiliate'); ?>
    <section class="page-heading qx-page-heading">
        <div><span class="eyebrow">Refer &amp; earn</span><h1>Affiliate program</h1><p>Your personal code is active automatically. Share it and track invited users and qualifying rewards.</p></div>
        <a class="button button--primary" href="/en/trade/">Open trade room</a>
    </section>

    <?php if ($agentActive): ?>
        <section class="affiliate-workspace panel">
            <div class="affiliate-workspace__copy">
                <span class="affiliate-hero__icon"><?= icon('user-plus', 32) ?></span>
                <div><span class="eyebrow">Your affiliate link</span><h2><?= e($referralCode) ?></h2><p>A new account registered with this link or code is attributed to you automatically.</p></div>
                <div class="affiliate-link-box"><code><?= e($referralUrl) ?></code></div>
                <div class="affiliate-actions">
                    <button class="button button--primary" type="button" data-affiliate-copy="<?= e($referralUrl) ?>">Copy link</button>
                    <button class="button button--ghost" type="button" data-affiliate-copy="<?= e($referralCode) ?>">Copy code</button>
                    <button class="button button--ghost" type="button" data-affiliate-share data-share-url="<?= e($referralUrl) ?>" data-share-code="<?= e($referralCode) ?>">Share</button>
                    <button class="button button--ghost" type="button" data-affiliate-qr-download>Save QR</button>
                </div>
            </div>
            <figure class="affiliate-qr-card">
                <div data-affiliate-qr data-qr-value="<?= e($referralUrl) ?>" aria-label="QR code for your affiliate link"></div>
                <figcaption><strong>Scan to register</strong><span><?= e($referralCode) ?></span></figcaption>
            </figure>
        </section>

        <section class="stat-grid affiliate-stats">
            <article><span>Invited users</span><strong><?= number_format((int)$stats['referrals']) ?></strong><small>Accounts registered with your code</small></article>
            <article><span>Qualified</span><strong><?= number_format((int)$stats['qualified_referrals']) ?></strong><small>First approved deposit met the minimum</small></article>
            <article><span>Bonus earned</span><strong class="amount-positive"><?= e(money((float)$stats['earned_bonus'], $user['currency'])) ?></strong><small>Credited to your Live balance</small></article>
            <article><span>Current reward</span><strong><?= $rewardEnabled ? e(money($rewardAmount, $user['currency'])) : 'Paused' ?></strong><small>Minimum approved deposit <?= e(money($minimumDeposit, $user['currency'])) ?></small></article>
        </section>

        <section class="panel affiliate-referral-list">
            <div class="panel-heading"><div><span class="eyebrow">Referral activity</span><h2>Invited users</h2></div><span class="status status--active"><?= number_format((int)$stats['pending_referrals']) ?> waiting</span></div>
            <div class="table-scroll"><table><thead><tr><th>User</th><th>Joined</th><th>Approved deposits</th><th>Live trades</th><th>Reward</th></tr></thead><tbody>
            <?php if (!$recent): ?><tr><td colspan="5" class="table-empty">No invited users yet. Share your link, QR, or code above.</td></tr><?php endif; ?>
            <?php foreach ($recent as $row):
                $isCredited = $row['reward_status'] === 'credited';
                $statusText = $isCredited ? 'Qualified' : ($row['reward_status'] === 'cancelled' ? 'Cancelled' : 'Waiting for deposit');
            ?>
                <tr>
                    <td><strong><?= e(mask_referral_name((string)$row['name'])) ?></strong><small><?= e(mask_referral_email((string)$row['email'])) ?></small></td>
                    <td><?= e(date('d M Y H:i', strtotime((string)$row['created_at']))) ?></td>
                    <td><?= e(money((float)$row['approved_deposits'], $user['currency'])) ?></td>
                    <td><?= number_format((int)$row['trade_count']) ?><small><?= e(money((float)$row['live_volume'], $user['currency'])) ?> volume</small></td>
                    <td><span class="status status--<?= $isCredited ? 'active' : 'open' ?>"><?= e($statusText) ?></span><?php if ($isCredited): ?><small class="amount-positive">+<?= e(money((float)$row['reward_amount'], $user['currency'])) ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php else: ?>
        <section class="panel affiliate-unavailable">
            <span><?= icon('shield', 38) ?></span>
            <div><span class="eyebrow">Affiliate paused</span><h2>This referral code is blocked</h2><p>Affiliate access is automatic and does not need approval. This specific code was blocked by an administrator; contact Support if you believe that was a mistake.</p><a class="button button--primary" href="/en/support/">Contact support</a></div>
        </section>
    <?php endif; ?>

    <div class="security-banner"><?= icon('shield', 22) ?><div><strong>Use honest referral information.</strong><p>Rewards are credited once after the invited user's first qualifying approved deposit. Never ask anyone for passwords, OTPs, UPI PINs, private keys, or funds.</p></div></div>
</main>
<?php render_public_footer(); render_document_end(['js/qrcode-matrix.js', 'js/account.js', 'js/affiliate.js']); ?>
