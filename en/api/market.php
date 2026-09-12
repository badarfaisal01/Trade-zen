<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_user();
session_write_close();

$symbol = strtoupper(trim((string)($_GET['symbol'] ?? 'EURUSD')));
$interval = (int)($_GET['interval'] ?? 5);
$count = min(10000, max(10, (int)($_GET['count'] ?? 10000)));
$includeCandles = (string)($_GET['history'] ?? '1') !== '0';
$endMs = isset($_GET['endMs']) && is_numeric($_GET['endMs']) ? (int)$_GET['endMs'] : (isset($_GET['before']) && is_numeric($_GET['before']) ? (int)$_GET['before'] : null);

try {
    json_response(['ok' => true, 'data' => market_snapshot($symbol, $interval, $count, $includeCandles, $endMs)]);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
}
