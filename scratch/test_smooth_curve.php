<?php
$entryPrice = 85500.00;
$decimals = 2;
$minStep = 0.01;
$tightMargin = max($minStep * 2.0, $entryPrice * 0.00015); // ~12.82
$targetClose = $entryPrice - $tightMargin; // ~85487.18 (Loss on UP trade)

echo "Entry Price: $entryPrice\n";
echo "Tight Margin: $tightMargin\n";
echo "Target Close: $targetClose\n\n";

$steerWin = 12.0; // 12 seconds

for ($s = 0; $s <= 60; $s += 2) {
    $sec = (float)$s;
    $rem = max(0.0, 60.0 - $sec);
    $rawBinance = $entryPrice + sin($sec / 8.0) * 15.0; // gentle Binance market movement
    $smoothDelta = ($rawBinance - $entryPrice) * 0.15;
    $gentleOsc = sin($sec / 2.5) * ($tightMargin * 0.22) + sin($sec / 5.0) * ($tightMargin * 0.12);
    $midStartPrice = $entryPrice + $smoothDelta + $gentleOsc;

    if ($rem > $steerWin) {
        $price = $midStartPrice;
        $mode = "Mid-Trade (Gentle)";
    } else {
        $steerElapsed = $steerWin - $rem;
        $steerNorm = min(1.0, max(0.0, $steerElapsed / $steerWin));
        $smoothFactor = $steerNorm * $steerNorm * (3.0 - 2.0 * $steerNorm);
        $microBreathing = sin($sec / 1.5) * ($tightMargin * 0.04 * (1.0 - $smoothFactor));
        $price = $midStartPrice + (($targetClose - $midStartPrice) * $smoothFactor) + $microBreathing;
        $mode = sprintf("Steering (%.0f%%)", $smoothFactor * 100);
    }

    printf("t=%02ds | rem=%02ds | Price: %.2f | Diff: %+.2f | %s\n", 
        $s, $rem, $price, $price - $entryPrice, $mode);
}
