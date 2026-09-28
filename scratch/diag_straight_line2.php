<?php
/**
 * Diagnostic: Check USDCAD candle-to-live-price gap in detail
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$symbol = 'USDCAD';
$asset = market_asset($symbol, false);
$nowMs = (int)floor(microtime(true) * 1000);

echo "=== USDCAD CANDLE DETAIL DIAGNOSTIC ===\n";
echo "Current UTC: " . gmdate('Y-m-d H:i:s') . "\n\n";

// Fetch raw TwelveData candles
$tdKey = (string)env('TWELVEDATA_API_KEY', '');
$apiSym = 'USD/CAD';

echo "--- TwelveData Raw Candles (1min, last 20) ---\n";
try {
    $rawCandles = fetch_twelvedata_candles($apiSym, 60, 800, $nowMs, $tdKey);
    echo "Total raw candles: " . count($rawCandles) . "\n";
    // Show last 20
    $last20 = array_slice($rawCandles, -20);
    foreach ($last20 as $c) {
        echo "  " . gmdate('H:i:s', (int)($c['openTime']/1000)) . " UTC  O=" . $c['open'] . "  H=" . $c['high'] . "  L=" . $c['low'] . "  C=" . $c['close'] . "\n";
    }
    
    // Check how old the last candle is
    $lastRaw = end($rawCandles);
    $lastCandleAgeSeconds = ($nowMs - $lastRaw['openTime']) / 1000;
    echo "\nLast candle age: " . number_format($lastCandleAgeSeconds, 0) . " seconds (" . number_format($lastCandleAgeSeconds/60, 1) . " minutes)\n";
    echo "Last candle close: " . $lastRaw['close'] . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Now check what market_candles produces
echo "\n--- market_candles output (interval=60, last 20) ---\n";
try {
    $candles = market_candles($asset, 60, 800, $nowMs);
    echo "Total generated candles: " . count($candles) . "\n";
    $last20gen = array_slice($candles, -20);
    foreach ($last20gen as $c) {
        echo "  " . gmdate('H:i:s', (int)($c['time']/1000)) . " UTC  O=" . $c['open'] . "  C=" . $c['close'] . "  complete=" . ($c['complete'] ? 'Y' : 'N') . "\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check what market_snapshot produces (the actual API response)
echo "\n--- market_snapshot comparison ---\n";
try {
    $snap = market_snapshot($symbol, 60, 800, true, null, null);
    $snapCandles = $snap['candles'];
    echo "Snapshot candle count: " . count($snapCandles) . "\n";
    echo "Snapshot asset price: " . ($snap['asset']['price'] ?? 'N/A') . "\n";
    
    if (!empty($snapCandles)) {
        $lastSnap = end($snapCandles);
        echo "Last snapshot candle: " . gmdate('H:i:s', (int)($lastSnap['time']/1000)) . " UTC  C=" . $lastSnap['close'] . "  complete=" . ($lastSnap['complete'] ? 'Y' : 'N') . "\n";
        
        $gap = abs(($snap['asset']['price'] ?? 0) - (float)$lastSnap['close']);
        $gapPct = (float)$lastSnap['close'] > 0 ? $gap / (float)$lastSnap['close'] * 100 : 0;
        echo "GAP asset.price vs last candle: " . $gap . " (" . number_format($gapPct, 4) . "%)\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Also check what happens with interval=300 (5-minute candles)
echo "\n--- Same for 5min interval ---\n";
try {
    $snap5 = market_snapshot($symbol, 300, 800, true, null, null);
    $snapCandles5 = $snap5['candles'];
    echo "5min candle count: " . count($snapCandles5) . "\n";
    if (!empty($snapCandles5)) {
        $last5 = array_slice($snapCandles5, -5);
        foreach ($last5 as $c) {
            echo "  " . gmdate('H:i:s', (int)($c['time']/1000)) . " UTC  O=" . $c['open'] . "  C=" . $c['close'] . "  complete=" . ($c['complete'] ? 'Y' : 'N') . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
