<?php
declare(strict_types=1);

function market_assets(bool $activeOnly = true): array
{
    $sql = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.price_source,a.api_symbol,a.is_active,a.sort_order,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM market_assets a LEFT JOIN market_price_cache c ON c.asset_id=a.id';
    if ($activeOnly) {
        $sql .= ' WHERE a.is_active = 1';
    }
    $sql .= ' ORDER BY a.sort_order ASC,a.display_name ASC';
    return db()->query($sql)->fetchAll();
}

function market_asset(string $symbol, bool $activeOnly = true): ?array
{
    $symbol = strtoupper(trim($symbol));
    $sql = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.price_source,a.api_symbol,a.is_active,a.sort_order,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM market_assets a LEFT JOIN market_price_cache c ON c.asset_id=a.id WHERE a.symbol = ?';
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$symbol]);
    $asset = $stmt->fetch();
    return $asset ?: null;
}

function market_noise(int $seed, int $bucket): float
{
    $hash = hash('sha256', $seed . ':' . $bucket);
    $value = (float)hexdec(substr($hash, 0, 8));
    return ($value / 4294967295.0) * 2.0 - 1.0;
}

function market_price_at(array $asset, int $timestampMs): float
{
    $source = $asset['price_source'] ?? 'internal';
    if ($source === 'api' || $source === 'binance') {
        $symbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));
        if ($symbol !== '') {
            try {
                $livePrice = fetch_market_price($symbol);
                if (is_numeric($livePrice) && $livePrice > 0) {
                    return round((float)$livePrice, (int)$asset['decimal_places']);
                }
            } catch (Throwable $exception) {
                // Ignore API failure, fallback to reference math
            }
        }
    }

    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
    $minStep = pow(10, -$decimals);

    $seconds = $timestampMs / 1000.0;
    $seed = (int)($asset['market_seed'] ?? 100);
    $base = market_reference_price($asset);
    $volatility = max(0.0005, (float)($asset['volatility'] ?? 0.002));
    $phase = ($seed % 997) / 17.0;

    $macro = sin(($seconds + $phase) / 181.0) * 0.58;
    $mid = sin(($seconds + ($phase * 3.1)) / 47.0) * 0.27;
    $micro = sin(($seconds + ($phase * 7.7)) / 11.0) * 0.15;

    $bucketSize = 2;
    $bucket = (int)floor($seconds / $bucketSize);
    $fraction = fmod($seconds, (float)$bucketSize) / $bucketSize;
    if ($fraction < 0) {
        $fraction += 1.0;
    }
    $smooth = $fraction * $fraction * (3.0 - 2.0 * $fraction);
    $noiseA = market_noise($seed, $bucket);
    $noiseB = market_noise($seed, $bucket + 1);
    $noise = $noiseA + (($noiseB - $noiseA) * $smooth);

    $fastNoise = market_noise($seed + 13, (int)floor($seconds));

    $ratio = (($macro + $mid + $micro) * $volatility * 2.5) + ($noise * $volatility * 1.2) + ($fastNoise * $volatility * 0.8);
    $price = max($base * 0.1, $base * (1.0 + $ratio));

    // Ensure price delta is active above minStep
    $pipStep = ($fastNoise * $minStep * 4.0);
    $price += $pipStep;

    return round($price, $decimals);
}

