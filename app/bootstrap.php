<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Load environment variables from .env file
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') === false) continue; // Skip invalid lines
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

$app = require BASE_PATH . '/config/app.php';
date_default_timezone_set((string)$app['timezone']);

require_once BASE_PATH . '/app/db.php';

$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$isInstaller = strpos($script, '/install/') !== false;

if (!$isInstaller && !is_installed()) {
    $isApi = strpos($script, '/api/') !== false;
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Application is not installed.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: /install/');
    exit;
}

require_once BASE_PATH . '/app/helpers.php';
require_once BASE_PATH . '/app/security.php';
require_once BASE_PATH . '/app/referrals.php';

send_security_headers();
start_secure_session($app);

set_exception_handler(function (Throwable $exception) use ($app): void {
    $reference = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    $line = sprintf("[%s] %s %s in %s:%d\n", date('c'), $reference, $exception->getMessage(), $exception->getFile(), $exception->getLine());
    @file_put_contents(BASE_PATH . '/storage/logs/app.log', $line, FILE_APPEND | LOCK_EX);

    if (request_expects_json()) {
        json_response([
            'ok' => false,
            'message' => !empty($app['debug']) ? $exception->getMessage() : 'Something went wrong. Reference: ' . $reference,
        ], 500);
    }

    http_response_code(500);
    $message = !empty($app['debug']) ? $exception->getMessage() : 'Something went wrong. Reference: ' . $reference;
    echo '<!doctype html><html><body style="font-family:system-ui;background:#111522;color:#fff;padding:40px"><h1>Application error</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
});

require_once BASE_PATH . '/app/auth.php';
require_once BASE_PATH . '/app/migrations.php';
if (!$isInstaller) {
    run_auto_migrations();
}
require_once BASE_PATH . '/app/market.php';
require_once BASE_PATH . '/app/finance.php';
require_once BASE_PATH . '/app/trading.php';
require_once BASE_PATH . '/app/currency.php';
require_once BASE_PATH . '/app/layout.php';
require_once BASE_PATH . '/app/original_trade.php';
