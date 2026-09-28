<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/test_tradingview_candles.php';

function generate_subminute_tradingview_candles(array $oneMinCandles, int $intervalSeconds, int $seed = 550101, float $volatility = 0.00018, int $decimals = 5): array
{
    $minStep = pow(10, -$decimals); // 0.00001
    $intervalMs = $intervalSeconds * 1000;
    
    // Index 1m candles by openTime
    $bMap = [];
    foreach ($oneMinCandles as $c) {
        $bMap[(int)$c['openTime']] = $c;
    }
    $sortedTimes = array_keys($bMap);
    sort($sortedTimes);
    if (empty($sortedTimes)) return [];
    
    $first1m = $sortedTimes[0];
    $last1m = end($sortedTimes);
    $endMs = $last1m + 60000;
    $startMs = max($first1m, $endMs - (20 * $intervalMs));
    
    $candles = [];
    $prevClose = (float)$bMap[$first1m]['open'];
    
    for ($t = $startMs; $t < $endMs; $t += $intervalMs) {
        $floorMin = (int)(floor($t / 60000) * 60000);
        $parent = $bMap[$floorMin] ?? null;
        if (!$parent) {
            // Find closest prior parent
            foreach ($sortedTimes as $st) {
                if ($st <= $t) $parent = $bMap[$st];
                else break;
            }
        }
        if (!$parent) continue;
        
        $bOpen = (float)$parent['open'];
        $bClose = (float)$parent['close'];
        $bHigh = (float)$parent['high'];
        $bLow = (float)$parent['low'];
        
        $open = ($candles === [] || $prevClose <= 0) ? $bOpen : $prevClose;
        
        $secInMin = (int)(($t - $floorMin) / 1000) % 60;
        $progress = min(1.0, max(0.0, ($secInMin + $intervalSeconds) / 60.0));
        
        $sec = (int)($t / 1000);
        $microNoise = market_noise($seed + 19, $sec);
        $wickNoiseH = abs(market_noise($seed + 43, $sec));
        $wickNoiseL = abs(market_noise($seed + 67, $sec));
        
        // Micro-wave within the minute
        $subWave = sin($sec / 4.0) * ($minStep * 2.0) + ($microNoise * $minStep * 1.5);
        $targetClose = $bOpen + (($bClose - $bOpen) * $progress) + $subWave;
        
        // Ensure non-zero body
        $minBody = $minStep * 0.8;
        if (abs($targetClose - $open) < $minBody) {
            $targetClose = ($microNoise >= 0) ? ($open + $minBody) : ($open - $minBody);
        }
        $close = round($targetClose, $decimals);
        
        $bodySpan = abs($close - $open);
        $baseWick = max($minStep * 1.5, $bodySpan * 0.35 + ($open * $volatility * 0.02));
        
        $wickUp = $baseWick * (0.3 + $wickNoiseH * 0.7);
        $wickDown = $baseWick * (0.3 + $wickNoiseL * 0.7);
        
        $high = round(max($open, $close) + $wickUp, $decimals);
        $low = round(max($minStep, min($open, $close) - $wickDown), $decimals);
        
        $candles[] = [
            'time' => $t,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
        ];
        $prevClose = $close;
    }
    
    return $candles;
}

$raw = test_fetch_yahoo_forex_candles('EURUSD', 60, 5);
$sub5s = generate_subminute_tradingview_candles($raw, 5, 550101, 0.00018, 5);

echo "Enriched 5-second EURUSD candles:\n";
foreach ($sub5s as $c) {
    $body = round(($c['close'] - $c['open']) * 100000, 1);
    $range = round(($c['high'] - $c['low']) * 100000, 1);
    echo sprintf(
        "%s | O: %.5f | H: %.5f | L: %.5f | C: %.5f | Body: %+4.1f pips | Range: %4.1f pips\n",
        date('H:i:s', (int)($c['time']/1000)),
        $c['open'],
        $c['high'],
        $c['low'],
        $c['close'],
        $body / 10.0,
        $range / 10.0
    );
}