function market_candles(array $asset, int $intervalSeconds = 5, int $count = 500, ?int $endMs = null): array
{
    $intervalSeconds = max(1, min(300, $intervalSeconds));
    $count = max(10, min(1000, $count));
    $endMs = $endMs ?? ((int)floor(microtime(true) * 1000));
    $decimals = max(0, min(8, (int)$asset['decimal_places']));
    $source = $asset['price_source'] ?? 'internal';
    $apiSymbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));

    // Fetch real Binance klines if asset is configured for Binance / API
    if (($source === 'binance' || $source === 'api') && $apiSymbol !== '') {
        try {
            $intervalMs = max(1000, $intervalSeconds * 1000);
            $subCount = max(1, (int)floor(60000 / $intervalMs));
            $neededBinanceKlines = max(10, min(1000, (int)ceil($count / $subCount)));
            $binanceKlines = fetch_binance_klines($apiSymbol, $intervalSeconds, $neededBinanceKlines);

            if (!empty($binanceKlines)) {
                $candles = [];
                $minStep = pow(10, -$decimals);
                $volatility = max(0.0001, (float)($asset['volatility'] ?? 0.002));
                $seed = (int)($asset['market_seed'] ?? 100);

                foreach ($binanceKlines as $k) {
                    $openTime = (int)$k['openTime'];
                    $closeTime = (int)$k['closeTime'];
                    $open = (float)$k['open'];
                    $high = (float)$k['high'];
                    $low = (float)$k['low'];
                    $close = (float)$k['close'];

                    // Eliminate flat line candles if price had zero movement
                    if ($high <= $low || ($high === $low && $high === $open)) {
                        $noise = market_noise($seed, (int)($openTime / 1000));
                        $spread = max($minStep * 3.0, $open * $volatility * 0.12);
                        $high = $open + abs($spread * (0.6 + 0.4 * $noise));
                        $low = $open - abs($spread * (0.6 + 0.4 * (1.0 - $noise)));
                        if ($open === $close) {
                            $close = $noise >= 0 ? min($high, $open + $minStep) : max($low, $open - $minStep);
                        }
                    }

                    $klineDurationMs = max(1000, $closeTime - $openTime);
                    $subUnits = max(1, (int)round($klineDurationMs / $intervalMs));
                    $firstPivot = $close >= $open ? $low : $high;
                    $secondPivot = $close >= $open ? $high : $low;

                    $calcPriceAtFrac = static function (float $frac) use ($open, $close, $firstPivot, $secondPivot): float {
                        if ($frac <= 0.33) {
                            return $open + ($firstPivot - $open) * ($frac / 0.33);
                        }
                        if ($frac <= 0.67) {
                            return $firstPivot + ($secondPivot - $firstPivot) * (($frac - 0.33) / 0.34);
                        }
                        return $secondPivot + ($close - $secondPivot) * (($frac - 0.67) / 0.33);
                    };

                    for ($subIdx = 0; $subIdx < $subUnits; $subIdx++) {
                        $subOpenTime = $openTime + ($subIdx * $intervalMs);
                        $subCloseTime = min($closeTime, $subOpenTime + $intervalMs - 1);
                        $f1 = $subIdx / $subUnits;
                        $f2 = ($subIdx + 1) / $subUnits;

                        $sOpen = $calcPriceAtFrac($f1);
                        $sClose = $calcPriceAtFrac($f2);

                        if ($subUnits > 1) {
                            $noiseVal = market_noise($seed, (int)($subOpenTime / 1000));
                            $spreadVal = max($minStep * 2.0, abs($sClose - $sOpen) * 0.4 + ($open * $volatility * 0.05));
                            $sHigh = max($sOpen, $sClose) + abs($spreadVal * (0.5 + 0.5 * $noiseVal));
                            $sLow = max($minStep, min($sOpen, $sClose) - abs($spreadVal * (0.5 + 0.5 * (1.0 - $noiseVal))));
                        } else {
                            $sHigh = $high;
                            $sLow = $low;
                        }

                        $sOpenR = round($sOpen, $decimals);
                        $sHighR = round(max($sHigh, $sOpen, $sClose), $decimals);
                        $sLowR = round(min($sLow, $sOpen, $sClose), $decimals);
                        $sCloseR = round($sClose, $decimals);

                        $subDurationMs = max(100, $subCloseTime - $subOpenTime);
                        $p1 = $sCloseR >= $sOpenR ? $sLowR : $sHighR;
                        $p2 = $sCloseR >= $sOpenR ? $sHighR : $sLowR;

                        $tMs1 = $subOpenTime;
                        $tMs2 = $subOpenTime + (int)round($subDurationMs * 0.33);
                        $tMs3 = $subOpenTime + (int)round($subDurationMs * 0.67);
                        $tMs4 = $subCloseTime;

                        $ticks = [
                            [$tMs1, $sOpenR],
                            [$tMs2, $p1],
                            [$tMs3, $p2],
                            [$tMs4, $sCloseR],
                        ];

                        $candles[] = [
                            'time' => $subOpenTime,
                            'open' => $sOpenR,
                            'high' => $sHighR,
                            'low' => $sLowR,
                            'close' => $sCloseR,
                            'lastTickTime' => min($subCloseTime, $endMs),
                            'complete' => $subCloseTime <= $endMs,
                            'ticks' => $ticks,
                        ];
                    }
                }

                // If count requested exceeds returned sub-candles, prepend synthetic sub-candles
                if (count($candles) > $count) {
                    $candles = array_slice($candles, -$count);
                }

                $extraCount = max(0, min(300, $count - count($candles)));
                if ($extraCount > 0 && !empty($candles)) {
                    $firstTime = (int)$candles[0]['time'];
                    $firstOpen = (float)$candles[0]['open'];
                    $historicalCandles = [];
                    $anchorPrice = $firstOpen;

                    for ($i = $extraCount; $i >= 1; $i--) {
                        $tMs = $firstTime - ($i * $intervalMs);
                        $candleEnd = $tMs + $intervalMs - 1;

                        $noiseVal = market_noise($seed, (int)($tMs / 1000));
                        $changeRatio = $noiseVal * $volatility * 0.3;
                        $hOpen = $anchorPrice;
                        $hClose = max($minStep * 10.0, $hOpen * (1.0 + $changeRatio));
                        $spreadVal = max($minStep * 3.0, max($hOpen, $hClose) * $volatility * 0.15);

                        $hHigh = max($hOpen, $hClose) + abs($spreadVal * (0.5 + 0.5 * market_noise($seed + 1, (int)($tMs / 1000))));
                        $hLow = max($minStep, min($hOpen, $hClose) - abs($spreadVal * (0.5 + 0.5 * market_noise($seed + 2, (int)($tMs / 1000)))));

                        $hOpenR = round($hOpen, $decimals);
                        $hHighR = round(max($hHigh, $hOpen, $hClose), $decimals);
                        $hLowR = round(min($hLow, $hOpen, $hClose), $decimals);
                        $hCloseR = round($hClose, $decimals);

                        $p1 = $hCloseR >= $hOpenR ? $hLowR : $hHighR;
                        $p2 = $hCloseR >= $hOpenR ? $hHighR : $hLowR;

                        $historicalCandles[] = [
                            'time' => $tMs,
                            'open' => $hOpenR,
                            'high' => $hHighR,
                            'low' => $hLowR,
                            'close' => $hCloseR,
                            'lastTickTime' => $candleEnd,
                            'complete' => true,
                            'ticks' => [
                                [$tMs, $hOpenR],
                                [$tMs + (int)round($intervalMs * 0.33), $p1],
                                [$tMs + (int)round($intervalMs * 0.67), $p2],
                                [$candleEnd, $hCloseR],
                            ],
                        ];

                        $anchorPrice = $hClose;
                    }

                    $candles = array_merge($historicalCandles, $candles);
                }

                return $candles;
            }
        } catch (Throwable $e) {
            error_log('Failed to fetch Binance klines for ' . $apiSymbol . ': ' . $e->getMessage());
        }
    }

    // Fallback generator if API is unavailable or internal asset
    $intervalMs = $intervalSeconds * 1000;
    $currentBucketMs = (int)(floor($endMs / $intervalMs) * $intervalMs);
    $startMs = $currentBucketMs - (($count - 1) * $intervalMs);
    $density = max(4, min(16, (int)setting('chart_tick_density', 10)));
    $candles = [];

    for ($i = 0; $i < $count; $i++) {
        $time = $startMs + ($i * $intervalMs);
        $candleEndMs = min($time + $intervalMs - 1, $endMs);
        if ($candleEndMs < $time) {
            continue;
        }

        $elapsedMs = max(0, $candleEndMs - $time);
        $sampleCount = $candleEndMs >= $time + $intervalMs - 1
            ? $density
            : max(2, min($density, 1 + (int)ceil(($density - 1) * ($elapsedMs / max(1, $intervalMs - 1)))));
        $ticks = [];
        $prices = [];
        $lastTickMs = -1;
        for ($sample = 0; $sample < $sampleCount; $sample++) {
            $fraction = $sampleCount === 1 ? 0.0 : $sample / ($sampleCount - 1);
            $tickMs = $time + (int)round($elapsedMs * $fraction);
            if ($tickMs <= $lastTickMs) {
                $tickMs = min($candleEndMs, $lastTickMs + 1);
            }
            if ($tickMs < $time || $tickMs > $candleEndMs || $tickMs === $lastTickMs) {
                continue;
            }
            $price = market_price_at($asset, $tickMs);
            $ticks[] = [$tickMs, $price];
            $prices[] = $price;
            $lastTickMs = $tickMs;
        }
        if (!$ticks || $lastTickMs < $candleEndMs) {
            $price = market_price_at($asset, $candleEndMs);
            $ticks[] = [$candleEndMs, $price];
            $prices[] = $price;
        }

        $open = (float)$prices[0];
        $close = (float)$prices[count($prices) - 1];
        $high = round(max($prices), $decimals);
        $low = round(min($prices), $decimals);

        if ($high <= $low || $high === $open || $low === $open) {
            $seed = (int)($asset['market_seed'] ?? 100);
            $minStep = pow(10, -$decimals);
            $noiseVal = market_noise($seed + 99, (int)($time / 1000));
            $vol = max(0.0005, (float)($asset['volatility'] ?? 0.002));
            $spreadVal = max($minStep * 2.0, $open * $vol * 0.2);
            $high = round(max($open, $close) + abs($spreadVal * (0.6 + 0.4 * $noiseVal)) + $minStep, $decimals);
            $low = round(max($minStep, min($open, $close) - abs($spreadVal * (0.6 + 0.4 * (1.0 - $noiseVal))) - $minStep), $decimals);
            if ($open === $close) {
                $close = $noiseVal >= 0 ? round($open + $minStep, $decimals) : round(max($minStep, $open - $minStep), $decimals);
            }
        }

        $candles[] = [
            'time' => $time,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'lastTickTime' => $candleEndMs,
            'complete' => $candleEndMs >= $time + $intervalMs - 1,
            'ticks' => $ticks,
        ];
    }

    return $candles;
}

