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
 * Generates and sends a high-converting, professional Quotex-style HTML Welcome Email upon user account creation
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

    $baseUrl = rtrim((string)app_config('url'), '/');
    if ($baseUrl === '' || strpos($baseUrl, '55ak.xyz') !== false) {
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : 'http://';
        $baseUrl = $scheme . $host;
    }

    $tradeUrl = $baseUrl . '/en/trade/';
    $depositUrl = $baseUrl . '/en/deposit/';
    $supportEmail = (string)(setting('support_email') ?: 'support@tradexa.pro');
    $termsUrl = $baseUrl . '/en/terms/';
    $privacyUrl = $baseUrl . '/en/privacy/';

    $demoBalance = number_format((float)($user['demo_balance'] ?? 10000.00), 2, '.', ',');
    $currency = strtoupper((string)($user['currency'] ?? 'USD'));
    $currencySign = $currency === 'INR' ? '₹' : '$';

    $subject = "Welcome to {$platformName} – Your Trading Account is Ready";

    $year = date('Y');

    $html = <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Welcome to {$platformName}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0c1017; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color: #e2e8f0; -webkit-font-smoothing: antialiased; line-height: 1.5;">
    <!-- Outer Table -->
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #0c1017; margin: 0; padding: 32px 12px 40px;">
        <tr>
            <td align="center" valign="top">
                <!-- Main Container -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #151c28; border: 1px solid #222d42; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.45);">
                    
                    <!-- Header Bar -->
                    <tr>
                        <td align="center" style="background-color: #101620; padding: 26px 32px; border-bottom: 1px solid #222d42;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="left" style="font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff;">
                                        {$platformName}<span style="color: #0073e6;">.</span>
                                    </td>
                                    <td align="right">
                                        <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #00e676; background-color: #0f2320; border: 1px solid rgba(0, 230, 118, 0.3); padding: 5px 12px; border-radius: 4px; display: inline-block;">
                                            Official Notification
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 36px 36px 28px;">
                            <h1 style="margin: 0 0 16px; font-size: 22px; font-weight: 700; color: #ffffff; line-height: 1.3;">
                                Welcome to {$platformName}
                            </h1>
                            <p style="margin: 0 0 16px; font-size: 15px; color: #cbd5e1; line-height: 1.6;">
                                Dear <strong style="color: #ffffff;">{$name}</strong>,
                            </p>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #94a3b8; line-height: 1.6;">
                                Thank you for registering with {$platformName}. Your trading profile has been successfully set up and is now ready for use. You have immediate access to real-time market data, rapid trade execution, and professional charting tools.
                            </p>

                            <!-- Account Details Table -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #101620; border: 1px solid #222d42; border-radius: 6px; margin: 0 0 28px; border-collapse: collapse;">
                                <tr>
                                    <td colspan="2" style="padding: 12px 18px; background-color: #1a2232; border-bottom: 1px solid #222d42; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #94a3b8;">
                                        Trading Account Summary
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; color: #94a3b8;">Account ID:</td>
                                    <td align="right" style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; font-weight: 600; color: #f1f5f9; font-family: 'Courier New', Courier, monospace;">{$accountIdFormatted}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; color: #94a3b8;">Registered Email:</td>
                                    <td align="right" style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; font-weight: 600; color: #f1f5f9;">{$email}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; color: #94a3b8;">Account Currency:</td>
                                    <td align="right" style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; font-weight: 600; color: #f1f5f9;">{$currency}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 13px; color: #94a3b8;">Practice (Demo) Balance:</td>
                                    <td align="right" style="padding: 12px 18px; border-bottom: 1px solid #1c2638; font-size: 14px; font-weight: 700; color: #00e676;">{$currencySign}{$demoBalance} {$currency}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 18px; font-size: 13px; color: #94a3b8;">Account Status:</td>
                                    <td align="right" style="padding: 12px 18px; font-size: 13px; font-weight: 600; color: #00b0ff;">Active</td>
                                </tr>
                            </table>

                            <!-- Primary CTA Button (Quotex Solid Style) -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 32px;">
                                <tr>
                                    <td align="center">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" bgcolor="#0073e6" style="border-radius: 6px; background-color: #0073e6;">
                                                    <a href="{$tradeUrl}" target="_blank" style="font-size: 15px; font-weight: 700; color: #ffffff; text-decoration: none; padding: 14px 40px; display: inline-block; letter-spacing: 0.3px; border-radius: 6px; font-family: inherit;">
                                                        START TRADING NOW &rarr;
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- 3-Step Quick Guide -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 28px; border-top: 1px solid #222d42; padding-top: 24px;">
                                <tr>
                                    <td style="font-size: 14px; font-weight: 700; color: #ffffff; padding-bottom: 14px;">
                                        Quick Start Guide:
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td valign="top" width="28" style="font-size: 13px; font-weight: 700; color: #0073e6;">1.</td>
                                                <td style="font-size: 13px; color: #cbd5e1; line-height: 1.5;">
                                                    <strong style="color: #ffffff;">Practice Risk-Free:</strong> Test trading strategies and familiarize yourself with tools using your virtual demo funds.
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td valign="top" width="28" style="font-size: 13px; font-weight: 700; color: #0073e6;">2.</td>
                                                <td style="font-size: 13px; color: #cbd5e1; line-height: 1.5;">
                                                    <strong style="color: #ffffff;">Fund Your Account:</strong> Make a secure deposit using UPI, Bank Cards, E-Wallets, or Cryptocurrency to unlock live trading.
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td valign="top" width="28" style="font-size: 13px; font-weight: 700; color: #0073e6;">3.</td>
                                                <td style="font-size: 13px; color: #cbd5e1; line-height: 1.5;">
                                                    <strong style="color: #ffffff;">Execute Trades:</strong> Open positions across 400+ assets with lightning-fast execution and real-time market data.
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Security Information Box -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #101620; border-left: 3px solid #0073e6; border-radius: 4px; padding: 14px 16px; margin: 0 0 20px;">
                                <tr>
                                    <td style="font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                        <strong style="color: #ffffff;">Account Security:</strong> Never disclose your account credentials or passwords. {$platformName} staff will never request your password or one-time verification codes.
                                    </td>
                                </tr>
                            </table>

                            <!-- Support Section -->
                            <p style="margin: 0; font-size: 13px; color: #94a3b8; line-height: 1.5;">
                                If you require assistance, our customer support team is available 24/7 at <a href="mailto:{$supportEmail}" style="color: #0073e6; text-decoration: none;">{$supportEmail}</a>.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #101620; border-top: 1px solid #222d42; padding: 24px 36px; text-align: center;">
                            <p style="margin: 0 0 12px; font-size: 11px; color: #64748b; line-height: 1.6; text-align: justify;">
                                <strong style="color: #94a3b8;">Risk Warning:</strong> Financial operations provided on the platform may involve high risk. Engaging in trading financial instruments and digital contracts carries substantial risk of capital loss. Please assess your investment objectives and risk appetite prior to trading.
                            </p>
                            <p style="margin: 0 0 12px; font-size: 11px; color: #64748b;">
                                <a href="{$tradeUrl}" style="color: #94a3b8; text-decoration: none; margin: 0 8px;">Trading Platform</a> &bull;
                                <a href="{$termsUrl}" style="color: #94a3b8; text-decoration: none; margin: 0 8px;">Terms of Service</a> &bull;
                                <a href="{$privacyUrl}" style="color: #94a3b8; text-decoration: none; margin: 0 8px;">Privacy Policy</a>
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #475569;">
                                &copy; {$year} {$platformName}. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- End Main Container -->
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    $text = <<<TEXT
Welcome to {$platformName}
========================================

