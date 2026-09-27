<?php
declare(strict_types=1);

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https: blob:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self' ws: wss: https: blob: data:; font-src 'self' data: https:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function start_secure_session(array $app): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_trans_sid', '0');
    session_name((string)$app['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!isset($_SESSION['_created_at'])) {
        session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
    } elseif ((time() - (int)$_SESSION['_created_at']) > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
    }
}

function trade_token_base64_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function trade_token_base64_decode(string $value): ?string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return null;
    }
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function trade_api_user_agent_hash(): string
{
    return substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 24);
}

function trade_api_signing_material(int $userId): ?array
{
    if ($userId < 1) {
        return null;
    }
    $stmt = db()->prepare("SELECT id,password_hash,status,trade_account_type FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function trade_api_token(array $user): string
{
    $userId = (int)($user['id'] ?? 0);
    $material = trade_api_signing_material($userId);
    if (!$material || (string)$material['status'] !== 'active') {
        throw new RuntimeException('The signed-in account is not available.');
    }

    $payload = trade_token_base64_encode((string)json_encode([
        'v' => 1,
        'uid' => $userId,
        'iat' => time(),
        'exp' => time() + 43200,
        'csrf' => hash('sha256', csrf_token()),
        'ua' => trade_api_user_agent_hash(),
    ], JSON_UNESCAPED_SLASHES));
    $key = hash('sha256', (string)$material['password_hash'] . '|' . (string)app_config('url') . '|trade-api-v1', true);
    $signature = trade_token_base64_encode(hash_hmac('sha256', $payload, $key, true));
    return $payload . '.' . $signature;
}

function is_trade_api_request(): bool
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    return strpos($path, '/en/api/') === 0;
}

function restore_trade_api_session(): ?int
{
    if (!is_trade_api_request()) {
        return null;
    }
    $token = trim((string)($_SERVER['HTTP_X_TRADE_AUTH'] ?? ''));
    $csrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($token === '' || $csrf === '' || strlen($token) > 1200 || strlen($csrf) !== 64) {
        return null;
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    $payloadJson = trade_token_base64_decode($parts[0]);
    $signature = trade_token_base64_decode($parts[1]);
    if ($payloadJson === null || $signature === null) {
        return null;
    }
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) {
        return null;
    }
    $userId = (int)($payload['uid'] ?? 0);
    $issuedAt = (int)($payload['iat'] ?? 0);
    $expiresAt = (int)($payload['exp'] ?? 0);
    $now = time();
    if ($userId < 1 || $issuedAt > ($now + 60) || $expiresAt < ($now - 30) || $expiresAt > ($now + 43320)) {
        return null;
    }
    if (!hash_equals((string)($payload['csrf'] ?? ''), hash('sha256', $csrf)) || !hash_equals((string)($payload['ua'] ?? ''), trade_api_user_agent_hash())) {
        return null;
    }
    $material = trade_api_signing_material($userId);
    if (!$material || (string)$material['status'] !== 'active') {
        return null;
    }
    $key = hash('sha256', (string)$material['password_hash'] . '|' . (string)app_config('url') . '|trade-api-v1', true);
    $expected = hash_hmac('sha256', $parts[0], $key, true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['_created_at'] = $now;
    $_SESSION['_csrf'] = $csrf;
    $_SESSION['trade_account'] = (string)$material['trade_account_type'] === 'demo' ? 'demo' : 'live';
    return $userId;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['_csrf'];
}

function verify_csrf(?string $token = null): void
{
    if ($token === null) {
        $token = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    }

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        if (request_expects_json()) {
            json_response(['ok' => false, 'message' => 'Security token expired. Refresh the page and try again.'], 419);
        }
        http_response_code(419);
        exit('Security token expired. Refresh the page and try again.');
    }
}

function client_ip(): string
{
    $candidate = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ((bool)app_config('trust_cloudflare_ip_header') && isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidate = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '0.0.0.0';
}

function session_rate_limit(string $key, int $limit, int $windowSeconds): bool
{
    $now = time();
    $bucketKey = '_rate_' . hash('sha256', $key);
    $bucket = $_SESSION[$bucketKey] ?? ['started' => $now, 'count' => 0];

    if (($now - (int)$bucket['started']) >= $windowSeconds) {
        $bucket = ['started' => $now, 'count' => 0];
    }

    $bucket['count'] = (int)$bucket['count'] + 1;
    $_SESSION[$bucketKey] = $bucket;

    return $bucket['count'] <= $limit;
}

function record_login_attempt(string $email, bool $success): void
{
    try {
        $stmt = db()->prepare('INSERT INTO login_attempts (email_hash, ip_address, was_successful, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([hash('sha256', strtolower(trim($email))), client_ip(), $success ? 1 : 0]);
    } catch (Throwable $ignored) {
        // Authentication must remain available if audit logging is temporarily unavailable.
    }
}

function too_many_login_attempts(string $email): bool
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE (email_hash = ? OR ip_address = ?) AND was_successful = 0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)');
        $stmt->execute([hash('sha256', strtolower(trim($email))), client_ip()]);
        return (int)$stmt->fetchColumn() >= 8;
    } catch (Throwable $ignored) {
        return false;
    }
}