function market_reference_price(array $asset): float
{
    $source = $asset['price_source'] ?? 'internal';
    if ($source === 'api' || $source === 'binance') {
        $sourcePrice = isset($asset['source_price']) ? (float)$asset['source_price'] : 0.0;
        if ($sourcePrice > 0) {
            return $sourcePrice;
        }

        $symbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));
        if ($symbol !== '') {
            try {
                $livePrice = fetch_market_price($symbol);
                if (is_numeric($livePrice) && $livePrice > 0) {
                    $assetId = isset($asset['id']) ? (int)$asset['id'] : 0;
                    if ($assetId > 0) {
                        $upsert = db()->prepare('INSERT INTO market_price_cache (asset_id,price,source,fetched_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price),source=VALUES(source),fetched_at=NOW()');
                        $upsert->execute([$assetId, (float)$livePrice, $source]);
                    }
                    return (float)$livePrice;
                }
            } catch (Throwable $exception) {
                error_log('API-fed market refresh failed for ' . $symbol . ': ' . $exception->getMessage());
            }
        }
    }

    return max(0.00000001, (float)$asset['base_price']);
}

function market_json_path_value(array $payload, string $path)
{
    $value = $payload;
    foreach (array_filter(explode('.', trim($path, '.')), 'strlen') as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            throw new RuntimeException('Configured price path was not found in the API response.');
        }
        $value = $value[$part];
    }
    return $value;
}

