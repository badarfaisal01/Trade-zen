<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'profile') {
            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')) ?? '');
            $nickname = trim(preg_replace('/\s+/', ' ', (string)($_POST['nickname'] ?? '')) ?? '');
            $phone = trim((string)($_POST['phone'] ?? ''));
            $country = strtoupper(trim((string)($_POST['country'] ?? 'IN')));
            $birth = trim((string)($_POST['date_of_birth'] ?? ''));
            if (strlen($name) < 2 || strlen($name) > 80 || strlen($nickname) > 80 || preg_match('/[\x00-\x1F\x7F]/', $nickname) || !preg_match('/^[A-Z]{2}$/', $country)) {
                throw new InvalidArgumentException('Name, nickname, or country is invalid.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+() .-]{6,30}$/', $phone)) {
                throw new InvalidArgumentException('Enter a valid phone number.');
            }
            $birthValue = null;
            if ($birth !== '') {
                $date = DateTimeImmutable::createFromFormat('Y-m-d', $birth);
                $oldest = new DateTimeImmutable('1900-01-01');
                $adultCutoff = (new DateTimeImmutable('today'))->modify('-18 years');
                if (!$date || $date->format('Y-m-d') !== $birth || $date < $oldest || $date > $adultCutoff) {
                    throw new InvalidArgumentException('Enter a valid date of birth. Account holders must be at least 18.');
                }
                $birthValue = $birth;
            }
            $submitForVerification = (string)($_POST['submit_for_verification'] ?? '0') === '1';
            $currentVerificationStatus = (string)($user['verification_status'] ?? 'unverified');
            if ($submitForVerification && $currentVerificationStatus === 'verified') {
                throw new RuntimeException('This account is already verified.');
            }
            if ($submitForVerification && $currentVerificationStatus === 'pending') {
                throw new RuntimeException('Verification is already pending administrator review.');
            }
            if ($submitForVerification && ($phone === '' || $birthValue === null)) {
                throw new InvalidArgumentException('Add a valid phone number and date of birth before submitting verification.');
            }
            $identityChanged = $name !== (string)$user['name']
                || ($phone ?: null) !== ($user['phone'] ?: null)
                || $country !== (string)$user['country']
                || ($birthValue ?: null) !== ($user['date_of_birth'] ?: null);
            if ($currentVerificationStatus === 'verified' && $identityChanged) {
                throw new RuntimeException('Verified identity details can only be changed through administrator support.');
            }
            $pendingVerificationReset = !$submitForVerification && $currentVerificationStatus === 'pending' && $identityChanged;

            $newAvatar = store_protected_image($_FILES['avatar'] ?? [], 'avatars', 'avatar');
            $oldAvatar = $user['avatar_filename'] ?: null;
            $avatar = $newAvatar ?: $oldAvatar;
            if (isset($_POST['remove_avatar']) && !$newAvatar) {
                $avatar = null;
            }
            try {
                if ($submitForVerification) {
                    $stmt = db()->prepare("UPDATE users SET name=?,nickname=?,phone=?,country=?,date_of_birth=?,avatar_filename=?,verification_status='pending',verification_submitted_at=NOW(),verification_reviewed_at=NULL,verification_note=NULL,updated_at=NOW() WHERE id=?");
                } elseif ($pendingVerificationReset) {
                    $stmt = db()->prepare("UPDATE users SET name=?,nickname=?,phone=?,country=?,date_of_birth=?,avatar_filename=?,verification_status='unverified',verification_submitted_at=NULL,verification_reviewed_at=NULL,verification_note=NULL,updated_at=NOW() WHERE id=?");
                } else {
                    $stmt = db()->prepare('UPDATE users SET name=?,nickname=?,phone=?,country=?,date_of_birth=?,avatar_filename=?,updated_at=NOW() WHERE id=?');
                }
                $stmt->execute([$name, $nickname ?: null, $phone ?: null, $country, $birthValue, $avatar, (int)$user['id']]);
            } catch (Throwable $exception) {
                if ($newAvatar) {
                    remove_protected_image($newAvatar, 'avatars');
                }
                throw $exception;
            }
            if ($oldAvatar && $oldAvatar !== $avatar) {
                remove_protected_image((string)$oldAvatar, 'avatars');
            }
            current_user(true);
            flash('success', $submitForVerification
                ? 'Profile submitted for administrator verification.'
                : ($pendingVerificationReset ? 'Profile updated. Please submit the changed identity details for verification again.' : 'Profile details updated.'));
        } elseif ($action === 'currency_exchange') {
            $quote = exchange_account_currency((int)$user['id'], (string)($_POST['target_currency'] ?? ''));
            flash('success', 'Account currency changed from ' . $quote['fromCurrency'] . ' to ' . $quote['toCurrency'] . '. New Live balance: ' . money($quote['liveAfter'], $quote['toCurrency']) . '.');
        } elseif ($action === 'password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $confirmation = (string)($_POST['password_confirmation'] ?? '');
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
            $stmt->execute([(int)$user['id']]);
            $hash = (string)$stmt->fetchColumn();
            if (!password_verify($currentPassword, $hash)) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                throw new InvalidArgumentException('New password needs at least 10 characters, a letter, and a number.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException('New password confirmation does not match.');
            }
            $update = db()->prepare('UPDATE users SET password_hash=?,force_password_change=0,updated_at=NOW() WHERE id=?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
            session_regenerate_id(true);
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
            flash('success', 'Password changed successfully.');
        } else {
            throw new InvalidArgumentException('Unknown account action.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect('/en/settings/');
}

$user = current_user(true);
$accountType = trade_account_type();
$accountLabel = $accountType === 'demo' ? 'Demo Account' : 'Live Account';
$activeBalance = trade_balance_from_user($user, $accountType);
$statsStmt = db()->prepare("SELECT COUNT(*) trades,COALESCE(SUM(status='won'),0) wins,COALESCE(SUM(profit_loss),0) net FROM trades WHERE user_id=? AND account_type=? AND status<>'open'");
$statsStmt->execute([(int)$user['id'], $accountType]);
$stats = $statsStmt->fetch();
$exchangeRates = currency_exchange_rates();
$exchangeFee = max(0.0, min(25.0, (float)setting('currency_exchange_fee_percent', 3)));
$exchangeEnabled = (bool)setting('currency_exchange_enabled', true) && isset($exchangeRates[(string)$user['currency']]) && count($exchangeRates) > 1;
$exchangeHistoryStmt = db()->prepare('SELECT * FROM currency_exchanges WHERE user_id=? ORDER BY id DESC LIMIT 5');
$exchangeHistoryStmt->execute([(int)$user['id']]);
$exchangeHistory = $exchangeHistoryStmt->fetchAll();
$verificationStatus = in_array((string)($user['verification_status'] ?? ''), ['unverified','pending','verified','rejected'], true) ? (string)$user['verification_status'] : 'unverified';

render_document_start('Account settings', 'account-page black qx-account-shell qx-profile-shell', ['/trade-room/react/app.1e382d4238188d8c0401.css', 'css/account.css', 'css/original-account.css']);
render_cabinet_chrome('settings');
render_flashes();
?>
<main class="account-main profile-page qx-cabinet-page">
    <?php render_account_switcher('profile'); ?>

    <?php if (in_array($verificationStatus, ['unverified','rejected'], true)): ?>
    <section class="qx-reference-modal" data-verification-modal role="dialog" aria-modal="true" aria-labelledby="verificationModalTitle">
        <div class="qx-reference-modal__backdrop"></div>
        <article class="qx-reference-modal__card qx-verification-modal-card">
            <button type="button" class="qx-reference-modal__close" data-verification-later aria-label="Close"><?= icon('x', 19) ?></button>
            <div class="qx-verification-illustration"><?= icon('user', 32) ?><span><?= icon('check', 16) ?></span></div>
            <h2 id="verificationModalTitle">Verify your account</h2>
            <p>Confirm your identity details to unlock the complete account experience and help protect payments.</p>
            <?php if ($verificationStatus === 'rejected' && !empty($user['verification_note'])): ?><div class="qx-verification-note"><?= icon('info', 16) ?><span><?= e($user['verification_note']) ?></span></div><?php endif; ?>
            <button class="button button--primary button--wide" type="button" data-start-verification>Start verification</button>
            <button class="button button--ghost button--wide" type="button" data-verification-later>Later</button>
        </article>
    </section>
    <?php endif; ?>

    <section class="qx-cabinet-banner qx-profile-banner" data-dismissible-banner>
        <span class="qx-cabinet-banner__icon"><?= icon('shield', 21) ?></span>
        <div><strong>Keep your account protected</strong><span>Use a unique password and never share OTP, PIN, password, private key, or seed phrase.</span></div>
        <button class="qx-cabinet-banner__close" type="button" data-dismiss-banner aria-label="Dismiss banner"><?= icon('x', 15) ?></button>
    </section>

    <section class="page-heading qx-page-heading qx-profile-heading">
        <div><span class="eyebrow">Personal profile</span><h1>My account</h1><p>Manage your personal data, profile photo, and sign-in security.</p></div>
        <div class="balance-card"><span><?= e($accountLabel) ?> balance</span><strong><?= e(money($activeBalance, $user['currency'])) ?></strong><small><span class="status-dot"></span><?= e($user['currency']) ?> · current trading account</small></div>
    </section>

    <section class="qx-currency-console" data-currency-console data-current-currency="<?= e($user['currency']) ?>" data-live-balance="<?= e(number_format((float)$user['demo_balance'], 2, '.', '')) ?>" data-demo-balance="<?= e(number_format((float)$user['demo_trade_balance'], 2, '.', '')) ?>" data-exchange-fee="<?= e(number_format($exchangeFee, 3, '.', '')) ?>" data-exchange-rates="<?= e((string)json_encode($exchangeRates, JSON_UNESCAPED_SLASHES)) ?>">
        <div><span class="qx-currency-flag"><?= e($user['currency'] === 'INR' ? '₹' : ($user['currency'] === 'GBP' ? '£' : ($user['currency'] === 'EUR' ? '€' : '$'))) ?></span><span><small>My current currency</small><strong><?= e($user['currency']) ?></strong></span></div>
        <div><small>Live wallet</small><strong><?= e(money((float)$user['demo_balance'], $user['currency'])) ?></strong></div>
        <div><small>Demo wallet</small><strong><?= e(money((float)$user['demo_trade_balance'], $user['currency'])) ?></strong></div>
        <button class="button button--primary button--small" type="button" data-open-exchange <?= !$exchangeEnabled ? 'disabled' : '' ?>>Change</button>
    </section>

    <dialog class="qx-exchange-dialog" id="currencyExchangeDialog" aria-labelledby="currencyExchangeTitle">
        <form method="post" class="qx-exchange-form" data-exchange-form>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="currency_exchange">
            <button type="button" class="qx-reference-modal__close" data-close-exchange aria-label="Close"><?= icon('x', 18) ?></button>
            <h2 id="currencyExchangeTitle">Exchange Form</h2>
            <div class="qx-exchange-selects">
                <label><span>My Currency:</span><b><?= e($user['currency']) ?></b></label>
                <i></i>
                <label><span>New Currency:</span><select name="target_currency" required data-target-currency><?php foreach ($exchangeRates as $code => $rate): if ($code === $user['currency']) continue; ?><option value="<?= e($code) ?>"><?= e($code) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="qx-exchange-preview">
                <span><small>You are exchanging:</small><strong data-exchange-from><?= e(number_format((float)$user['demo_balance'], 2)) ?> <?= e($user['currency']) ?></strong></span>
                <b class="qx-exchange-symbol" data-exchange-source-symbol><?= e(original_currency_symbol($user['currency'])) ?></b>
                <?= icon('arrow-right', 30) ?>
                <b class="qx-exchange-symbol is-target" data-exchange-target-symbol>¤</b>
                <span><small>You will receive:</small><strong data-exchange-to>0.00</strong></span>
            </div>
            <p class="qx-exchange-rate" data-exchange-rate>Operator exchange rate · Fee <?= e(number_format($exchangeFee, 2)) ?>%</p>
            <div class="qx-exchange-warning"><?= icon('info', 16) ?><span>Open trades and active payment orders must be completed first. The Demo wallet is converted at the same ratio without a fee.</span></div>
            <div class="qx-exchange-actions"><button class="button button--primary" type="submit"><?= icon('check', 15) ?> Yes, proceed</button><button class="button button--ghost" type="button" data-close-exchange>No, go back</button></div>
        </form>
    </dialog>

    <section class="stat-grid qx-profile-stats"><article><span>Live balance</span><strong><?= e(money((float)$user['demo_balance'], $user['currency'])) ?></strong><small>Deposits &amp; withdrawals</small></article><article><span>Demo balance</span><strong><?= e(money((float)$user['demo_trade_balance'], $user['currency'])) ?></strong><small>Separate practice wallet</small></article><article><span><?= e($accountLabel) ?> trades</span><strong><?= (int)$stats['trades'] ?></strong><small><?= (int)$stats['wins'] ?> winning · settled</small></article><article><span><?= e($accountLabel) ?> net P/L</span><strong class="<?= (float)$stats['net'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (float)$stats['net'] > 0 ? '+' : '' ?><?= e(money($stats['net'], $user['currency'])) ?></strong><small>Current account only</small></article></section>

    <section class="account-grid settings-account-grid qx-settings-grid">
        <article class="panel request-panel qx-profile-panel">
            <div class="panel-heading"><div><span class="eyebrow">Personal data</span><h2>Profile settings</h2></div><?= icon('user', 23) ?></div>
            <form method="post" enctype="multipart/form-data" id="profileSettingsForm">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="profile">
                <input type="hidden" name="submit_for_verification" value="0" data-submit-verification>

                <div class="form-avatar qx-form-avatar">
                    <div class="form-avatar__image-block">
                        <span class="profile-avatar form-avatar__avatar" data-avatar-preview><?php if ($user['avatar_filename']): ?><img src="/en/avatar/?id=<?= (int)$user['id'] ?>" alt="Profile photo"><?php else: ?><?= e(strtoupper(substr($user['name'], 0, 1))) ?><?php endif; ?></span>
                        <label class="form-avatar__photo" title="Choose profile photo"><?= icon('camera', 18) ?><input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-input hidden></label>
                    </div>
                    <div class="form-avatar__block"><strong class="form-avatar__block-email"><?= e($user['email']) ?></strong><span class="form-avatar__block-text">ID: #<?= (int)$user['id'] ?></span><b class="form-avatar__block-label <?= $verificationStatus === 'verified' ? '' : 'warning' ?>"><?= icon($verificationStatus === 'verified' ? 'check' : 'info', 14) ?><?= e(ucfirst($verificationStatus)) ?></b></div>
                </div>

                <div class="field-grid"><label class="field qx-cabinet-field"><span>Full name</span><input name="name" value="<?= e($user['name']) ?>" minlength="2" maxlength="80" autocomplete="name" required></label><label class="field qx-cabinet-field"><span>Nickname</span><input name="nickname" value="<?= e((string)$user['nickname']) ?>" maxlength="80" autocomplete="nickname" placeholder="Public display name"></label></div>
                <label class="field qx-cabinet-field qx-email-field"><span>Email address</span><input value="<?= e($user['email']) ?>" autocomplete="email" disabled><small>Email changes require administrator support.</small></label>
                <div class="field-grid"><label class="field qx-cabinet-field"><span>Phone</span><input type="tel" name="phone" value="<?= e((string)$user['phone']) ?>" maxlength="30" autocomplete="tel" placeholder="+91 ..."></label><label class="field qx-cabinet-field"><span>Date of birth</span><input type="date" name="date_of_birth" value="<?= e((string)$user['date_of_birth']) ?>" max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>"></label></div>
                <label class="field qx-cabinet-field"><span>Country</span><select name="country" autocomplete="country"><option value="IN" <?= $user['country']==='IN'?'selected':'' ?>>India</option><option value="AE" <?= $user['country']==='AE'?'selected':'' ?>>United Arab Emirates</option><option value="GB" <?= $user['country']==='GB'?'selected':'' ?>>United Kingdom</option><option value="US" <?= $user['country']==='US'?'selected':'' ?>>United States</option><option value="ZZ" <?= $user['country']==='ZZ'?'selected':'' ?>>Other</option></select></label>
                <?php if ($user['avatar_filename']): ?><label class="check-field qx-remove-avatar"><input type="checkbox" name="remove_avatar" value="1"><span>Remove current profile photo</span></label><?php endif; ?>
                <button class="button button--primary qx-profile-save">Save personal data</button>
            </form>
        </article>

        <article class="panel request-panel qx-security-panel">
            <div class="panel-heading"><div><span class="eyebrow">Account security</span><h2>Change password</h2></div><?= icon('shield', 23) ?></div>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password"><label class="field qx-cabinet-field"><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label><label class="field qx-cabinet-field"><span>New password</span><input type="password" name="password" autocomplete="new-password" minlength="10" required><small>At least 10 characters with a letter and number.</small></label><label class="field qx-cabinet-field"><span>Confirm new password</span><input type="password" name="password_confirmation" autocomplete="new-password" minlength="10" required></label><button class="button button--primary">Update password</button><div class="security-mini"><strong>Secure password storage</strong><span>Your password is stored as a one-way secure hash and is verified safely when you sign in.</span></div></form>
            <div class="qx-security-links"><a href="/en/support/"><?= icon('support', 18) ?><span><strong>Need help?</strong><small>Open a support request</small></span><?= icon('arrow-right', 16) ?></a><a href="/en/history/"><?= icon('history', 18) ?><span><strong>Account activity</strong><small>Review your recorded trade history</small></span><?= icon('arrow-right', 16) ?></a></div>
        </article>
    </section>

    <section class="profile-shortcuts qx-profile-shortcuts"><a href="/en/balance/"><?= icon('wallet', 22) ?><span><strong>Payments</strong><small>Deposit, withdraw, gift codes</small></span><?= icon('arrow-right', 16) ?></a><a href="/en/analytics/"><?= icon('activity', 22) ?><span><strong>Analytics</strong><small>Performance and activity</small></span><?= icon('arrow-right', 16) ?></a><a href="/en/support/"><?= icon('support', 22) ?><span><strong>Support</strong><small>Open and track requests</small></span><?= icon('arrow-right', 16) ?></a></section>
</main>
<?php render_public_footer(); render_document_end(['js/account.js']); ?>
