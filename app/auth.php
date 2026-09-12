<?php
declare(strict_types=1);

function current_user(bool $fresh = false): ?array
{
    static $cached = null;
    static $loaded = false;

    if ($fresh) {
        $loaded = false;
        $cached = null;
    }

    if ($loaded) {
        return $cached;
    }

    $loaded = true;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        $userId = (int)(restore_trade_api_session() ?? 0);
    }
    if ($userId < 1) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, status, demo_balance, demo_trade_balance, trade_account_type, currency, country, phone, nickname, date_of_birth, avatar_filename, email_verified_at, onboarding_completed_at, verification_status, verification_submitted_at, verification_reviewed_at, verification_note, force_password_change, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        auth_logout(false);
        return null;
    }

    $cached = $user;
    return $cached;
}

function auth_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        return ['ok' => false, 'message' => 'Enter a valid email and password.'];
    }

    if (too_many_login_attempts($email) || !session_rate_limit('login:' . $email, 10, 900)) {
        return ['ok' => false, 'message' => 'Too many attempts. Please wait 15 minutes and try again.'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $envAdminEmail = strtolower(trim((string)(getenv('ADMIN_EMAIL') ?: ($_ENV['ADMIN_EMAIL'] ?? ''))));
    $envAdminPass = (string)(getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? ''));

    if ($envAdminEmail !== '' && $envAdminPass !== '' && $email === $envAdminEmail && hash_equals($envAdminPass, $password)) {
        if (!$user) {
            $hash = password_hash($envAdminPass, PASSWORD_DEFAULT);
            $insert = db()->prepare("INSERT INTO users (name, nickname, email, password_hash, role, status, demo_balance, demo_trade_balance, currency, force_password_change, created_at, updated_at) VALUES ('Administrator', 'Admin', ?, ?, 'super_admin', 'active', 0.00, 1000.00, 'USD', 0, NOW(), NOW())");
            $insert->execute([$envAdminEmail, $hash]);
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } else {
            $hash = password_hash($envAdminPass, PASSWORD_DEFAULT);
            $role = in_array($user['role'], ['admin', 'super_admin', 'manager'], true) ? $user['role'] : 'super_admin';
            db()->prepare("UPDATE users SET password_hash = ?, role = ?, status = 'active', force_password_change = 0, updated_at = NOW() WHERE id = ?")
                ->execute([$hash, $role, (int)$user['id']]);
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        }
    }

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        record_login_attempt($email, false);
        return ['ok' => false, 'message' => 'Email or password is incorrect.'];
    }

    if ($user['status'] !== 'active') {
        record_login_attempt($email, false);
        return ['ok' => false, 'message' => 'This account is not active. Contact support.'];
    }

    record_login_attempt($email, true);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['_created_at'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['trade_account'] = ($user['trade_account_type'] ?? 'live') === 'demo' ? 'demo' : 'live';

    // Login must not fail just because an older database is missing an
    // optional last-login metadata column. The migration repairs that schema,
    // while this fallback keeps the authenticated session usable immediately.
    $newPasswordHash = password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)
        ? password_hash($password, PASSWORD_DEFAULT)
        : null;
    try {
        if ($newPasswordHash !== null) {
            $update = db()->prepare('UPDATE users SET password_hash = ?, last_login_at = NOW(), last_login_ip = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([$newPasswordHash, client_ip(), (int)$user['id']]);
        } else {
            $update = db()->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([client_ip(), (int)$user['id']]);
        }
    } catch (Throwable $ignored) {
        if ($newPasswordHash !== null) {
            try {
                db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$newPasswordHash, (int)$user['id']]);
            } catch (Throwable $rehashIgnored) {
                // A metadata/rehash write must never turn valid credentials
                // into an application error. The secure session is still valid.
            }
        }
    }

    return ['ok' => true, 'user' => current_user(true)];
}