Dear {$name},

Thank you for registering with {$platformName}. Your trading profile has been successfully set up and is now ready for use.

Trading Account Summary:
- Account ID: {$accountIdFormatted}
- Registered Email: {$email}
- Account Currency: {$currency}
- Practice (Demo) Balance: {$currencySign}{$demoBalance} {$currency}
- Account Status: Active

Start Trading:
{$tradeUrl}

Quick Start Guide:
1. Practice Risk-Free: Test trading strategies using your virtual demo funds.
2. Fund Your Account: Make a secure deposit using cards, UPI, or crypto to unlock live trading.
3. Execute Trades: Open positions with lightning-fast execution and real-time quotes.

Security Reminder:
Never disclose your account credentials to anyone. {$platformName} staff will never request your password or verification codes.

Need Help?
Our support team is available 24/7 at {$supportEmail}.

----------------------------------------
Risk Warning: Financial operations provided on the platform may involve high risk. Engaging in trading financial instruments carries substantial risk of capital loss. Please assess your risk appetite prior to trading.

© {$year} {$platformName}. All rights reserved.
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
    $baseUrl = rtrim((string)app_config('url'), '/');
    if ($baseUrl === '' || strpos($baseUrl, '55ak.xyz') !== false) {
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : 'http://';
        $baseUrl = $scheme . $host;
    }
    $termsUrl = $baseUrl . '/en/terms/';
    $privacyUrl = $baseUrl . '/en/privacy/';
    $year = date('Y');

    $subject = "Reset Your {$platformName} Password";

    $html = <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Password Reset - {$platformName}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0c1017; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color: #e2e8f0; -webkit-font-smoothing: antialiased; line-height: 1.5;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed; background-color: #0c1017; margin: 0; padding: 32px 12px 40px;">
        <tr>
            <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #151c28; border: 1px solid #222d42; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.45);">
                    <tr>
                        <td align="center" style="background-color: #101620; padding: 26px 32px; border-bottom: 1px solid #222d42;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="left" style="font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff;">
                                        {$platformName}<span style="color: #0073e6;">.</span>
                                    </td>
                                    <td align="right">
                                        <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #00b0ff; background-color: #0f1c2b; border: 1px solid rgba(0, 176, 255, 0.3); padding: 5px 12px; border-radius: 4px; display: inline-block;">
                                            Security Request
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 36px 36px 28px;">
                            <h1 style="margin: 0 0 16px; font-size: 20px; font-weight: 700; color: #ffffff; line-height: 1.3;">
                                Reset Your Password
                            </h1>
                            <p style="margin: 0 0 16px; font-size: 15px; color: #cbd5e1; line-height: 1.6;">
                                Hello <strong style="color: #ffffff;">{$name}</strong>,
                            </p>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #94a3b8; line-height: 1.6;">
                                We received a request to reset the password associated with your {$platformName} account. Click the button below to set a new password:
                            </p>

                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 28px;">
                                <tr>
                                    <td align="center">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" bgcolor="#0073e6" style="border-radius: 6px; background-color: #0073e6;">
                                                    <a href="{$resetUrl}" target="_blank" style="font-size: 15px; font-weight: 700; color: #ffffff; text-decoration: none; padding: 14px 40px; display: inline-block; letter-spacing: 0.3px; border-radius: 6px; font-family: inherit;">
                                                        RESET PASSWORD &rarr;
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #101620; border-left: 3px solid #00b0ff; border-radius: 4px; padding: 14px 16px; margin: 0 0 24px;">
                                <tr>
                                    <td style="font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                        This reset link will expire in <strong style="color: #ffffff;">30 minutes</strong>. If you did not initiate this request, no action is required and your account remains secure.
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 13px; color: #94a3b8; line-height: 1.5;">
                                For any questions, please contact our support team at <a href="mailto:{$supportEmail}" style="color: #0073e6; text-decoration: none;">{$supportEmail}</a>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #101620; border-top: 1px solid #222d42; padding: 24px 36px; text-align: center;">
                            <p style="margin: 0 0 12px; font-size: 11px; color: #64748b;">
                                <a href="{$baseUrl}" style="color: #94a3b8; text-decoration: none; margin: 0 8px;">Platform Home</a> &bull;
                                <a href="{$termsUrl}" style="color: #94a3b8; text-decoration: none; margin: 0 8px;">Terms of Service</a> &bull;
                                <a href="{$privacyUrl}" style="color: #94a3b8; text-decoration: none; margin: 0 8px;">Privacy Policy</a>
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #475569;">
                                &copy; {$year} {$platformName}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
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
