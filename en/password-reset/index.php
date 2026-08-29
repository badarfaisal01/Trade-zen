<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$error = '';
$record = null;
try {
    $record = auth_password_reset_record($token);
} catch (Throwable $ignored) {
    $record = null;
}

if (is_post()) {
    verify_csrf();
    try {
        $result = auth_reset_password($token, (string)($_POST['password'] ?? ''), (string)($_POST['password_confirmation'] ?? ''));
        if ($result['ok']) {
            header('Location: /en/sign-in/?reset=1');
            exit;
        }
        $error = (string)$result['message'];
        $record = auth_password_reset_record($token);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $record = auth_password_reset_record($token);
    }
}

render_document_start('Reset password', 'auth-page', ['css/auth.css']);
?>
<main class="auth-shell recovery-shell">
    <section class="auth-brand-panel">
        <a class="auth-logo" href="/en/"><?= brand_logo(false) ?></a>
        <div class="auth-brand-copy">
            <span class="eyebrow">Secure reset</span>
            <h1>Create a new password.</h1>
            <p>Choose a unique password with at least 10 characters, including a letter and a number. The reset link can only be used once.</p>
        </div>
        <ul class="auth-proof"><li>Hashed reset token</li><li>One-time use</li><li>Other reset links invalidated</li></ul>
    </section>
    <section class="auth-form-panel">
        <div class="auth-form-wrap recovery-card">
            <a class="auth-mobile-logo" href="/en/"><?= brand_logo(false) ?></a>
            <span class="eyebrow">Account security</span>
            <h2>Reset password</h2>
            <?php if ($record): ?>
                <p class="form-intro">Resetting access for <strong><?= e((string)$record['email']) ?></strong>.</p>
                <?php if ($error !== ''): ?><div class="auth-status auth-status--error"><?= e($error) ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <label class="field"><span>New password</span><input type="password" name="password" minlength="10" maxlength="255" autocomplete="new-password" required><small>At least 10 characters with a letter and number.</small></label>
                    <label class="field"><span>Confirm new password</span><input type="password" name="password_confirmation" minlength="10" maxlength="255" autocomplete="new-password" required></label>
                    <button class="button button--primary button--large button--wide" type="submit">Change password</button>
                </form>
            <?php else: ?>
                <div class="auth-status auth-status--error">This reset link is invalid or has expired.</div>
                <p class="form-intro">Request a new password reset link to continue.</p>
                <a class="button button--primary button--large button--wide" href="/en/password-recovery/">Request new link</a>
            <?php endif; ?>
            <p class="auth-switch"><a href="/en/sign-in/">Back to sign in</a></p>
        </div>
    </section>
</main>
<?php render_document_end(); ?>
