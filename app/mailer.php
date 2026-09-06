<?php
declare(strict_types=1);

/**
 * Mailer Module with Hostinger SMTP SSL/TLS Socket Client
 * Supports HTML and Plain-Text Multipart Emails with Zero External Dependencies
 */

function mailer_env(string $key, ?string $default = null): ?string
{
    if (isset($_ENV[$key]) && (string)$_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    if (isset($_SERVER[$key]) && (string)$_SERVER[$key] !== '') {
        return (string)$_SERVER[$key];
    }
    $val = getenv($key);
    if ($val !== false && (string)$val !== '') {
        return (string)$val;
    }
    static $envCache = null;
    if ($envCache === null) {
        $envCache = [];
        $envFile = defined('BASE_PATH') ? BASE_PATH . '/.env' : dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                    $v = substr($v, 1, -1);
                }
                $envCache[$k] = $v;
            }
        }
    }
    return $envCache[$key] ?? $default;
}

function mailer_config(): array
{
    $host = trim((string)(mailer_env('MAIL_HOST', 'smtp.hostinger.com')));
    $port = (int)(mailer_env('MAIL_PORT', '465'));
    $encryption = strtolower(trim((string)(mailer_env('MAIL_ENCRYPTION', $port === 465 ? 'ssl' : 'tls'))));
    $username = trim((string)(mailer_env('MAIL_USERNAME', 'noreply@tradexa.pro')));
    $password = (string)(mailer_env('MAIL_PASSWORD', ''));
    $fromAddress = trim((string)(mailer_env('MAIL_FROM_ADDRESS', $username)));
    $fromName = trim((string)(mailer_env('MAIL_FROM_NAME', (setting('platform_name') ?: 'Tradexa'))));
    $driver = strtolower(trim((string)(mailer_env('MAIL_DRIVER', 'smtp'))));

    return [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'password' => $password,
        'from_address' => $fromAddress,
        'from_name' => $fromName !== '' ? $fromName : 'Tradexa',
    ];
}

