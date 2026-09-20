<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
session_write_close();

$symbol = strtoupper(trim((string)($_GET['symbol'] ?? 'EURUSD')));
$interval = (int)($_GET['interval'] ?? 5);
$count = min(3000, max(10, (int)($_GET['count'] ?? 800)));
$includeCandles = (string)($_GET['history'] ?? '1') !== '0';
$endMs = isset($_GET['endMs']) && is_numeric($_GET['endMs']) ? (int)$_GET['endMs'] : (isset($_GET['before']) && is_numeric($_GET['before']) ? (int)$_GET['before'] : null);

try {
    json_response(['ok' => true, 'data' => market_snapshot($symbol, $interval, $count, $includeCandles, $endMs)]);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Market API error for ' . $symbol . ': ' . $exception->getMessage());
    try {
        $fallbackAsset = market_asset($symbol, false);
        if ($fallbackAsset) {
            $nowMs = (int)floor(microtime(true) * 1000);
            json_response([
                'ok' => true,
                'data' => [
                    'serverTime' => $nowMs,
                    'asset' => market_asset_payload($fallbackAsset, $nowMs),
                    'assets' => [],
                    'candles' => [],
                    'engine' => 'market-engine-fallback'
                ]
            ]);
        }
    } catch (Throwable $fallbackErr) {}
    json_response(['ok' => false, 'message' => 'Market temporarily unavailable.'], 200);
}
