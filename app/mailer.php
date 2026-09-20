<?php
declare(strict_types=1);

/**
 * Mailer Module with Hostinger SMTP SSL/TLS Socket Client
 * Supports HTML and Plain-Text Multipart Emails with Zero External Dependencies
 */

function mailer_env(string $key, ?string $default = null): ?string
{
    $val = env($key, $default);
    return $val !== null ? (string)$val : null;
}

function mailer_config(): array
{
    $host = trim((string)(mailer_env('MAIL_HOST', 'smtp.hostinger.com')));
    $port = (int)(mailer_env('MAIL_PORT', '465'));
    $encryption = strtolower(trim((string)(mailer_env('MAIL_ENCRYPTION', $port === 465 ? 'ssl' : 'tls'))));
    $username = trim((string)(mailer_env('MAIL_USERNAME', 'noreply@qxquotax.online')));
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

    $timeout = 3;
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

    // 2. EHLO using sender domain (qxquotax.online) for trusted SMTP relay
    $senderDomain = substr(strrchr($fromAddress, '@'), 1);
    $clientHost = !empty($senderDomain) ? $senderDomain : 'qxquotax.online';
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
 * Generates a clean HTML brand header with the Quotex logo for all email templates
 */
function mailer_header_html(string $platformName, string $baseUrl, string $accentColor = '#111111'): string
{
    $logoUrl = $baseUrl . '/assets/img/email-logo.png';
    return <<<HTML
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin-bottom: 24px; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: middle; padding-right: 12px;">
                    <img src="{$logoUrl}" alt="{$platformName}" width="42" height="48" style="display: block; width: 42px; height: 48px; max-width: 42px; border: 0; outline: none; border-radius: 4px;" />
                </td>
                <td style="vertical-align: middle;">
                    <span style="font-size: 22px; font-weight: 800; color: {$accentColor}; letter-spacing: -0.3px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">{$platformName}</span>
                </td>
            </tr>
        </table>
HTML;
}

/**
 * Generates and sends a clean, professional, plain-style HTML Welcome Email upon user account creation
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

    $userId = (int)($user['id'] ?? 0);
    $accountIdFormatted = $userId > 0 ? sprintf('#%06d', $userId) : 'Active';

    $config = mailer_config();
    $platformName = $config['from_name'];

    $baseUrl = app_base_url();

    $tradeUrl = $baseUrl . '/en/trade/';
    $supportEmail = (string)(setting('support_email') ?: 'support@qxquotax.online');
    $termsUrl = $baseUrl . '/en/terms/';
    $privacyUrl = $baseUrl . '/en/privacy/';

    $demoBalance = number_format((float)($user['demo_balance'] ?? 1000.00), 2, '.', ',');
    $currency = strtoupper((string)($user['currency'] ?? 'USD'));
    $currencySign = $currency === 'INR' ? '₹' : '$';

    $subject = "Welcome to {$platformName} – Your Account is Ready";
    $year = date('Y');
    $headerHtml = mailer_header_html($platformName, $baseUrl);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {$platformName}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #222222;">
    <div style="max-width: 600px; margin: 0 auto; padding: 12px 0;">
        {$headerHtml}

        <p style="margin: 0 0 16px 0;">Dear <strong>{$name}</strong>,</p>

        <p style="margin: 0 0 16px 0;">
            Thank you for registering with {$platformName}. Your trading account has been created and is now active.
        </p>

        <p style="margin: 0 0 8px 0; font-weight: 600;">Account Details:</p>
        <ul style="margin: 0 0 20px 0; padding-left: 20px; color: #333333;">
            <li style="margin-bottom: 4px;">Account ID: <strong>{$accountIdFormatted}</strong></li>
            <li style="margin-bottom: 4px;">Registered Email: <strong>{$email}</strong></li>
            <li style="margin-bottom: 4px;">Account Currency: <strong>{$currency}</strong></li>
            <li style="margin-bottom: 4px;">Practice (Demo) Balance: <strong>{$currencySign}{$demoBalance} {$currency}</strong></li>
            <li style="margin-bottom: 4px;">Status: <strong>Active</strong></li>
        </ul>

        <p style="margin: 0 0 20px 0;">
            You can access your trading room directly using the link below:<br>
            <a href="{$tradeUrl}" style="color: #1a73e8; text-decoration: underline; font-weight: 600;">{$tradeUrl}</a>
        </p>

        <p style="margin: 0 0 16px 0;">
            <strong>Next Steps:</strong>
        </p>
        <ol style="margin: 0 0 24px 0; padding-left: 20px; color: #333333;">
            <li style="margin-bottom: 6px;">Practice trading risk-free using your complimentary virtual practice balance.</li>
            <li style="margin-bottom: 6px;">Deposit funds to start placing real-market trades whenever you are ready.</li>
            <li style="margin-bottom: 6px;">Explore live market charts across crypto, currencies, commodities, and indices.</li>
        </ol>

        <p style="margin: 0 0 20px 0; font-size: 14px; color: #555555;">
            <strong>Security Reminder:</strong> Never share your account credentials or passwords with anyone. {$platformName} support will never ask for your password.
        </p>

        <p style="margin: 0 0 24px 0; font-size: 14px; color: #555555;">
            If you have any questions or need assistance, please contact our support team at <a href="mailto:{$supportEmail}" style="color: #1a73e8; text-decoration: underline;">{$supportEmail}</a>.
        </p>

        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

        <p style="margin: 0 0 8px 0; font-size: 12px; color: #777777;">
            <strong>Risk Warning:</strong> Trading financial instruments carries risk of capital loss. Please trade responsibly.
        </p>

        <p style="margin: 0; font-size: 12px; color: #777777;">
            <a href="{$tradeUrl}" style="color: #1a73e8; text-decoration: underline;">Trading Room</a> &bull;
            <a href="{$termsUrl}" style="color: #1a73e8; text-decoration: underline;">Terms of Service</a> &bull;
            <a href="{$privacyUrl}" style="color: #1a73e8; text-decoration: underline;">Privacy Policy</a><br>
            &copy; {$year} {$platformName}. All rights reserved.
        </p>
    </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Welcome to {$platformName}
========================================

Dear {$name},

Thank you for registering with {$platformName}. Your trading account has been created and is now active.

Account Details:
- Account ID: {$accountIdFormatted}
- Registered Email: {$email}
- Account Currency: {$currency}
- Practice (Demo) Balance: {$currencySign}{$demoBalance} {$currency}
- Status: Active

Access your trading room:
{$tradeUrl}

Next Steps:
1. Practice trading risk-free using your complimentary virtual practice balance.
2. Deposit funds to start placing real-market trades whenever you are ready.
3. Explore live market charts across crypto, currencies, commodities, and indices.

Security Reminder:
Never share your account credentials or passwords with anyone. {$platformName} support will never ask for your password.

If you have any questions, please contact our support team at {$supportEmail}.

----------------------------------------
Risk Warning: Trading financial instruments carries risk of capital loss. Please trade responsibly.

© {$year} {$platformName}. All rights reserved.
TEXT;

    return send_email($email, $subject, $html, $text, $name);
}

/**
 * Send Password Reset Email via Hostinger SMTP (Clean professional plain style)
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
    $supportEmail = (string)(setting('support_email') ?: 'support@qxquotax.online');
    $baseUrl = app_base_url();
    $termsUrl = $baseUrl . '/en/terms/';
    $privacyUrl = $baseUrl . '/en/privacy/';
    $year = date('Y');

    $subject = "Reset Your {$platformName} Password";
    $headerHtml = mailer_header_html($platformName, $baseUrl);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - {$platformName}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #222222;">
    <div style="max-width: 600px; margin: 0 auto; padding: 12px 0;">
        {$headerHtml}

        <p style="margin: 0 0 16px 0;">Hello <strong>{$name}</strong>,</p>


        <p style="margin: 0 0 16px 0;">
            We received a request to reset the password for your {$platformName} account.
        </p>

        <p style="margin: 0 0 20px 0;">
            To set a new password, please click the link below (valid for 30 minutes):<br>
            <a href="{$resetUrl}" style="color: #1a73e8; text-decoration: underline; font-weight: 600;">{$resetUrl}</a>
        </p>

        <p style="margin: 0 0 20px 0; font-size: 14px; color: #555555;">
            If you did not request a password reset, you can safely ignore this email. Your account remains secure.
        </p>

        <p style="margin: 0 0 24px 0; font-size: 14px; color: #555555;">
            For any questions or help, contact our support team at <a href="mailto:{$supportEmail}" style="color: #1a73e8; text-decoration: underline;">{$supportEmail}</a>.
        </p>

        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

        <p style="margin: 0; font-size: 12px; color: #777777;">
            <a href="{$baseUrl}" style="color: #1a73e8; text-decoration: underline;">Platform Home</a> &bull;
            <a href="{$termsUrl}" style="color: #1a73e8; text-decoration: underline;">Terms of Service</a> &bull;
            <a href="{$privacyUrl}" style="color: #1a73e8; text-decoration: underline;">Privacy Policy</a><br>
            &copy; {$year} {$platformName}. All rights reserved.
        </p>
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

Need Help?
Support is available 24/7 at {$supportEmail}.

© {$year} {$platformName}. All rights reserved.
TEXT;

    return send_email($email, $subject, $html, $text, $name);
}

/**
 * Send Deposit Placed Confirmation Email (Processed within 24 Hours)
 */
function send_deposit_placed_email(array $user, array $deposit): bool
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $name = trim((string)($user['name'] ?? 'Trader'));
    if ($name === '') {
        $name = 'Trader';
    }

    $userId = (int)($user['id'] ?? 0);
    $accountIdFormatted = $userId > 0 ? sprintf('#%06d', $userId) : 'Active';

    $config = mailer_config();
    $platformName = $config['from_name'];
    $supportEmail = (string)(setting('support_email') ?: 'support@qxquotax.online');

    $baseUrl = app_base_url();

    $tradeUrl = $baseUrl . '/en/trade/';
    $walletUrl = $baseUrl . '/en/wallet/';
    $termsUrl = $baseUrl . '/en/terms/';
    $privacyUrl = $baseUrl . '/en/privacy/';
    $year = date('Y');

    $orderId = (string)($deposit['public_id'] ?? $deposit['id'] ?? 'D' . time());
    $currency = strtoupper((string)($deposit['currency'] ?? $user['currency'] ?? 'USD'));
    $currencySign = $currency === 'INR' ? '₹' : '$';
    $amount = number_format((float)($deposit['amount'] ?? 0.00), 2, '.', ',');
    $method = trim((string)($deposit['method'] ?? $deposit['method_name'] ?? 'Online Payment'));
    $reference = trim((string)($deposit['reference'] ?? $deposit['user_reference'] ?? ''));

    $subject = "Deposit Placed (#{$orderId}) – Processing Within 24 Hours";
    $headerHtml = mailer_header_html($platformName, $baseUrl);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Placed - {$platformName}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #222222;">
    <div style="max-width: 600px; margin: 0 auto; padding: 12px 0;">
        {$headerHtml}

        <p style="margin: 0 0 16px 0;">Dear <strong>{$name}</strong>,</p>

        <p style="margin: 0 0 16px 0;">
            Your deposit request for <strong>{$currencySign}{$amount} {$currency}</strong> has been successfully placed and received in our system.
        </p>

        <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 16px; margin: 0 0 20px 0; color: #166534;">
            <strong style="display: block; margin-bottom: 4px; font-size: 16px;">⏱️ Processing SLA: Within 24 Hours</strong>
            <span>Our financial operations team is reviewing your transaction. The balance will be verified and credited to your live account within 24 hours.</span>
        </div>

        <p style="margin: 0 0 8px 0; font-weight: 600;">Deposit Summary:</p>
        <ul style="margin: 0 0 20px 0; padding-left: 20px; color: #333333;">
            <li style="margin-bottom: 4px;">Order ID: <strong>{$orderId}</strong></li>
            <li style="margin-bottom: 4px;">Account ID: <strong>{$accountIdFormatted}</strong></li>
            <li style="margin-bottom: 4px;">Deposit Amount: <strong>{$currencySign}{$amount} {$currency}</strong></li>
            <li style="margin-bottom: 4px;">Payment Method: <strong>{$method}</strong></li>
HTML;

    if ($reference !== '') {
        $html .= <<<HTML
            <li style="margin-bottom: 4px;">Transaction Reference: <strong>{$reference}</strong></li>
HTML;
    }

    $html .= <<<HTML
            <li style="margin-bottom: 4px;">Status: <strong style="color: #059669;">Pending Verification</strong></li>
        </ul>

        <p style="margin: 0 0 20px 0;">
            You can monitor your transaction status in your wallet anytime:<br>
            <a href="{$walletUrl}" style="color: #1a73e8; text-decoration: underline; font-weight: 600;">{$walletUrl}</a>
        </p>

        <p style="margin: 0 0 20px 0; font-size: 14px; color: #555555;">
            <strong>Need Immediate Assistance?</strong> If you have any questions regarding your deposit, reach out to our 24/7 support desk at <a href="mailto:{$supportEmail}" style="color: #1a73e8; text-decoration: underline;">{$supportEmail}</a> with your Order ID <strong>{$orderId}</strong>.
        </p>

        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

        <p style="margin: 0 0 8px 0; font-size: 12px; color: #777777;">
            <strong>Risk Warning:</strong> Trading financial instruments carries risk of capital loss. Please trade responsibly.
        </p>

        <p style="margin: 0; font-size: 12px; color: #777777;">
            <a href="{$tradeUrl}" style="color: #1a73e8; text-decoration: underline;">Trading Room</a> &bull;
            <a href="{$walletUrl}" style="color: #1a73e8; text-decoration: underline;">Wallet</a> &bull;
            <a href="{$termsUrl}" style="color: #1a73e8; text-decoration: underline;">Terms of Service</a> &bull;
            <a href="{$privacyUrl}" style="color: #1a73e8; text-decoration: underline;">Privacy Policy</a><br>
            &copy; {$year} {$platformName}. All rights reserved.
        </p>
    </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Deposit Placed - {$platformName}
========================================

Dear {$name},

Your deposit request for {$currencySign}{$amount} {$currency} has been placed and received.

Processing SLA: Within 24 Hours
Our financial operations team is verifying your payment details. The funds will be credited to your live balance within 24 hours.

Deposit Summary:
- Order ID: {$orderId}
- Account ID: {$accountIdFormatted}
- Amount: {$currencySign}{$amount} {$currency}
- Payment Method: {$method}
TEXT;

    if ($reference !== '') {
        $text .= "\n- Transaction Reference: {$reference}";
    }

    $text .= <<<TEXT

- Status: Pending Verification

Check order status:
{$walletUrl}

If you have any questions, please contact our support team at {$supportEmail}.

----------------------------------------
Risk Warning: Trading financial instruments carries risk of capital loss. Please trade responsibly.

© {$year} {$platformName}. All rights reserved.
TEXT;

    return send_email($email, $subject, $html, $text, $name);
}