function mailer_log(string $message): void
{
    $logDir = BASE_PATH . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $entry = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), trim($message));
    @file_put_contents($logDir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Send an email via native SMTP socket
 */
function smtp_send(string $to, string $subject, string $htmlBody, ?string $textBody = null, ?string $toName = null): array
{
    $to = trim(strtolower($to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => "Invalid recipient address: {$to}"];
    }

    $config = mailer_config();

    if ($config['driver'] === 'log') {
        mailer_log("DRIVER=LOG | To: {$to} | Subject: {$subject}");
        return ['ok' => true, 'message' => 'Logged'];
    }

    $host = $config['host'];
    $port = $config['port'];
    $encryption = $config['encryption'];
    $username = $config['username'];
    $password = $config['password'];
    $fromAddress = $config['from_address'];
    $fromName = $config['from_name'];
    $toName = trim((string)($toName ?? ''));

    if ($username === '' || $password === '') {
        $errorMsg = "SMTP credentials missing: username or password is not configured.";
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    $timeout = 12;
    $errno = 0;
    $errstr = '';

    // Choose transport scheme
    $remoteTarget = ($encryption === 'ssl' || $port === 465)
        ? "ssl://{$host}:{$port}"
        : "tcp://{$host}:{$port}";

    $sslContext = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]
    ]);

    $socket = @stream_socket_client($remoteTarget, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $sslContext);
    if (!$socket) {
        $errorMsg = "SMTP Connection to {$remoteTarget} failed: [{$errno}] {$errstr}";
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    stream_set_timeout($socket, $timeout);

    $readReply = function () use ($socket): array {
        $data = '';
        $code = 0;
        while ($line = fgets($socket, 512)) {
            $data .= $line;
            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $matches)) {
                $code = (int)$matches[1];
                if ($matches[2] === ' ') {
                    break;
                }
            }
        }
        return ['code' => $code, 'text' => trim($data)];
    };

    $sendCommand = function (string $cmd) use ($socket, $readReply): array {
        fputs($socket, $cmd . "\r\n");
        return $readReply();
    };

    // 1. Initial Greeting (220)
    $greeting = $readReply();
    if ($greeting['code'] !== 220) {
        fclose($socket);
        $errorMsg = "SMTP Invalid greeting: " . $greeting['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    // 2. EHLO using sender domain (tradexa.pro) for trusted SMTP relay
    $senderDomain = substr(strrchr($fromAddress, '@'), 1);
    $clientHost = !empty($senderDomain) ? $senderDomain : 'tradexa.pro';
    $ehlo = $sendCommand("EHLO {$clientHost}");
    if ($ehlo['code'] !== 250) {
        fclose($socket);
        $errorMsg = "SMTP EHLO rejected: " . $ehlo['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    // 3. STARTTLS if needed (port 587 or tls encryption)
    if ($encryption === 'tls' && $port !== 465) {
        $starttls = $sendCommand("STARTTLS");
        if ($starttls['code'] !== 220) {
            fclose($socket);
            $errorMsg = "SMTP STARTTLS failed: " . $starttls['text'];
            mailer_log($errorMsg);
            return ['ok' => false, 'message' => $errorMsg];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            $errorMsg = "SMTP Crypto negotiation failed";
            mailer_log($errorMsg);
            return ['ok' => false, 'message' => $errorMsg];
        }
        // Resend EHLO after TLS handshake
        $ehlo = $sendCommand("EHLO {$clientHost}");
    }

    // 4. AUTH LOGIN (Mandatory)
    $auth = $sendCommand("AUTH LOGIN");
    if ($auth['code'] !== 334) {
        fclose($socket);
        $errorMsg = "SMTP AUTH LOGIN rejected: " . $auth['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    $userRes = $sendCommand(base64_encode($username));
    if ($userRes['code'] !== 334) {
        fclose($socket);
        $errorMsg = "SMTP Username rejected: " . $userRes['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    $passRes = $sendCommand(base64_encode($password));
    if ($passRes['code'] !== 235) {
        fclose($socket);
        $errorMsg = "SMTP Authentication failed: " . $passRes['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    // 5. MAIL FROM
    $mailFrom = $sendCommand("MAIL FROM:<{$fromAddress}>");
    if ($mailFrom['code'] !== 250) {
        $sendCommand("QUIT");
        fclose($socket);
        $errorMsg = "SMTP MAIL FROM rejected: " . $mailFrom['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    // 6. RCPT TO
    $rcptTo = $sendCommand("RCPT TO:<{$to}>");
    if ($rcptTo['code'] !== 250 && $rcptTo['code'] !== 251) {
        $sendCommand("QUIT");
        fclose($socket);
        $errorMsg = "SMTP RCPT TO rejected for {$to}: " . $rcptTo['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    // 7. DATA
    $dataRes = $sendCommand("DATA");
    if ($dataRes['code'] !== 354) {
        $sendCommand("QUIT");
        fclose($socket);
        $errorMsg = "SMTP DATA initiation failed: " . $dataRes['text'];
        mailer_log($errorMsg);
        return ['ok' => false, 'message' => $errorMsg];
    }

    // 8. Build MIME message
    $boundary = '=_' . md5((string)microtime(true) . bin2hex(random_bytes(8)));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $fromHeader = "{$encodedFromName} <{$fromAddress}>";
    $toHeader = $toName !== '' ? '=?UTF-8?B?' . base64_encode($toName) . '?=' . " <{$to}>" : "<{$to}>";

    if ($textBody === null || trim($textBody) === '') {
        $textBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));
    }

    $messageId = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)), preg_replace('/^.*?@/', '', $fromAddress));

    $headers = [
        "Date: " . date('r'),
        "From: {$fromHeader}",
        "To: {$toHeader}",
        "Subject: {$encodedSubject}",
        "Message-ID: {$messageId}",
        "MIME-Version: 1.0",
        "X-Mailer: Tradexa Mailer",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    ];

    $content = implode("\r\n", $headers) . "\r\n\r\n";

    // Plain text part
    $content .= "--{$boundary}\r\n";
    $content .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $content .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $content .= chunk_split(base64_encode($textBody)) . "\r\n";

    // HTML part
    $content .= "--{$boundary}\r\n";
    $content .= "Content-Type: text/html; charset=UTF-8\r\n";
    $content .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $content .= chunk_split(base64_encode($htmlBody)) . "\r\n";

    // End boundary
    $content .= "--{$boundary}--\r\n";

    // SMTP transparency: lines starting with a dot must be dot-stuffed
    $content = preg_replace('/^\./m', '..', $content);

    fputs($socket, $content . "\r\n.\r\n");
    $sendRes = $readReply();

    $sendCommand("QUIT");
    fclose($socket);

    if ($sendRes['code'] === 250) {
        mailer_log("SUCCESS | Sent to: {$to} | Subject: {$subject} | Res: {$sendRes['text']}");
        return ['ok' => true, 'message' => $sendRes['text']];
    }

    $errorMsg = "SMTP Message rejected: " . $sendRes['text'];
    mailer_log("ERROR | To: {$to} | " . $errorMsg);
    return ['ok' => false, 'message' => $errorMsg];
}

/**
 * Public email sender helper
 */
function send_email(string $to, string $subject, string $htmlBody, ?string $textBody = null, ?string $toName = null): bool
{
    try {
        $res = smtp_send($to, $subject, $htmlBody, $textBody, $toName);
        return !empty($res['ok']);
    } catch (Throwable $e) {
        mailer_log("EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        return false;
    }
}

/**
 * Generates and sends a high-converting, premium HTML Welcome Email upon user account creation
 */
function send_welcome_email(array $user): bool
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $name = trim((string)($user['name'] ?? 'Trader'));
    if ($name === '') {
        $name = 'Trader';
    }

    $config = mailer_config();
    $platformName = $config['from_name'];

    $baseUrl = rtrim((string)app_config('url'), '/');
    if ($baseUrl === '' || strpos($baseUrl, '55ak.xyz') !== false) {
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : 'http://';
        $baseUrl = $scheme . $host;
    }

    $tradeUrl = $baseUrl . '/en/trade/';
    $depositUrl = $baseUrl . '/en/deposit/';
    $supportEmail = (string)(setting('support_email') ?: 'support@tradexa.pro');

    $demoBalance = number_format((float)($user['demo_balance'] ?? 1000.00), 2, '.', ',');
    $currency = strtoupper((string)($user['currency'] ?? 'USD'));
    $currencySign = $currency === 'INR' ? '₹' : '$';

    $subject = "Welcome to {$platformName} – Your Trading Account is Ready!";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {$platformName}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #0b0e14;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #e2e8f0;
            -webkit-font-smoothing: antialiased;
        }
        .wrapper {
            width: 100%;
            background-color: #0b0e14;
            padding: 30px 15px;
        }
        .container {
            max-width: 580px;
            margin: 0 auto;
            background: #131722;
            border: 1px solid #1f2736;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }
        .header {
            background: linear-gradient(135deg, #0d131f 0%, #172033 100%);
            padding: 36px 30px 28px;
            text-align: center;
            border-bottom: 1px solid #1f2736;
        }
        .badge {
            display: inline-block;
            background: rgba(0, 230, 118, 0.12);
            color: #00e676;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 6px 14px;
            border-radius: 20px;
            margin-bottom: 16px;
            border: 1px solid rgba(0, 230, 118, 0.25);
        }
        .logo {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #ffffff;
            margin: 0;
        }
        .logo span {
            color: #00e676;
        }
        .body {
            padding: 36px 32px;
        }
        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 14px;
            line-height: 1.3;
        }
        p {
            font-size: 15px;
            line-height: 1.6;
            color: #94a3b8;
            margin: 0 0 20px;
        }
        .account-card {
            background: #181f2f;
            border: 1px solid #232c40;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
        }
        .account-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #232c40;
            font-size: 14px;
        }
        .account-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .account-row:first-child {
            padding-top: 0;
        }
        .label {
            color: #64748b;
        }
        .val {
            color: #f1f5f9;
            font-weight: 600;
        }
        .balance-val {
            color: #00e676;
            font-weight: 700;
            font-size: 16px;
        }
        .cta-container {
            text-align: center;
            margin: 32px 0 28px;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #00e676 0%, #00b0ff 100%);
            color: #0b0e14 !important;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
            padding: 14px 34px;
            border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0, 230, 118, 0.35);
            letter-spacing: 0.3px;
        }
        .features {
            margin: 28px 0;
            padding: 0;
            list-style: none;
        }
        .feature-item {
            padding: 10px 0;
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #cbd5e1;
        }
        .feature-icon {
            display: inline-block;
            width: 24px;
            height: 24px;
            line-height: 24px;
            text-align: center;
            background: rgba(0, 176, 255, 0.15);
            color: #00b0ff;
            border-radius: 6px;
            margin-right: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .security-notice {
            background: rgba(245, 158, 11, 0.08);
            border-left: 3px solid #f59e0b;
            padding: 14px 16px;
            border-radius: 6px;
            font-size: 13px;
            color: #fbbf24;
            line-height: 1.5;
            margin: 24px 0 0;
        }
        .footer {
            background: #0d111a;
            border-top: 1px solid #1a202c;
            padding: 24px 30px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }
        .footer a {
            color: #94a3b8;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <div class="badge">Account Verified & Ready</div>
                <h1 class="logo">{$platformName}</h1>
            </div>
            <div class="body">
                <h1>Welcome aboard, {$name}!</h1>
                <p>Your trading account has been successfully created. You're now equipped with institutional-grade real-time market data, instant one-click execution, and smart trading tools.</p>

                <table width="100%" cellpadding="0" cellspacing="0" class="account-card" style="border-collapse:collapse;">
                    <tr>
                        <td style="padding:10px 0; border-bottom:1px solid #232c40; color:#64748b; font-size:14px;">Registered Email:</td>
                        <td align="right" style="padding:10px 0; border-bottom:1px solid #232c40; color:#f1f5f9; font-size:14px; font-weight:600;">{$email}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0; border-bottom:1px solid #232c40; color:#64748b; font-size:14px;">Practice Balance:</td>
                        <td align="right" style="padding:10px 0; border-bottom:1px solid #232c40; color:#00e676; font-size:15px; font-weight:700;">{$currencySign}{$demoBalance} {$currency}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0; color:#64748b; font-size:14px;">Account Status:</td>
                        <td align="right" style="padding:10px 0; color:#00b0ff; font-size:14px; font-weight:600;">Active</td>
                    </tr>
                </table>

                <div class="cta-container">
                    <a href="{$tradeUrl}" class="btn" target="_blank">Start Trading Now &rarr;</a>
                </div>

                <div style="font-size:13px; color:#cbd5e1; margin-top:20px;">
                    <p style="margin-bottom:8px; font-weight:600; color:#ffffff;">Quick Start Steps:</p>
                    <div style="padding:6px 0;">&#10003; <strong>Practice Risk-Free:</strong> Master your strategies using your practice balance.</div>
                    <div style="padding:6px 0;">&#10003; <strong>Deposit Securely:</strong> Fund your Live account with UPI, Cards, or Crypto anytime.</div>
                    <div style="padding:6px 0;">&#10003; <strong>Instant Execution:</strong> Enjoy ultra-fast trade placement with accurate price streaming.</div>
                </div>

                <div class="security-notice">
                    <strong>Security Reminder:</strong> Never share your login credentials or password with anyone. {$platformName} support will never ask for your password.
                </div>
            </div>
            <div class="footer">
                <p style="margin:0 0 8px;">Questions or need assistance? Contact us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
                <p style="margin:0;">&copy; 2026 {$platformName}. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Hello {$name},

Welcome to {$platformName}!

Your trading account has been successfully created and is ready to use.

Account Details:
- Registered Email: {$email}
- Practice Balance: {$currencySign}{$demoBalance} {$currency}
- Account Status: Active

Start Trading Now:
{$tradeUrl}

Security Reminder:
Never share your password with anyone. {$platformName} support will never ask for your password.

If you have any questions, contact our support team at: {$supportEmail}

Regards,
The {$platformName} Team
TEXT;

    return send_email($email, $subject, $html, $text, $name);
}

/**
 * Send Password Reset Email via Hostinger SMTP
 */
function send_password_reset_email(array $user, string $resetUrl): bool
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $name = trim((string)($user['name'] ?? 'Account Holder'));
    $config = mailer_config();
    $platformName = $config['from_name'];
    $supportEmail = (string)(setting('support_email') ?: 'support@tradexa.pro');

    $subject = "Reset Your {$platformName} Password";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
</head>
<body style="margin:0;padding:0;background:#0b0e14;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e2e8f0;">
    <div style="width:100%;padding:30px 15px;background:#0b0e14;box-sizing:border-box;">
        <div style="max-width:540px;margin:0 auto;background:#131722;border:1px solid #1f2736;border-radius:14px;overflow:hidden;">
            <div style="background:#172033;padding:28px 24px;text-align:center;border-bottom:1px solid #1f2736;">
                <h1 style="color:#ffffff;margin:0;font-size:24px;">{$platformName}</h1>
            </div>
            <div style="padding:32px 28px;">
                <h2 style="color:#ffffff;font-size:18px;margin:0 0 16px;">Hello {$name},</h2>
                <p style="color:#94a3b8;font-size:15px;line-height:1.6;margin:0 0 20px;">
                    We received a request to reset the password for your {$platformName} account. Click the button below to choose a new password:
                </p>
                <div style="text-align:center;margin:30px 0;">
                    <a href="{$resetUrl}" style="display:inline-block;background:#00e676;color:#0b0e14;text-decoration:none;font-size:15px;font-weight:700;padding:12px 30px;border-radius:8px;" target="_blank">Reset Password</a>
                </div>
                <p style="color:#64748b;font-size:13px;line-height:1.5;margin:20px 0 0;">
                    This link will expire in <strong>30 minutes</strong>. If you did not request a password reset, you can safely ignore this email.
                </p>
            </div>
            <div style="background:#0d111a;border-top:1px solid #1a202c;padding:20px;text-align:center;font-size:12px;color:#64748b;">
                Need help? Contact <a href="mailto:{$supportEmail}" style="color:#94a3b8;">{$supportEmail}</a>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Hello {$name},

We received a request to reset the password for your {$platformName} account.

Reset your password using the link below (valid for 30 minutes):
{$resetUrl}

If you did not request this, you can safely ignore this email.

Regards,
The {$platformName} Team
TEXT;

    return send_email($email, $subject, $html, $text, $name);
}
