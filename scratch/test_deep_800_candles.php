<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/market.php';

$testSymbols = ['BTCUSD', 'EURUSD', 'XAUUSD', 'ETHUSD', 'SOLUSD', 'GBPUSD'];
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

        $timeGaps = 0;
        $priceJumps = 0;
        $maxPriceJump = 0.0;
        $expectedStep = $interval * 1000;

        for ($i = 1; $i < $count; $i++) {
            $diffTime = $candles[$i]['time'] - $candles[$i - 1]['time'];
            if ($diffTime !== $expectedStep) {
                $timeGaps++;
            }
            $openDiff = abs($candles[$i]['open'] - $candles[$i - 1]['close']);
            if ($openDiff > ($candles[$i]['open'] * 0.03)) { // Jump > 3%
                $priceJumps++;
                if ($openDiff > $maxPriceJump) $maxPriceJump = $openDiff;
            }
        }

        echo "Interval {$interval}s: {$count} candles in {$elapsed}ms | Time Gaps: {$timeGaps} | Price Jumps (>3%): {$priceJumps} (max jump: {$maxPriceJump})\n";
    }
}
