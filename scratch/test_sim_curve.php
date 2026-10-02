<?php
// Simulate the price curve from t=0 to t=60s for an UP trade with 10% win rate (Loss)
$entryPrice = 85280.00;
$decimals = 2;
$minStep = 0.01;
$targetMargin = max($minStep * 2.0, $entryPrice * 0.00015) * 1.4; // ~17.90
$targetPrice = $entryPrice - $targetMargin; // ~85262.10

echo "Entry Price: $entryPrice\n";
echo "Target Margin: $targetMargin\n";
echo "Target Price (Loss): $targetPrice\n\n";

$duration = 60.0;
$steerWindow = 12.0;

for ($s = 0; $s <= 65; $s += 5) {
    $remaining = max(0, 60 - $s);
    // Simulate raw Binance market drifting up
    $rawBinance = $entryPrice + 14.0 + sin($s * 0.5) * 6.0;
    
    if ($s <= 60) {
        if ($remaining > $steerWindow) {
            // Early / Mid: oscillate around entry with raw volatility
            $rawDelta = $rawBinance - $entryPrice;
            $osc = sin($s * 2.2) * ($targetMargin * 0.35);
            $p = $entryPrice + ($rawDelta * 0.4) + $osc;
        } else {
            // Final steer window
            $steerElapsed = $steerWindow - $remaining;
            $steerNorm = $steerElapsed / $steerWindow;
            $smooth = $steerNorm * $steerNorm * (3.0 - 2.0 * $steerNorm);
            
            $startMidPrice = $entryPrice;
            $liveMicro = sin($s * 3.5) * ($targetMargin * 0.08 * (1.0 - $smooth));
            $p = $startMidPrice + (($targetPrice - $startMidPrice) * $smooth) + $liveMicro;
        }
    } else {
        // Post expiry decay
        $elapsedPost = $s - 60;
        $decayNorm = min(1.0, $elapsedPost / 4.0);
        $smoothDecay = $decayNorm * $decayNorm * (3.0 - 2.0 * $decayNorm);
        $p = $targetPrice + (($rawBinance - $targetPrice) * $smoothDecay);
    }
    
    printf("t = %02ds (rem: %02ds) | Binance: %.2f | Live Chart: %.2f | Diff from Entry: %+.2f\n", 
        $s, $remaining, $rawBinance, $p, $p - $entryPrice);
}
