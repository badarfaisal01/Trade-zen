<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once BASE_PATH . '/app/original_frontend.php';

$next = safe_next_path($_GET['next'] ?? $_POST['next'] ?? null, '/en/trade/');
if (current_user()) {
    redirect($next);
}

$error = '';
$success = (string)($_GET['reset'] ?? '') === '1' ? 'Password changed successfully. Sign in with your new password.' : '';
$email = '';
if (is_post()) {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $result = auth_login($email, (string)($_POST['password'] ?? ''));
    if ($result['ok']) {
        redirect($next);
    }
    $error = (string)$result['message'];
}

render_original_auth_template(__DIR__ . '/index.html', $error, $email, $success);