/**
 * Send Account Banned / Suspended Notification Email
 */
function send_account_banned_email(array $user, string $reason = ''): bool
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $name = trim((string)($user['name'] ?? 'Account Holder'));
    if ($name === '') {
        $name = 'Account Holder';
    }

    $userId = (int)($user['id'] ?? 0);
    $accountIdFormatted = $userId > 0 ? sprintf('#%06d', $userId) : 'Active';

    $config = mailer_config();
    $platformName = $config['from_name'];
    $supportEmail = (string)(setting('support_email') ?: 'support@qxquotax.online');

    $baseUrl = app_base_url();

    $termsUrl = $baseUrl . '/en/terms/';
    $privacyUrl = $baseUrl . '/en/privacy/';
    $supportUrl = $baseUrl . '/en/support/';
    $year = date('Y');
    $dateStr = date('d M Y, H:i T');

    $subject = "Important Security Notice: Your {$platformName} Account Has Been Suspended";
    $headerHtml = mailer_header_html($platformName, $baseUrl, '#dc2626');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended - {$platformName}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #222222;">
    <div style="max-width: 600px; margin: 0 auto; padding: 12px 0;">
        {$headerHtml}

        <p style="margin: 0 0 16px 0;">Dear <strong>{$name}</strong>,</p>

        <p style="margin: 0 0 16px 0;">
            This email is an official notification that your trading account on {$platformName} has been <strong>suspended / blocked</strong>.
        </p>

        <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 16px; margin: 0 0 20px 0; color: #991b1b;">
            <strong style="display: block; margin-bottom: 4px; font-size: 15px;">⚠️ Account Restriction Notice</strong>
            <span>In accordance with our platform terms of service and compliance guidelines, active trading, deposits, and session logins have been disabled for this account.</span>
        </div>

        <p style="margin: 0 0 8px 0; font-weight: 600;">Account Details:</p>
        <ul style="margin: 0 0 20px 0; padding-left: 20px; color: #333333;">
            <li style="margin-bottom: 4px;">Account ID: <strong>{$accountIdFormatted}</strong></li>
            <li style="margin-bottom: 4px;">Registered Email: <strong>{$email}</strong></li>
            <li style="margin-bottom: 4px;">Action Taken: <strong style="color: #dc2626;">Blocked / Suspended</strong></li>
            <li style="margin-bottom: 4px;">Effective Date: <strong>{$dateStr}</strong></li>
        </ul>
