<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$existing = current_user();
if ($existing && is_admin($existing)) {
    redirect('/admin/');
}

$error = '';
$email = '';
if (is_post()) {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $result = auth_login($email, (string)($_POST['password'] ?? ''));
    if ($result['ok'] && is_admin($result['user'])) {
        redirect('/admin/');
    }
    if ($result['ok']) {
        auth_logout(true);
        $error = 'This account does not have administrator access.';
    } else {
        $error = (string)$result['message'];
    }
}

render_document_start('Admin login', 'auth-page admin-login-page', ['css/auth.css', 'css/admin.css']);
?>
<main class="auth-shell admin-auth-shell">
    <section class="auth-brand-panel admin-auth-brand">
        <a class="auth-logo" href="/en/"><?= brand_logo(false) ?></a>
        <div class="auth-brand-copy"><span class="eyebrow">Control center</span><h1>Operate with visibility, not guesswork.</h1><p>Review activity, manage accounts and finance requests, configure markets, and trace every administrative change.</p></div>
        <ul class="auth-proof"><li>Role-based access</li><li>Administrator audit log</li><li>No outcome override</li></ul>
    </section>
    <section class="auth-form-panel">
        <div class="auth-form-wrap"><a class="auth-mobile-logo" href="/en/"><?= brand_logo(false) ?></a><span class="eyebrow">Restricted area</span><h2>Administrator login</h2><p class="form-intro">Use the account created by the installer.</p><?php if ($error): ?><div class="form-errors"><div><?= e($error) ?></div></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><label class="field"><span>Admin email</span><input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></label><label class="field"><span>Password</span><span class="password-field"><input type="password" name="password" autocomplete="current-password" required><button class="password-toggle" type="button" aria-label="Show password"><?= icon('eye', 19) ?></button></span></label><button class="button button--primary button--wide" type="submit">Open control center</button></form><p class="auth-disclaimer"><?= icon('shield', 16) ?> Administrator actions are recorded with time, account, and IP address.</p></div>
    </section>
</main>
<?php render_document_end(['js/auth.js']); ?>