function market_public_ip_for_host(string $host): string
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }
    }
    $ips = array_values(array_unique($ips));
    usort($ips, static function (string $left, string $right): int {
        $leftV4 = filter_var($left, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $rightV4 = filter_var($right, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        return $leftV4 === $rightV4 ? 0 : ($leftV4 ? -1 : 1);
    });
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    throw new RuntimeException('Market API host must resolve to a public internet address.');
}

function refresh_market_api_price(int $assetId): float
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for market API feeds.');
    }
    $stmt = db()->prepare('SELECT * FROM market_assets WHERE id=? LIMIT 1');
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    if (!$asset) {
        throw new RuntimeException('Market asset not found.');
    }
    if ($asset['price_source'] !== 'api') {
        throw new RuntimeException('Set this market price source to API before refreshing it.');
    }

    $symbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));
    $price = null;
    $source = 'unknown';

    // Try Twelve Data API first (for Forex, Crypto)
    $twelvedataKey = getenv('TWELVEDATA_API_KEY');
    if (!empty($twelvedataKey)) {
        try {
            $price = fetch_twelvedata_price($symbol, $twelvedataKey);
            $source = 'twelvedata.com';
        } catch (Exception $e) {
            // Fall through to try other providers
        }
    }

    // Try Metals API for precious metals
    if ($price === null && (stripos($symbol, 'XAU') !== false || stripos($symbol, 'XAG') !== false || stripos($symbol, 'GOLD') !== false || stripos($symbol, 'SILVER') !== false)) {
        $metalsKey = getenv('METALS_API_KEY');
        if (!empty($metalsKey)) {
            try {
                $price = fetch_metals_api_price($symbol, 'USD', $metalsKey);
                $source = 'metals-api.com';
            } catch (Exception $e) {
                // Fall through to try custom API
            }
        }
    }

    // Fall back to custom market API URL if configured
    if ($price === null) {
        $template = trim((string)setting('market_api_url', ''));
        $path = str_replace('{symbol}', $symbol, trim((string)setting('market_api_price_path', 'price')));
        $url = str_replace('{symbol}', rawurlencode($symbol), $template);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Configure Twelve Data API key in .env file (TWELVEDATA_API_KEY) or set up a custom market API URL with {symbol} placeholder in Settings.');
        }
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $port = (int)($parts['port'] ?? 443);
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || $port !== 443) {
            throw new InvalidArgumentException('Market API must use HTTPS on port 443 without URL credentials.');
        }
        $ip = market_public_ip_for_host($host);
        $headers = ['Accept: application/json'];
        $apiKey = trim((string)setting('market_api_key', ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the market API request.');
        }
        $resolvedAddress = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $resolvedAddress],
            CURLOPT_USERAGENT => '55AK-Market-Feed/1.0',
        ]);
        $raw = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Market API request failed' . ($error !== '' ? ': ' . $error : ' with HTTP ' . $httpCode) . '.');
        }
        if (strlen($raw) > 1048576) {
            throw new RuntimeException('Market API response is too large.');
        }
        $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Market API did not return a JSON object or array.');
        }
        $price = (float)market_json_path_value($payload, $path);
        $source = $host;
    }

    if (!is_numeric($price) || !is_finite($price) || $price <= 0 || $price > 1000000000000) {
        throw new RuntimeException('Market API returned an invalid price.');
    }
    $price = round((float)$price, (int)$asset['decimal_places']);
    $upsert = db()->prepare('INSERT INTO market_price_cache (asset_id,price,source,fetched_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price),source=VALUES(source),fetched_at=NOW()');
    $upsert->execute([$assetId, $price, $source]);
    return $price;
}