function auth_register(string $name, string $email, string $password, string $country = 'IN', string $referralCode = ''): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    $email = strtolower(trim($email));
    $country = strtoupper(trim($country));

    if (strlen($name) < 2 || strlen($name) > 80) {
        return ['ok' => false, 'message' => 'Name must be between 2 and 80 characters.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }
    if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return ['ok' => false, 'message' => 'Password must be at least 10 characters and include a letter and number.'];
    }
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        $country = 'IN';
    }
    if (!session_rate_limit('register:' . client_ip(), 5, 3600)) {
        return ['ok' => false, 'message' => 'Too many accounts were created from this connection. Try again later.'];
    }

    $agent = null;
    if (trim($referralCode) !== '') {
        $agent = active_agent_by_code($referralCode);
        if (!$agent) {
            return ['ok' => false, 'message' => 'The referral code is invalid or inactive.'];
        }
        if (strtolower((string)$agent['email']) === $email) {
            return ['ok' => false, 'message' => 'You cannot register with your own referral code.'];
        }
    }

    $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        return ['ok' => false, 'message' => 'An account with this email already exists.'];
    }

    $startingBalance = (float)setting('demo_starting_balance', app_config('demo_starting_balance'));
    $practiceBalance = (float)setting('practice_starting_balance', app_config('practice_starting_balance'));
    $currency = strtoupper((string)setting('wallet_currency', 'USD'));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = 'USD';
    }

    try {
        $userId = db_transaction(function (PDO $pdo) use ($name, $email, $password, $country, $startingBalance, $practiceBalance, $currency, $agent): int {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status, demo_balance, demo_trade_balance, trade_account_type, currency, country, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'user', 'active', $startingBalance, $practiceBalance, 'live', $currency, $country]);
            $userId = (int)$pdo->lastInsertId();

            if ($agent) {
                attach_user_referral($pdo, (int)$agent['id'], $userId);
            }

            // Affiliate is an automatic account feature. New users can share
            // their own code immediately; no administrator approval is needed.
            ensure_user_affiliate(['id' => $userId, 'name' => $name, 'email' => $email], $pdo);

            if ($startingBalance > 0) {
                $ledger = $pdo->prepare('INSERT INTO wallet_transactions (user_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $ledger->execute([$userId, 'signup_bonus', $startingBalance, 0, $startingBalance, 'system', null, 'Opening balance']);
            }
            return $userId;
        });
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() === '23000') {
            return ['ok' => false, 'message' => 'An account with this email already exists.'];
        }
        throw $exception;
    }

    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['_created_at'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['trade_account'] = 'live';

    // Dispatch Welcome Email via Hostinger SMTP (non-blocking for registration flow)
    try {
        send_welcome_email([
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'currency' => $currency,
            'demo_balance' => $startingBalance,
            'practice_balance' => $practiceBalance,
        ]);
    } catch (Throwable $mailException) {
        mailer_log("Welcome email dispatch error for user #{$userId}: " . $mailException->getMessage());
    }

    return ['ok' => true, 'user' => current_user(true)];
}

function auth_request_password_reset(string $email): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }
    if (!session_rate_limit('password-reset:' . client_ip(), 6, 3600) || !session_rate_limit('password-reset-email:' . hash('sha256', $email), 4, 3600)) {
        return ['ok' => false, 'message' => 'Too many reset requests. Please wait and try again later.'];
    }

    $generic = 'If an active account exists for this email, a password reset link has been sent.';
    $stmt = db()->prepare("SELECT id,email,name,status FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || (string)$user['status'] !== 'active') {
        return ['ok' => true, 'message' => $generic];
    }

    // Limit persistent requests as well as the session bucket so opening a new
    // browser session cannot generate an unlimited number of reset emails.
    $rate = db()->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE (user_id=? OR requested_ip=?) AND created_at >= (NOW() - INTERVAL 1 HOUR)');
    $rate->execute([(int)$user['id'], client_ip()]);
    if ((int)$rate->fetchColumn() >= 8) {
        return ['ok' => false, 'message' => 'Too many reset requests. Please wait and try again later.'];
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    db_transaction(function (PDO $pdo) use ($user, $tokenHash): void {
        $expireOld = $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
        $expireOld->execute([(int)$user['id']]);
        $insert = $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,requested_ip,expires_at,created_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE),NOW())');
        $insert->execute([(int)$user['id'], $tokenHash, client_ip()]);
    });

    $baseUrl = rtrim((string)app_config('url'), '/');
    if ($baseUrl === '' || strpos($baseUrl, '55ak.xyz') !== false) {
        $baseUrl = (is_https() ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $resetUrl = $baseUrl . '/en/password-reset/?token=' . rawurlencode($rawToken);
    
    $sent = false;
    try {
        $sent = send_password_reset_email($user, $resetUrl);
    } catch (Throwable $mailError) {
        mailer_log("Password reset exception: " . $mailError->getMessage());
        $sent = false;
    }
    if (!$sent) {
        mailer_log(sprintf("Password reset email delivery failed for user #%d.", (int)$user['id']));
    }

    return ['ok' => true, 'message' => $generic];
}

function auth_password_reset_record(string $rawToken): ?array
{
    $rawToken = strtolower(trim($rawToken));
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }
    $stmt = db()->prepare("SELECT r.id reset_id,r.user_id,r.expires_at,u.email,u.name,u.status FROM password_reset_tokens r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>NOW() LIMIT 1");
    $stmt->execute([hash('sha256', $rawToken)]);
    $record = $stmt->fetch();
    return $record && (string)$record['status'] === 'active' ? $record : null;
}

