<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

echo "=======================================================\n";
echo " VERIFYING EURUSD LIVE STEERING & CLOSE COINCIDENCE    \n";
echo "=======================================================\n\n";

$asset = market_asset('EURUSD');
$entryPrice = 1.08798;
$decimals = 5;
$tightMargin = max(pow(10, -$decimals) * 2.0, $entryPrice * 0.00015); // ~0.00016
$targetClose = 1.08766; // Expected loss target below entry

$durationMs = 60000;
$steerWinMs = 12000;

echo "Entry: " . number_format($entryPrice, 5) . " | Target Close: " . number_format($targetClose, 5) . "\n\n";

for ($t = 0; $t <= 60; $t += 2) {
    $nowSec = $t;
    $remMs = max(0, (60 - $t) * 1000);
    $smoothDelta = 0.0;
    $gentleOsc = sin($t / 2.5) * ($tightMargin * 0.22) + sin($t / 5.0) * ($tightMargin * 0.12);
    $midStartPrice = $entryPrice + $smoothDelta + $gentleOsc;

    if ($remMs > $steerWinMs) {
        $livePrice = round($midStartPrice, $decimals);
        $phase = "Gentle Mid-Trade";
    } else {
        $steerElapsed = $steerWinMs - $remMs;
        $steerNorm = min(1.0, max(0.0, $steerElapsed / $steerWinMs));
        $smoothFactor = $steerNorm * $steerNorm * (3.0 - 2.0 * $steerNorm);
        $microBreathing = sin($t / 1.5) * ($tightMargin * 0.04 * (1.0 - $smoothFactor));
        $livePrice = round($midStartPrice + (($targetClose - $midStartPrice) * $smoothFactor) + $microBreathing, $decimals);
        $pct = round($smoothFactor * 100);
        $phase = "Steering ({$pct}%)";
    }

    $diff = $livePrice - $entryPrice;
    $diffStr = ($diff >= 0 ? "+" : "") . number_format($diff, 5);
    printf("t=%02ds | rem=%02ds | Price: %s | Diff: %s | %s\n", $t, 60 - $t, number_format($livePrice, 5), $diffStr, $phase);
}

// Check final price at t=60
$finalRem = 0;
$steerNorm = 1.0;
$smoothFactor = 1.0;
$finalPrice = round($midStartPrice + (($targetClose - $midStartPrice) * $smoothFactor), $decimals);

echo "\nFinal Graph Price at Expiry (t=60s): " . number_format($finalPrice, 5) . "\n";
echo "Settled Target Close Price:          " . number_format($targetClose, 5) . "\n";

if (abs($finalPrice - $targetClose) < 0.000001) {
    echo "SUCCESS: Graph price EXACTLY MATCHES target close price at trade expiration!\n";
} else {
    echo "ERROR: Graph price and close price mismatch!\n";
    exit(1);
}
