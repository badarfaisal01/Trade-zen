<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$error = '';
$success = '';
$email = trim((string)($_POST['email'] ?? ''));
if (is_post()) {
    verify_csrf();
    try {
        $result = auth_request_password_reset($email);
        if ($result['ok']) {
            $success = (string)$result['message'];
        } else {
            $error = (string)$result['message'];
        }
    } catch (Throwable $exception) {
        $error = 'Password reset is temporarily unavailable. Please try again later or contact support.';
    }
}

render_document_start('Forgot password', 'auth-page', ['css/auth.css']);
?>
<main class="auth-shell recovery-shell">
    <section class="auth-brand-panel">
        <a class="auth-logo" href="/en/"><?= brand_logo(false) ?></a>
        <div class="auth-brand-copy">
            <span class="eyebrow">Account recovery</span>
            <h1>Reset access securely.</h1>
            <p>Enter the email used for your 55AK Trade account. A one-time link expires after 30 minutes and becomes invalid after use.</p>
        </div>
        <ul class="auth-proof"><li>One-time token</li><li>30-minute expiry</li><li>Existing password stays private</li></ul>
    </section>
    <section class="auth-form-panel">
        <div class="auth-form-wrap recovery-card">
            <a class="auth-mobile-logo" href="/en/"><?= brand_logo(false) ?></a>
            <span class="eyebrow">Password help</span>
            <h2>Forgot your password?</h2>
            <p class="form-intro">We will send a reset link if this email belongs to an active account.</p>
            <?php if ($success !== ''): ?><div class="auth-status auth-status--success"><?= e($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="auth-status auth-status--error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <label class="field"><span>Email address</span><input type="email" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="email" placeholder="you@example.com" required></label>
                <button class="button button--primary button--large button--wide" type="submit">Send reset link</button>
            </form>
            <p class="auth-switch"><a href="/en/sign-in/">Back to sign in</a></p>
            <div class="auth-disclaimer"><?= icon('shield', 18) ?><span>For privacy, the same confirmation is shown whether or not an account exists for the email.</span></div>
        </div>
    </section>
</main>
<?php render_document_end(); ?>
