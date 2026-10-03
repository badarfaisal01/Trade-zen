<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/market_api.php';

function fetch_binance_klines_fast(string $symbol, string $binanceInterval, int $neededCount, ?int $endTimeMs = null): array
{
    $apiSymbol = binance_api_symbol_map($symbol);
    $baseSec = 60;
    if ($binanceInterval === '3m') $baseSec = 180;
    else if ($binanceInterval === '5m') $baseSec = 300;
    else if ($binanceInterval === '15m') $baseSec = 900;
    else if ($binanceInterval === '30m') $baseSec = 1800;
    else if ($binanceInterval === '1h') $baseSec = 3600;
    else if ($binanceInterval === '4h') $baseSec = 14400;
    else if ($binanceInterval === '1d') $baseSec = 86400;

    $neededBatches = max(1, min(4, (int)ceil($neededCount / 1000)));
    $nowMs = $endTimeMs !== null ? $endTimeMs : (int)floor(microtime(true) * 1000);

    $mh = curl_multi_init();
    $curlHandles = [];

    $host = 'https://api.binance.com';
    for ($b = 0; $b < $neededBatches; $b++) {
        $batchEnd = $nowMs - ($b * 1000 * $baseSec * 1000);
        $url = $host . "/api/v3/klines?symbol=" . urlencode($apiSymbol) . "&interval=" . $binanceInterval . "&limit=1000&endTime=" . $batchEnd;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, binance_request_headers());
        curl_multi_add_handle($mh, $ch);
        $curlHandles[] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.05);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $allKlinesMap = [];
    foreach ($curlHandles as $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response)) {
            $klines = json_decode($response, true);
            if (is_array($klines)) {
                foreach ($klines as $k) {
                    if (!is_array($k) || count($k) < 6) continue;
                    $openTime = (int)$k[0];
                    $allKlinesMap[$openTime] = [
                        'openTime'  => $openTime,
                        'open'      => (float)$k[1],
                        'high'      => (float)$k[2],
                        'low'       => (float)$k[3],
                        'close'     => (float)$k[4],
                        'closeTime' => (int)$k[6],
                    ];
                }
            }
        }
    }
    curl_multi_close($mh);
    ksort($allKlinesMap);
    return array_values($allKlinesMap);
}

$start = microtime(true);
$klines = fetch_binance_klines_fast('BTCUSDT', '1m', 1600);
$elapsed = round((microtime(true) - $start) * 1000, 1);

echo "Fetched " . count($klines) . " 1m klines via multi-curl in {$elapsed}ms\n";