HTML;

    if ($reason !== '') {
        $html .= <<<HTML
        <p style="margin: 0 0 16px 0; font-size: 14px; color: #555555;">
            <strong>Reason for Action:</strong> {$reason}
        </p>
HTML;
    }

    $html .= <<<HTML
        <p style="margin: 0 0 16px 0;">
            <strong>How to Appeal or Request Information:</strong>
        </p>
        <p style="margin: 0 0 20px 0; font-size: 14px; color: #444444;">
            If you believe this suspension was made in error or wish to submit an appeal, please contact our support department directly at <a href="mailto:{$supportEmail}" style="color: #1a73e8; text-decoration: underline; font-weight: 600;">{$supportEmail}</a>.
            Please include your Account ID (<strong>{$accountIdFormatted}</strong>) and registered email address to expedite your inquiry.
        </p>

        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

        <p style="margin: 0; font-size: 12px; color: #777777;">
            <a href="{$termsUrl}" style="color: #1a73e8; text-decoration: underline;">Terms of Service</a> &bull;
            <a href="{$privacyUrl}" style="color: #1a73e8; text-decoration: underline;">Privacy Policy</a> &bull;
            <a href="{$supportUrl}" style="color: #1a73e8; text-decoration: underline;">Support</a><br>
            &copy; {$year} {$platformName}. All rights reserved.
        </p>
    </div>
</body>
</html>
HTML;

    $text = <<<TEXT
Security Notice: Account Suspended - {$platformName}
========================================

Dear {$name},

This email is an official notification that your trading account on {$platformName} has been suspended / blocked.

Account Details:
- Account ID: {$accountIdFormatted}
- Registered Email: {$email}
- Action Taken: Blocked / Suspended
- Effective Date: {$dateStr}
TEXT;

    if ($reason !== '') {
        $text .= "\n- Reason: {$reason}";
    }

    $text .= <<<TEXT

How to Appeal or Request Information:
If you believe this suspension was made in error or wish to submit an appeal, please contact our support desk at {$supportEmail}.
Please include your Account ID ({$accountIdFormatted}) with your inquiry.

----------------------------------------
© {$year} {$platformName}. All rights reserved.
TEXT;

    return send_email($email, $subject, $html, $text, $name);
}

