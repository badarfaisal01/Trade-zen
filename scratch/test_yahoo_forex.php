<?php
require __DIR__ . '/../app/bootstrap.php';

$symbols = ['EURUSD=X', 'GBPUSD=X', 'USDJPY=X', 'AUDUSD=X', 'USDCAD=X'];

foreach ($symbols as $sym) {
    echo "======================= $sym =======================\n";
    $yfUrl = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($sym) . "?interval=1m&range=1d";
    $ch = curl_init($yfUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $res = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode((string)$res, true);
    $result = $data['chart']['result'][0] ?? null;
    if ($result) {
        $meta = $result['meta'] ?? [];
        $regularPrice = $meta['regularMarketPrice'] ?? null;
        $timestamps = $result['timestamp'] ?? [];
        $indicators = $result['indicators']['quote'][0] ?? [];
        $count = count($timestamps);
        echo "Market Price: " . $regularPrice . "\n";
        echo "Total 1m candles: " . $count . "\n";
        if ($count > 0) {
            $lastIdx = $count - 1;
            echo "Last Candle Time: " . date('Y-m-d H:i:s', $timestamps[$lastIdx]) . "\n";
            echo sprintf("O: %.5f | H: %.5f | L: %.5f | C: %.5f\n", 
                $indicators['open'][$lastIdx] ?? 0,
                $indicators['high'][$lastIdx] ?? 0,
                $indicators['low'][$lastIdx] ?? 0,
                $indicators['close'][$lastIdx] ?? 0
            );
        }
    } else {
        echo "Error or no result: " . substr((string)$res, 0, 200) . "\n";
    }
}
