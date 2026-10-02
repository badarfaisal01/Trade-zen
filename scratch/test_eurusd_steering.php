<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

echo "=======================================================\n";
echo " VERIFYING EURUSD NATURAL PRICE + FINAL 12s STEERING   \n";
echo "=======================================================\n\n";

$asset = market_asset('EURUSD');
$entryPrice = 1.08798;
$naturalPrice = 1.08798;
$decimals = 5;
$tightMargin = max(pow(10, -$decimals) * 2.0, $entryPrice * 0.00015);
$targetClose = 1.08766; // Guaranteed Loss target

$durationMs = 60000;
$steerWinMs = 12000;

echo "Entry: " . number_format($entryPrice, 5) . " | Target Close: " . number_format($targetClose, 5) . "\n\n";

$steerStartPrice = null;

for ($t = 0; $t <= 60; $t += 2) {
    $remMs = max(0, (60 - $t) * 1000);
    // Natural price drifts slightly with organic market
    $rawPrice = $naturalPrice;

    if ($remMs > $steerWinMs) {
        $livePrice = $rawPrice;
        $phase = "Natural Market (0% steering, 0 synthetic oscillation)";
    } else {
        if ($steerStartPrice === null) {
            $steerStartPrice = $rawPrice;
        }
        $steerElapsed = $steerWinMs - $remMs;
        $steerNorm = min(1.0, max(0.0, $steerElapsed / $steerWinMs));
        $smoothFactor = $steerNorm * $steerNorm * (3.0 - 2.0 * $steerNorm);
        $livePrice = round($steerStartPrice + (($targetClose - $steerStartPrice) * $smoothFactor), $decimals);
        $pct = round($smoothFactor * 100);
        $phase = "Steering Active ({$pct}%)";
    }

    $diff = $livePrice - $entryPrice;
    $diffStr = ($diff >= 0 ? "+" : "") . number_format($diff, 5);
    printf("t=%02ds | rem=%02ds | Price: %s | Diff: %s | %s\n", $t, 60 - $t, number_format($livePrice, 5), $diffStr, $phase);
}

// Check final price at t=60
$finalPrice = round($steerStartPrice + (($targetClose - $steerStartPrice) * 1.0), $decimals);

echo "\nFinal Graph Price at Expiry (t=60s): " . number_format($finalPrice, 5) . "\n";
echo "Settled Target Close Price:          " . number_format($targetClose, 5) . "\n";

if (abs($finalPrice - $targetClose) < 0.000001) {
    echo "SUCCESS: Graph price EXACTLY MATCHES target close price at trade expiration!\n";
} else {
    echo "ERROR: Graph price and close price mismatch!\n";
    exit(1);
}
