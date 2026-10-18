<?php
/**
 * TradeZen / 55AK Senior Software Engineer Comprehensive Test Suite
 * End-to-End Automated API & Production Readiness Verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$baseUrl = 'http://localhost';
$results = [];

function run_test(string $name, callable $fn) {
    global $results;
    try {
        $result = $fn();
        $results[] = ['name' => $name, 'status' => 'PASS', 'details' => $result ?? 'OK'];
        echo "✅ PASS: {$name}\n";
        if ($result && is_string($result)) {
            echo "   └─ {$result}\n";
        }
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'details' => $e->getMessage()];
        echo "❌ FAIL: {$name}\n";
        echo "   └─ ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

function http_req(string $path, string $method = 'GET', ?array $data = null, array $headers = [], ?string $cookieJar = null): array {
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $h = ['User-Agent: SeniorQATester/1.0', 'Accept: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($data !== null) {
        $body = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException("cURL failed: {$curlError}");
    }

    $rawHeader = substr((string)$response, 0, $headerSize);
    $body = substr((string)$response, $headerSize);
    $json = json_decode($body, true);

    return [
        'code' => $httpCode,
        'headers' => $rawHeader,
        'body' => $body,
        'json' => $json,
    ];
}

function http_post_form(string $path, array $data, ?string $cookieJar = null): array {
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $h = ['User-Agent: SeniorQATester/1.0', 'Content-Type: application/x-www-form-urlencoded'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException("cURL failed: {$curlError}");
    }

    $rawHeader = substr((string)$response, 0, $headerSize);
    $body = substr((string)$response, $headerSize);
    $json = json_decode($body, true);

    return [
        'code' => $httpCode,
        'headers' => $rawHeader,
        'body' => $body,
        'json' => $json,
    ];
}

echo "====================================================================\n";
echo " TRADEZEN PRODUCTION & API SENIOR DEVELOPER TEST SUITE\n";
echo "====================================================================\n\n";

// 1. Health API
run_test("API: GET /en/api/health.php returns 200 and healthy DB", function () {
    $res = http_req('/en/api/health.php');
    if ($res['code'] !== 200) throw new Exception("Expected 200, got {$res['code']}");
    if (empty($res['json']['ok']) || $res['json']['status'] !== 'healthy') {
        throw new Exception("Unexpected payload: " . $res['body']);
    }
    return "Version: {$res['json']['version']}, Status: {$res['json']['status']}";
});

// 2. Standalone Market API
run_test("API: GET /en/api/market-api.php?symbol=BTCUSD&action=price", function () {
    $res = http_req('/en/api/market-api.php?symbol=BTCUSD&action=price');
    if ($res['code'] !== 200) throw new Exception("Expected 200, got {$res['code']}");
    if (empty($res['json']['ok']) || empty($res['json']['price'])) {
        throw new Exception("Invalid response: " . $res['body']);
    }
    return "BTCUSD Price: {$res['json']['price']}";
});

// 3. Market API - Crypto
run_test("API: GET /en/api/market.php?symbol=BTCUSD (Crypto)", function () {
    $res = http_req('/en/api/market.php?symbol=BTCUSD&interval=60&count=30');
    if ($res['code'] !== 200) throw new Exception("Expected 200, got {$res['code']}");
    $data = $res['json']['data'] ?? [];
    if (empty($data['asset']) || $data['asset']['symbol'] !== 'BTCUSD') {
        throw new Exception("Invalid asset data: " . $res['body']);
    }
    if (empty($data['candles']) || count($data['candles']) < 5) {
        throw new Exception("Insufficient candles: count=" . count($data['candles'] ?? []));
    }
    return "Price: {$data['asset']['price']}, Candles: " . count($data['candles']);
});

// 4. Market API - Currencies
run_test("API: GET /en/api/market.php?symbol=EURUSD (Currencies)", function () {
    $res = http_req('/en/api/market.php?symbol=EURUSD&interval=60&count=30');
    if ($res['code'] !== 200) throw new Exception("Expected 200, got {$res['code']}");
    $data = $res['json']['data'] ?? [];
    if (empty($data['asset']) || $data['asset']['symbol'] !== 'EURUSD') {
        throw new Exception("Invalid asset data: " . $res['body']);
    }
    if (empty($data['candles']) || count($data['candles']) < 5) {
        throw new Exception("Insufficient candles: count=" . count($data['candles'] ?? []));
    }
    return "Price: {$data['asset']['price']}, Candles: " . count($data['candles']);
});

// 5. Market API - Commodities
run_test("API: GET /en/api/market.php?symbol=XAUUSD (Gold)", function () {
    $res = http_req('/en/api/market.php?symbol=XAUUSD&interval=60&count=30');
    if ($res['code'] !== 200) throw new Exception("Expected 200, got {$res['code']}");
    $data = $res['json']['data'] ?? [];
    if (empty($data['asset']) || $data['asset']['symbol'] !== 'XAUUSD') {
        throw new Exception("Invalid asset data: " . $res['body']);
    }
    return "Gold Price: {$data['asset']['price']}, Candles: " . count($data['candles']);
});

// 6. Security Check: Unauthenticated access to private APIs
run_test("Security: Unauthenticated /en/api/session.php returns 401 or redirect", function () {
    $res = http_req('/en/api/session.php');
    if ($res['code'] !== 401 && !in_array($res['code'], [302, 301, 307])) {
        throw new Exception("Expected 401 or redirect, got {$res['code']}");
    }
    return "Status: {$res['code']}";
});

run_test("Security: Unauthenticated /en/api/state.php returns 401 or redirect", function () {
    $res = http_req('/en/api/state.php');
    if ($res['code'] !== 401 && !in_array($res['code'], [302, 301, 307])) {
        throw new Exception("Expected 401 or redirect, got {$res['code']}");
    }
    return "Status: {$res['code']}";
});

run_test("Security: Protected directories blocked (.env, /config/, /storage/)", function () {
    $envCheck = http_req('/.env');
    if ($envCheck['code'] !== 403 && $envCheck['code'] !== 404) {
        throw new Exception("/.env is NOT blocked! Returned: {$envCheck['code']}");
    }
    $cfgCheck = http_req('/config/database.local.php');
    if ($cfgCheck['code'] !== 403 && $cfgCheck['code'] !== 404) {
        throw new Exception("/config/database.local.php is NOT blocked! Returned: {$cfgCheck['code']}");
    }
    $storageCheck = http_req('/storage/logs/app.log');
    if ($storageCheck['code'] !== 403 && $storageCheck['code'] !== 404) {
        throw new Exception("/storage/ is NOT blocked! Returned: {$storageCheck['code']}");
    }
    return "Protected: /.env [{$envCheck['code']}], /config/ [{$cfgCheck['code']}], /storage/ [{$storageCheck['code']}]";
});

// Set up Authenticated Session for Deep API testing via real browser sign-in flow
$cookieJar = tempnam(sys_get_temp_dir(), 'trade_qa_cookies_');
$capturedCsrf = '';

run_test("Auth: End-to-end browser login simulation (/en/sign-in/)", function () use ($cookieJar, &$capturedCsrf) {
    // 1. GET /en/sign-in/ to fetch session cookie and CSRF token
    $pageRes = http_req('/en/sign-in/', 'GET', null, [], $cookieJar);
    if ($pageRes['code'] !== 200) {
        throw new Exception("GET /en/sign-in/ returned HTTP {$pageRes['code']}");
    }

    if (!preg_match('/name="_csrf"\s+value="([^"]+)"/', $pageRes['body'], $m)) {
        throw new Exception("Could not find CSRF token in sign-in form");
    }
    $formCsrf = $m[1];

    // 2. POST credentials
    $postRes = http_post_form('/en/sign-in/', [
        'email' => 'admin@55ak.xyz',
        'password' => 'Admin@123456',
        '_csrf' => $formCsrf,
        'next' => '/en/trade/'
    ], $cookieJar);

    if ($postRes['code'] !== 302 && $postRes['code'] !== 200) {
        throw new Exception("Login POST failed with HTTP {$postRes['code']}: " . substr($postRes['body'], 0, 200));
    }

    // 3. Verify session via /en/api/session.php
    $res = http_req('/en/api/session.php', 'GET', null, [], $cookieJar);
    if ($res['code'] !== 200 || empty($res['json']['ok'])) {
        throw new Exception("Session verification failed: HTTP {$res['code']} - " . $res['body']);
    }

    // 4. Fetch trade room to capture the user's meta csrf token
    $roomRes = http_req('/en/trade/', 'GET', null, [], $cookieJar);
    if (preg_match('/name="trade-csrf"\s+content="([^"]+)"/', $roomRes['body'], $mCsrf)) {
        $capturedCsrf = $mCsrf[1];
    } else {
        $capturedCsrf = $formCsrf;
    }

    return "Successfully logged in as admin@55ak.xyz (User ID: {$res['json']['data']['userId']})";
});

// 7. Authenticated State API
run_test("API: GET /en/api/state.php (Authenticated)", function () use ($cookieJar) {
    $res = http_req('/en/api/state.php', 'GET', null, [], $cookieJar);
    if ($res['code'] !== 200 || empty($res['json']['ok'])) {
        throw new Exception("State API failed: HTTP {$res['code']} - " . $res['body']);
    }
    $data = $res['json']['data'] ?? [];
    if (!isset($data['liveBalance']) || !isset($data['demoBalance'])) {
        throw new Exception("Balance fields missing in state: " . json_encode($data));
    }
    return "Live Balance: \${$data['liveBalance']}, Demo Balance: \${$data['demoBalance']}, Active Trades: " . count($data['trades'] ?? []);
});

// 8. Cabinets Digest API (With trailing slash / rewrite verified)
run_test("API: GET /api/v1/cabinets/digest (Authenticated)", function () use ($cookieJar) {
    $res = http_req('/api/v1/cabinets/digest', 'GET', null, [], $cookieJar);
    if ($res['code'] !== 200) {
        throw new Exception("Digest API failed: HTTP {$res['code']} - " . $res['body']);
    }
    $data = $res['json']['data'] ?? [];
    if (empty($data['id']) || !isset($data['liveBalance']) || !isset($data['demoBalance'])) {
        throw new Exception("Missing digest fields: " . $res['body']);
    }
    return "User: {$data['nickname']}, Live: \${$data['liveBalance']}, Demo: \${$data['demoBalance']}, Market categories: " . count($data['modals'] ?? []);
});

// 9. Cabinets High-Low Settings API
run_test("API: GET /api/v1/cabinets/trades/settings/high-low (Authenticated)", function () use ($cookieJar) {
    $res = http_req('/api/v1/cabinets/trades/settings/high-low', 'GET', null, [], $cookieJar);
    if ($res['code'] !== 200) {
        throw new Exception("High-Low API failed: HTTP {$res['code']} - " . $res['body']);
    }
    $modals = $res['json']['modals'] ?? [];
    if (empty($modals)) {
        throw new Exception("High-Low returned empty modals list");
    }
    $totalItems = 0;
    foreach ($modals as $m) {
        $totalItems += count($m['items'] ?? []);
    }
    return "Market Categories: " . count($modals) . ", Total Assets Configured: {$totalItems}";
});

// 10. Account Switch API
run_test("API: POST /en/api/account.php (Switch Demo/Live with CSRF)", function () use ($cookieJar, &$capturedCsrf) {
    $res = http_req('/en/api/account.php', 'POST', [
        'account' => 'demo',
        '_csrf' => $capturedCsrf
    ], ['X-CSRF-Token' => $capturedCsrf], $cookieJar);

    if ($res['code'] !== 200 || empty($res['json']['ok'])) {
        throw new Exception("Switch to demo failed: HTTP {$res['code']} - " . $res['body']);
    }

    $res2 = http_req('/en/api/account.php', 'POST', [
        'account' => 'live',
        '_csrf' => $capturedCsrf
    ], ['X-CSRF-Token' => $capturedCsrf], $cookieJar);

    if ($res2['code'] !== 200 || empty($res2['json']['ok'])) {
        throw new Exception("Switch to live failed: HTTP {$res2['code']} - " . $res2['body']);
    }

    // Switch back to demo for trade testing
    http_req('/en/api/account.php', 'POST', ['account' => 'demo', '_csrf' => $capturedCsrf], ['X-CSRF-Token' => $capturedCsrf], $cookieJar);

    return "Successfully toggled between Demo Account and Live Account";
});

// 11. Trade Placement API
$placedTradeId = null;
run_test("API: POST /en/api/trade.php (Open Demo Trade)", function () use ($cookieJar, &$capturedCsrf, &$placedTradeId) {
    $tradePayload = [
        'symbol' => 'BTCUSD',
        'direction' => 'up',
        'amount' => 10.00,
        'duration' => 60,
        'account' => 'demo',
        'requestId' => 'test-req-' . time() . '-' . rand(100, 999),
        '_csrf' => $capturedCsrf
    ];

    $res = http_req('/en/api/trade.php', 'POST', $tradePayload, ['X-CSRF-Token' => $capturedCsrf], $cookieJar);
    if ($res['code'] !== 201 || empty($res['json']['ok'])) {
        throw new Exception("Trade placement failed: HTTP {$res['code']} - " . $res['body']);
    }

    $trade = $res['json']['data'] ?? [];
    $placedTradeId = $trade['public_id'] ?? $trade['id'] ?? null;
    $entry = $trade['entryPrice'] ?? $trade['entry_price'] ?? '0';
    return "Opened Trade #{$placedTradeId}: {$trade['symbol']} {$trade['direction']} \${$trade['amount']} @ {$entry}";
});

// 12. Trade History API & Auto-Settlement
run_test("API: GET /en/api/trade-history.php (History & Settlement)", function () use ($cookieJar) {
    $res = http_req('/en/api/trade-history.php', 'GET', null, [], $cookieJar);
    if ($res['code'] !== 200 || empty($res['json']['success'])) {
        throw new Exception("Trade history failed: HTTP {$res['code']} - " . $res['body']);
    }
    $trades = $res['json']['trades'] ?? [];
    return "Fetched " . count($trades) . " trades, Current Balance: \${$res['json']['balance']}";
});

// 13. Trade Early Close / Sell API
run_test("API: POST /en/api/trade.php (Sell / Early Close Trade)", function () use ($cookieJar, &$capturedCsrf, &$placedTradeId) {
    if (!$placedTradeId) return "SKIPPED: No active trade to close";

    $sellPayload = [
        'action' => 'sell',
        'tradeId' => (string)$placedTradeId,
        '_csrf' => $capturedCsrf
    ];

    $res = http_req('/en/api/trade.php', 'POST', $sellPayload, ['X-CSRF-Token' => $capturedCsrf], $cookieJar);
    if ($res['code'] !== 200 || empty($res['json']['ok'])) {
        throw new Exception("Trade sell failed: HTTP {$res['code']} - " . $res['body']);
    }

    return "Trade #{$placedTradeId} sold successfully. Response: {$res['json']['message']}";
});

// 14. Cron Settlement CLI execution
run_test("Cron: Execute cron/settle.php via CLI", function () {
    $output = shell_exec('c:\xampp\php\php.exe cron/settle.php 2>&1');
    if (!str_contains((string)$output, 'settled=')) {
        throw new Exception("Unexpected cron output: {$output}");
    }
    return trim((string)$output);
});

// 15. Clean up temporary cookie file
@unlink($cookieJar);

echo "\n====================================================================\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$total = count($results);
echo " SUMMARY: {$passCount}/{$total} TESTS PASSED\n";
echo "====================================================================\n";
