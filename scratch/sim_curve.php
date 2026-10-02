<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$asset = market_asset('EURUSD', false);
$userId = 1;
$assetId = (int)$asset['id'];
$decimals = 5;
$tick = 0.00001;
$entryPrice = 1.08500;
$direction = 'up';
$outcome = 'won'; // or lost

$targetMargin = max($tick * 2.5, $entryPrice * 0.00020);
$targetPrice = ($outcome === 'lost') ? ($entryPrice - $targetMargin) : ($entryPrice + $targetMargin);

$duration = 60; // 60s trade
$openedAtMs = 1000000;
$expiresAtMs = $openedAtMs + ($duration * 1000);

echo "Simulating 60s Trade Price Action (UP trade, Outcome = WON, Entry = {$entryPrice}, Target = {$targetPrice}):\n\n";

for ($s = 0; $s <= 65; $s += 5) {
    $tMs = $openedAtMs + ($s * 1000);
    $elapsedMs = max(0, $tMs - $openedAtMs);
    $remainingMs = max(0, $expiresAtMs - $tMs);
    $isDecaying = ($tMs > $expiresAtMs);
    
    // Natural market baseline
    $sec = $tMs / 1000.0;
    $naturalPrice = $entryPrice + sin($sec / 3.5) * 0.00015;
    
    if (!$isDecaying) {
        $rawProgress = min(1.0, max(0.0, $elapsedMs / ($duration * 1000.0)));
        
        // Steering factor: gentle 0..0.2 early on with oscillations, ramping up smoothly in last 12-15s to 1.0
        // S-curve / Hermite ramp that activates prominently in the final 25% (last 15s)
        $steerWindowMs = min(15000.0, max(5000.0, $duration * 1000.0 * 0.30));
        
        if ($remainingMs > $steerWindowMs) {
            // Early phase: natural price + subtle market oscillation around trade
            $earlyProgress = min(1.0, $elapsedMs / max(1.0, ($duration * 1000.0) - $steerWindowMs));
            $oscillation = sin($sec * 2.2) * ($targetMargin * 0.25 * (1.0 - $earlyProgress * 0.3));
            $steerWeight = pow($rawProgress, 1.8) * 0.25;
            $price = $naturalPrice + (($targetPrice - $naturalPrice) * $steerWeight) + $oscillation;
        } else {
            // Final 12-15s phase: decisive smooth transition to targetPrice
            $steerElapsed = $steerWindowMs - $remainingMs;
            $steerNorm = min(1.0, max(0.0, $steerElapsed / $steerWindowMs));
            // Hermite smoothstep 0.25 -> 1.0
            $smoothFactor = 0.25 + (0.75 * ($steerNorm * $steerNorm * (3.0 - 2.0 * $steerNorm)));
            $micro = sin($sec * 2.8) * ($targetMargin * 0.08 * (1.0 - $steerNorm));
            $price = $naturalPrice + (($targetPrice - $naturalPrice) * $smoothFactor) + $micro;
        }
    } else {
        // Post-expiry decay
        $elapsedPost = min(3000.0, (float)($tMs - $expiresAtMs));
        $postNorm = $elapsedPost / 3000.0;
        $decayFactor = $postNorm * $postNorm * (3.0 - 2.0 * $postNorm);
        $price = $targetPrice + (($naturalPrice - $targetPrice) * $decayFactor);
    }
    
    $pRound = round($price, $decimals);
    $diffFromEntry = round($pRound - $entryPrice, $decimals);
    printf("t = %2ds (rem %2ds) | Price: %0.5f | Diff: %+0.5f | %s\n", 
        $s, 
        max(0, (int)($remainingMs / 1000)), 
        $pRound, 
        $diffFromEntry,
        ($s <= 45 ? "Early/Mid Oscillations" : ($s <= 60 ? ">> FINAL STEERING <<" : "Decaying back to market"))
    );
}
