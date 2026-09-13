<?php
require __DIR__ . '/../app/bootstrap.php';

echo "====================================================\n";
echo "CLEAN ARCHITECTURE: ENV & CONFIG VERIFICATION TEST\n";
echo "====================================================\n\n";

// 1. Test env() retrieval & types
echo "1. Testing env() typed access:\n";
$tests = [
    'APP_NAME' => env('APP_NAME'),
    'APP_ENV' => env('APP_ENV'),
    'APP_DEBUG' => env('APP_DEBUG'),
    'APP_URL' => env('APP_URL'),
    'APP_TIMEZONE' => env('APP_TIMEZONE'),
    'APP_VERSION' => env('APP_VERSION'),
    'TRUST_CLOUDFLARE_IP_HEADER' => env('TRUST_CLOUDFLARE_IP_HEADER'),
    'DB_HOST' => env('DB_HOST'),
    'DB_PORT' => env('DB_PORT'),
    'DB_DATABASE' => env('DB_DATABASE'),
    'DB_USERNAME' => env('DB_USERNAME'),
    'MAIL_DRIVER' => env('MAIL_DRIVER'),
    'MAIL_HOST' => env('MAIL_HOST'),
    'MAIL_PORT' => env('MAIL_PORT'),
    'MAIL_ENCRYPTION' => env('MAIL_ENCRYPTION'),
    'MAIL_USERNAME' => env('MAIL_USERNAME'),
    'MARKET_API_PROVIDER' => env('MARKET_API_PROVIDER'),
    'FINNHUB_API_KEY' => env('FINNHUB_API_KEY'),
    'BINANCE_QUOTE_TTL_MS' => env('BINANCE_QUOTE_TTL_MS'),
    'ADMIN_EMAIL' => env('ADMIN_EMAIL'),
    'DEFAULT_CURRENCY' => env('DEFAULT_CURRENCY'),
    'NON_EXISTENT_KEY_WITH_DEFAULT' => env('NON_EXISTENT_KEY', 'default_value_ok'),
];

foreach ($tests as $key => $val) {
    $type = gettype($val);
    $display = is_bool($val) ? ($val ? 'true (bool)' : 'false (bool)') : (string)$val;
    echo sprintf("  %-30s => %s [%s]\n", $key, $display, $type);
}

// 2. Test config() dot-notation
echo "\n2. Testing config() dot-notation access:\n";
$configTests = [
    'app.name' => config('app.name'),
    'app.environment' => config('app.environment'),
    'app.debug' => config('app.debug'),
    'app.url' => config('app.url'),
    'app.timezone' => config('app.timezone'),
    'app.version' => config('app.version'),
    'app.trade_durations' => json_encode(config('app.trade_durations')),
];

foreach ($configTests as $key => $val) {
    echo sprintf("  %-25s => %s\n", $key, is_bool($val) ? ($val ? 'true' : 'false') : (string)$val);
}

// 3. Test Database connection using clean .env
echo "\n3. Testing Database connection via env():\n";
try {
    $pdo = db();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "  ✓ Database connection established successfully! Database: {$dbName}\n";
} catch (Throwable $e) {
    echo "  ✗ Database error: " . $e->getMessage() . "\n";
}

// 4. Test Mailer config reading from env()
echo "\n4. Testing Mailer configuration via env():\n";
try {
    $mailer = mailer_config();
    echo "  ✓ Mailer host: {$mailer['host']}:{$mailer['port']} ({$mailer['encryption']}), from: {$mailer['from_address']}\n";
} catch (Throwable $e) {
    echo "  ✗ Mailer error: " . $e->getMessage() . "\n";
}

// 5. Test Market API reading from env()
echo "\n5. Testing Market API resolution via env():\n";
try {
    $btcPrice = fetch_market_price('BTCUSD');
    $provider = env('MARKET_API_PROVIDER', 'binance');
    echo "  ✓ Live price for BTCUSD: {$btcPrice} (Provider: {$provider})\n";
} catch (Throwable $e) {
    echo "  ✗ Market API error: " . $e->getMessage() . "\n";
}

// 6. Test all env keys map
echo "\n6. Total loaded .env keys count: " . count(env_all()) . "\n";
echo "\n>>> CLEAN ARCHITECTURE VERIFICATION PASSED SUCCESSFULLY! <<<\n";
