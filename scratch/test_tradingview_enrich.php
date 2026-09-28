<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/test_tradingview_candles.php';
function enrich_tradingview_candles(array $rawCandles, int $intervalSeconds, int $seed = 550101, float $volatility = 0.00018, int $decimals = 5): array
{
    $minStep = pow(10, -$decimals); // 0.00001
    $pip = $minStep * 10; // 0.00010 (1 pip = 10 pipettes)
    
    $enriched = [];
    $prevClose = 0.0;
    
    $len = count($rawCandles);
    for ($i = 0; $i < $len; $i++) {
        $c = $rawCandles[$i];
        $t = $c['openTime'] ?? $c['time'];
        $rawO = (float)$c['open'];
        $rawH = (float)$c['high'];
        $rawL = (float)$c['low'];
        $rawC = (float)$c['close'];
        
        $sec = (int)($t / 1000);
        
        // Continuous open chaining
        if ($i === 0 || $prevClose <= 0) {
            $open = $rawO;
        } else {
            $open = $prevClose;
        }
        
        // Micro-wave oscillation based on time and seed
        $noiseClose = market_noise($seed + 41, $sec);
        $noiseWickH = abs(market_noise($seed + 73, $sec));
        $noiseWickL = abs(market_noise($seed + 97, $sec));
        
        // Macro delta from raw candle
        $macroDelta = ($rawC - $rawO);
        
        // If raw candle was flat (or sub-minute interval), create organic pipette movement
        $microDelta = ($noiseClose * $minStep * ($intervalSeconds < 60 ? 3.5 : 6.0));
        
        $targetClose = $open + ($macroDelta * 0.4) + $microDelta;
        
        // Enforce visible body (at least 1-3 pipettes)
        $minBody = $minStep * ($intervalSeconds < 60 ? 1.0 : 2.0);
        if (abs($targetClose - $open) < $minBody) {
            $targetClose = ($noiseClose >= 0) ? ($open + $minBody) : ($open - $minBody);
        }
        $close = round($targetClose, $decimals);
        
        // TradingView-style wicks (shadows):
        // Upper and lower shadows reflecting liquidity and spread testing
        $bodySpan = abs($close - $open);
        $baseWick = max($minStep * 2.0, $bodySpan * 0.4 + ($open * $volatility * 0.04));
        
        $wickUp = $baseWick * (0.4 + $noiseWickH * 0.9);
        $wickDown = $baseWick * (0.4 + $noiseWickL * 0.9);
        
        $high = round(max($open, $close, $rawH) + $wickUp, $decimals);
        $low = round(min($open, $close, $rawL) - $wickDown, $decimals);
        
        // Clamp low > 0
        $low = max($minStep, $low);
        $high = max($high, max($open, $close) + $minStep);
        $low = min($low, min($open, $close) - $minStep);
        
        $enriched[] = [
            'time' => $t,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'lastTickTime' => $t + ($intervalSeconds * 1000) - 1,
            'complete' => true,
        ];
        
        $prevClose = $close;
    }
    
    return $enriched;
}

$raw = test_fetch_yahoo_forex_candles('EURUSD', 60, 20);
$enriched = enrich_tradingview_candles($raw, 60, 550101, 0.00018, 5);

echo "Enriched TradingView EURUSD candles:\n";
foreach ($enriched as $c) {
    $body = round(($c['close'] - $c['open']) * 100000, 1);
    $range = round(($c['high'] - $c['low']) * 100000, 1);
    echo sprintf(
        "%s | O: %.5f | H: %.5f | L: %.5f | C: %.5f | Body: %+4.1f pips | Range: %4.1f pips\n",
        date('Y-m-d H:i:s', (int)($c['time']/1000)),
        $c['open'],
        $c['high'],
        $c['low'],
        $c['close'],
        $body / 10.0,
        $range / 10.0
    );
}
