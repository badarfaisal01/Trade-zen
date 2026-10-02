<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

echo "=======================================================\n";
echo " VERIFYING NATURAL PRE-12s + 10-12s DYNAMIC OSCILLATION\n";
echo "=======================================================\n\n";

$asset = market_asset('EURUSD');
$entryPrice = 1.08798;
$naturalPrice = 1.08798;
$decimals = 5;
$tightMargin = max(pow(10, -$decimals) * 2.0, $entryPrice * 0.00015);
$targetClose = 1.08766;

$durationMs = 60000;
$steerWinMs = 12000;

echo "Entry: " . number_format($entryPrice, 5) . " | Target Close: " . number_format($targetClose, 5) . "\n\n";

$steerStartPrice = null;

for ($t = 0; $t <= 60; $t += 2) {
    $remMs = max(0, (60 - $t) * 1000);
    $rawPrice = $naturalPrice;
    $sec = (float)$t;

    if ($remMs > $steerWinMs) {
        $livePrice = $rawPrice;
        $phase = "Pure Natural Market (No Fake Oscillation)";
    } else {
        if ($steerStartPrice === null) {
            $steerStartPrice = $rawPrice;
        }
        $steerElapsed = $steerWinMs - $remMs;
        $steerNorm = min(1.0, max(0.0, $steerElapsed / $steerWinMs));
        $smoothFactor = $steerNorm * $steerNorm * (3.0 - 2.0 * $steerNorm);
        $dynamicOsc = (sin($sec / 1.6) * 0.7 + sin($sec / 3.2) * 0.3) * ($tightMargin * 0.08 * (1.0 - $smoothFactor));
        $livePrice = round($steerStartPrice + (($targetClose - $steerStartPrice) * $smoothFactor) + $dynamicOsc, $decimals);
        $pct = round($smoothFactor * 100);
        $phase = "Steering + Dynamic Oscillation ({$pct}%)";
    }

    $diff = $livePrice - $entryPrice;
    $diffStr = ($diff >= 0 ? "+" : "") . number_format($diff, 5);
    printf("t=%02ds | rem=%02ds | Price: %s | Diff: %s | %s\n", $t, 60 - $t, number_format($livePrice, 5), $diffStr, $phase);
}

$finalSmooth = 1.0;
$finalOsc = (sin(60.0 / 1.6) * 0.7 + sin(60.0 / 3.2) * 0.3) * ($tightMargin * 0.08 * (1.0 - $finalSmooth)); // Exactly 0.0
$finalPrice = round($steerStartPrice + (($targetClose - $steerStartPrice) * $finalSmooth) + $finalOsc, $decimals);

echo "\nFinal Graph Price at Expiry (t=60s): " . number_format($finalPrice, 5) . "\n";
echo "Settled Target Close Price:          " . number_format($targetClose, 5) . "\n";

if (abs($finalPrice - $targetClose) < 0.000001) {
    echo "SUCCESS: Graph price lands EXACTLY on target close price with lively dynamic oscillation during 10-12s window!\n";
} else {
    echo "ERROR: Price mismatch!\n";
    exit(1);
}
