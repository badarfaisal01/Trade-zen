<?php
require __DIR__ . '/../app/bootstrap.php';

// Helper to simulate yahoo candles for EURUSD
function test_fetch_yahoo_forex_candles(string $symbol, int $intervalSeconds = 60, int $count = 100): array
{
    $cleanSym = normalize_market_api_symbol($symbol);
    $yfSym = $cleanSym . '=X';
    
    $yfInterval = '1m';
    $range = '2d';
    if ($intervalSeconds >= 86400) { $yfInterval = '1d'; $range = '1y'; }
    elseif ($intervalSeconds >= 3600) { $yfInterval = '60m'; $range = '1mo'; }
    elseif ($intervalSeconds >= 900) { $yfInterval = '15m'; $range = '5d'; }
    elseif ($intervalSeconds >= 300) { $yfInterval = '5m'; $range = '5d'; }
    
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($yfSym) . "?interval={$yfInterval}&range={$range}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $res = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode((string)$res, true);
    $result = $data['chart']['result'][0] ?? null;
    if (!$result) return [];
    
    $timestamps = $result['timestamp'] ?? [];
    $quotes = $result['indicators']['quote'][0] ?? [];
    $opens = $quotes['open'] ?? [];
    $highs = $quotes['high'] ?? [];
    $lows = $quotes['low'] ?? [];
    $closes = $quotes['close'] ?? [];
    
    $candles = [];
    $len = count($timestamps);
    for ($i = 0; $i < $len; $i++) {
        if (!isset($opens[$i], $closes[$i]) || $opens[$i] === null || $closes[$i] === null) continue;
        $t = (int)$timestamps[$i] * 1000;
        $o = (float)$opens[$i];
        $h = (float)($highs[$i] ?? $o);
        $l = (float)($lows[$i] ?? $o);
        $c = (float)$closes[$i];
        
        $candles[] = [
            'openTime' => $t,
            'open' => round($o, 5),
            'high' => round($h, 5),
            'low' => round($l, 5),
            'close' => round($c, 5),
            'closeTime' => $t + ($intervalSeconds * 1000) - 1,
        ];
    }
    return array_slice($candles, -$count);
}

$candles = test_fetch_yahoo_forex_candles('EURUSD', 60, 20);
echo "Fetched " . count($candles) . " Yahoo EURUSD candles:\n";
foreach ($candles as $c) {
    echo date('Y-m-d H:i:s', (int)($c['openTime']/1000)) . " | O: {$c['open']} | H: {$c['high']} | L: {$c['low']} | C: {$c['close']}\n";
}
