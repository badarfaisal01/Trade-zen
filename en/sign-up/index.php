<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once BASE_PATH . '/app/original_frontend.php';

if (current_user()) {
    redirect('/en/trade/');
}

$error = '';
$email = '';
$submittedReferral = array_key_exists('ref', $_POST) ? (string)$_POST['ref'] : null;
$linkedReferral = array_key_exists('ref', $_GET) ? (string)$_GET['ref'] : null;
$referralCode = normalize_referral_code((string)($submittedReferral ?? $linkedReferral ?? ($_SESSION['pending_referral_code'] ?? '')));
if ($linkedReferral !== null && active_agent_by_code($referralCode)) {
    $_SESSION['pending_referral_code'] = $referralCode;
}
if ($submittedReferral !== null) {
    if ($referralCode === '') {
        unset($_SESSION['pending_referral_code']);
    } else {
        $_SESSION['pending_referral_code'] = $referralCode;
    }
}
if (is_post()) {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    if (!isset($_POST['rules'])) {
        $error = 'Please confirm that you are at least 18 and accept the platform terms.';
    } else {
        $localPart = strstr($email, '@', true);
        $name = trim((string)$localPart) !== '' ? ucwords(str_replace(['.', '_', '-'], ' ', (string)$localPart)) : 'Account Holder';
        $result = auth_register($name, $email, (string)($_POST['password'] ?? ''), (string)($_POST['country-code'] ?? 'IN'), $referralCode);
        if ($result['ok']) {
            unset($_SESSION['pending_referral_code']);
            redirect('/en/trade/');
        }
        $error = (string)$result['message'];
    }
}

render_original_auth_template(__DIR__ . '/index.html', $error, $email, '', $referralCode);
