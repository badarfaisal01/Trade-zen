<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/market.php';

$testSymbols = ['BTCUSD', 'EURUSD', 'ETHUSD'];
$testIntervals = [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600];

$db = db();
foreach ($testSymbols as $sym) {
    $stmt = $db->prepare("SELECT * FROM market_assets WHERE symbol = ? LIMIT 1");
    $stmt->execute([$sym]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) continue;

    echo "\n================== Symbol: {$sym} (800 candles) ==================\n";
    foreach ($testIntervals as $interval) {
        $start = microtime(true);
        $candles = market_candles($asset, $interval, 800);
        $elapsed = round((microtime(true) - $start) * 1000, 1);
        $count = count($candles);
        
        $gaps = [];
        $expectedStep = $interval * 1000;
        for ($i = 1; $i < $count; $i++) {
            $diff = $candles[$i]['time'] - $candles[$i - 1]['time'];
            if ($diff !== $expectedStep) {
                $gaps[] = "Gap at index {$i}: prev={$candles[$i-1]['time']}, curr={$candles[$i]['time']}, diff={$diff}ms (expected {$expectedStep}ms)";
            }
        }
        
        // Also check tick gap inside candles or tick monotonicity
        $tickGaps = 0;
        $prevTick = -INF;
        foreach ($candles as $c) {
            foreach ($c['ticks'] as $t) {
                if ($t[0] <= $prevTick) {
                    $tickGaps++;
                }
                $prevTick = $t[0];
            }
        }
        
        echo "Interval {$interval}s: {$count} candles in {$elapsed}ms | Gaps: " . count($gaps) . " | Tick order issues: {$tickGaps}\n";
        if (count($gaps) > 0) {
            foreach (array_slice($gaps, 0, 3) as $g) {
                echo "   {$g}\n";
            }
        }
    }
}