function auth_reset_password(string $rawToken, string $password, string $confirmation): array
{
    if ($password !== $confirmation) {
        return ['ok' => false, 'message' => 'The password confirmation does not match.'];
    }
    if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return ['ok' => false, 'message' => 'Password must be at least 10 characters and include a letter and number.'];
    }
    $record = auth_password_reset_record($rawToken);
    if (!$record) {
        return ['ok' => false, 'message' => 'This reset link is invalid or has expired. Request a new link.'];
    }

    $tokenHash = hash('sha256', strtolower(trim($rawToken)));
    db_transaction(function (PDO $pdo) use ($record, $tokenHash, $password): void {
        // Lock and verify again inside the transaction so the same one-time link
        // cannot be consumed by two concurrent requests.
        $check = $pdo->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1 FOR UPDATE');
        $check->execute([$tokenHash]);
        $token = $check->fetch();
        if (!$token || (int)$token['user_id'] !== (int)$record['user_id']) {
            throw new RuntimeException('This reset link is invalid or has expired. Request a new link.');
        }
        $update = $pdo->prepare('UPDATE users SET password_hash=?,force_password_change=0,updated_at=NOW() WHERE id=? AND status=\'active\'');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), (int)$record['user_id']]);
        if ($update->rowCount() < 1) {
            throw new RuntimeException('The account is not available for password reset.');
        }
        $consume = $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
        $consume->execute([(int)$record['user_id']]);
        $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE email_hash=?');
        $clearAttempts->execute([hash('sha256', strtolower((string)$record['email']))]);
    });

    auth_logout(false);
    return ['ok' => true, 'message' => 'Your password has been changed. You can sign in with the new password.'];
}

function auth_logout(bool $regenerate = true): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if ($regenerate) {
        session_start();
        session_regenerate_id(true);
    }
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        if (request_expects_json()) {
            json_response(['ok' => false, 'message' => 'Sign in is required.', 'code' => 'AUTH_REQUIRED', 'redirect' => '/en/sign-in/'], 401);
        }
        $next = (string)($_SERVER['REQUEST_URI'] ?? '/en/trade/');
        redirect('/en/sign-in/?next=' . rawurlencode($next));
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if (!in_array($user['role'], ['manager', 'admin', 'super_admin'], true)) {
        if (request_expects_json()) {
            json_response(['ok' => false, 'message' => 'Administrator access required.'], 403);
        }
        http_response_code(403);
        exit('Administrator access required.');
    }
    if ((bool)$user['force_password_change']) {
        flash('warning', 'Change the temporary administrator password before opening the control center.');
        redirect('/en/settings/?password=required');
    }
    return $user;
}

function is_admin(?array $user): bool
{
    return $user !== null && in_array($user['role'], ['manager', 'admin', 'super_admin'], true);
}

function admin_can(array $user, string $capability): bool
{
    $role = (string)$user['role'];
    $matrix = [
        'view' => ['manager', 'admin', 'super_admin'],
        'review_requests' => ['manager', 'admin', 'super_admin'],
        'manage_payments' => ['admin', 'super_admin'],
        'manage_promos' => ['admin', 'super_admin'],
        'manage_support' => ['manager', 'admin', 'super_admin'],
        'manage_users' => ['admin', 'super_admin'],
        'manage_markets' => ['admin', 'super_admin'],
        'manage_settings' => ['admin', 'super_admin'],
        'manage_admins' => ['super_admin'],
        'manage_agents' => ['admin', 'super_admin'],
        'manage_notifications' => ['admin', 'super_admin'],
        'manage_branding' => ['admin', 'super_admin'],
    ];
    return isset($matrix[$capability]) && in_array($role, $matrix[$capability], true);
}

function require_admin_capability(array $user, string $capability): void
{
    if (!admin_can($user, $capability)) {
        throw new RuntimeException('Your administrator role does not allow this action.');
    }
}

function admin_audit(int $adminId, string $action, string $targetType, ?int $targetId = null, array $details = []): void
{
    $stmt = db()->prepare('INSERT INTO admin_audit_logs (admin_id, action, target_type, target_id, details_json, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $adminId,
        $action,
        $targetType,
        $targetId,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        client_ip(),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}