function market_asset_payload(array $asset, ?int $nowMs = null): array
{
    $nowMs = $nowMs ?? (int)floor(microtime(true) * 1000);
    $price = market_price_at($asset, $nowMs);
    $past = market_price_at($asset, $nowMs - 300000);
    $change = $past > 0 ? (($price - $past) / $past) * 100 : 0.0;

    return [
        'symbol' => $asset['symbol'],
        'name' => $asset['display_name'],
        'category' => $asset['category'],
        'price' => $price,
        'decimals' => (int)$asset['decimal_places'],
        'payout' => (float)$asset['payout_percent'],
        'changePercent' => round($change, 2),
        'active' => (bool)$asset['is_active'],
    ];
}

function market_snapshot(string $symbol, int $intervalSeconds = 5, int $count = 72, bool $includeCandles = true): array
{
    $asset = market_asset($symbol);
    if (!$asset) {
        throw new InvalidArgumentException('Selected market is unavailable.');
    }

    $nowMs = (int)floor(microtime(true) * 1000);
    // Pre-warm Binance batch price cache so all binance assets are fetched in a single HTTP call
    fetch_binance_all_prices();

    $assets = [];
    foreach (market_assets(true) as $row) {
        $assets[] = market_asset_payload($row, $nowMs);
    }

    return [
        'serverTime' => $nowMs,
        'asset' => market_asset_payload($asset, $nowMs),
        'assets' => $assets,
        'candles' => $includeCandles ? market_candles($asset, $intervalSeconds, $count, $nowMs) : [],
        'engine' => 'market-engine-v1',
    ];
}
