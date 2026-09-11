<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_user();
session_write_close();

$symbol = strtoupper(trim((string)($_GET['symbol'] ?? 'EURUSD')));
$interval = (int)($_GET['interval'] ?? 5);
$count = min(1000, max(10, (int)($_GET['count'] ?? 300)));
$includeCandles = (string)($_GET['history'] ?? '1') !== '0';

try {
    json_response(['ok' => true, 'data' => market_snapshot($symbol, $interval, $count, $includeCandles)]);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
}
