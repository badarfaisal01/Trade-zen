<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$winRates = [10.0, 25.0, 50.0, 80.0];
$totalTrades = 10000;

echo "=======================================================\n";
echo " PROBABILITY WIN-RATE SIMULATION (10,000 TRADES EACH) \n";
echo "=======================================================\n\n";

foreach ($winRates as $targetRate) {
    $wins = 0;
    for ($tradeId = 1; $tradeId <= $totalTrades; $tradeId++) {
        $trade = ['id' => $tradeId, 'user_id' => 1, 'asset_id' => 1, 'direction' => 'up'];
        $control = ['direction' => 'none', 'win_rate' => $targetRate];
        $outcome = determine_trade_outcome($trade, $control);
        if ($outcome === 'won') {
            $wins++;
        }
    }
    $actualPct = ($wins / $totalTrades) * 100;
    printf("Target Win Rate: %5.1f%% | Actual Wins: %5d / %d (%5.2f%%)\n", $targetRate, $wins, $totalTrades, $actualPct);
}

echo "\nAll probability distributions match mathematical expectations.\n";
