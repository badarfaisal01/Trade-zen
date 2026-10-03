<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
session_write_close();

$symbol = strtoupper(trim((string)($_GET['symbol'] ?? 'EURUSD')));
$interval = (int)($_GET['interval'] ?? 5);
$count = min(3000, max(20, (int)($_GET['count'] ?? 800)));
$includeCandles = (string)($_GET['history'] ?? '1') !== '0';
$endMs = isset($_GET['endMs']) && is_numeric($_GET['endMs']) ? (int)$_GET['endMs'] : (isset($_GET['before']) && is_numeric($_GET['before']) ? (int)$_GET['before'] : null);

$nowMs = (int)floor(microtime(true) * 1000);
if ($endMs !== null && ($nowMs - $endMs) < 600000 && !isset($_GET['before'])) {
    $endMs = null;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 && function_exists('current_user')) {
    try {
        $u = current_user();
        $userId = $u ? (int)($u['id'] ?? 0) : 0;
    } catch (Throwable $ignored) {
        $userId = 0;
    }
}

try {
    json_response(['ok' => true, 'data' => market_snapshot($symbol, $interval, $count, $includeCandles, $endMs, $userId)]);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Market API error for ' . $symbol . ': ' . $exception->getMessage());
    try {
        $fallbackSnap = market_snapshot($symbol, $interval, $count, false, $endMs, $userId);
        json_response(['ok' => true, 'data' => $fallbackSnap]);
    } catch (Throwable $e2) {
        json_response(['ok' => false, 'message' => $exception->getMessage()], 200);
    }
}
