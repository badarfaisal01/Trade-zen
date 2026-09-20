<?php
declare(strict_types=1);

function market_assets(bool $activeOnly = true): array
{
    static $cache = [];
    $key = $activeOnly ? '1' : '0';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $sql = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.price_source,a.api_symbol,a.is_active,a.sort_order,a.updated_at,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM market_assets a LEFT JOIN market_price_cache c ON c.asset_id=a.id';
    if ($activeOnly) {
        $sql .= ' WHERE a.is_active = 1';
    }
    $sql .= ' ORDER BY a.sort_order ASC,a.display_name ASC';
    $cache[$key] = db()->query($sql)->fetchAll();
    return $cache[$key];
}

function market_asset(string $symbol, bool $activeOnly = true): ?array
{
    static $assetCache = [];
    $cleanSymbol = strtoupper(trim($symbol));
    $cacheKey = $cleanSymbol . '_' . ($activeOnly ? '1' : '0');
    if (array_key_exists($cacheKey, $assetCache)) {
        return $assetCache[$cacheKey];
    }

    // 1. Try exact match
    $sql = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.price_source,a.api_symbol,a.is_active,a.sort_order,a.updated_at,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM market_assets a LEFT JOIN market_price_cache c ON c.asset_id=a.id WHERE a.symbol = ?';
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$cleanSymbol]);
    $asset = $stmt->fetch();
    if ($asset) {
        $assetCache[$cacheKey] = $asset;
        return $asset;
    }

    // 2. Strip _OTC / -OTC / (OTC) suffixes and special characters
    $stripped = preg_replace('/(_OTC|-OTC|\s+OTC|\(OTC\)|\/OTC)$/i', '', $cleanSymbol);
    $stripped = str_replace([' ', '_', '-', '/'], '', (string)$stripped);

    if ($stripped !== '' && $stripped !== $cleanSymbol) {
        $stmt->execute([$stripped]);
        $asset = $stmt->fetch();
        if ($asset) {
            $assetCache[$cacheKey] = $asset;
            return $asset;
        }
    }

    // 3. Fallback: match normalized DB symbols
    $target = $stripped !== '' ? $stripped : str_replace([' ', '_', '-', '/'], '', $cleanSymbol);
    $sqlFallback = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.price_source,a.api_symbol,a.is_active,a.sort_order,a.updated_at,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM market_assets a LEFT JOIN market_price_cache c ON c.asset_id=a.id WHERE REPLACE(REPLACE(REPLACE(REPLACE(UPPER(a.symbol), "_", ""), "-", ""), "/", ""), " ", "") = ?';
    if ($activeOnly) {
        $sqlFallback .= ' AND a.is_active = 1';
    }
    $sqlFallback .= ' LIMIT 1';
    $stmtFallback = db()->prepare($sqlFallback);
    $stmtFallback->execute([$target]);
    $asset = $stmtFallback->fetch();

    $result = $asset ?: null;
    $assetCache[$cacheKey] = $result;
    return $result;
}

function market_noise(int $seed, int $bucket): float
{
    $hash = hash('sha256', $seed . ':' . $bucket);
    $value = (float)hexdec(substr($hash, 0, 8));
    return ($value / 4294967295.0) * 2.0 - 1.0;
}

function market_price_at(array $asset, int $timestampMs, bool $useFormulaOnly = false): float
{
    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
    $source = $asset['price_source'] ?? 'internal';
    $nowMs = (int)floor(microtime(true) * 1000);
    $isLive = abs($nowMs - $timestampMs) < 60000;

    if (!$useFormulaOnly && $isLive && ($source === 'api' || $source === 'binance')) {
        $symbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));
        if ($symbol !== '') {
            try {
                $livePrice = fetch_market_price($symbol);
                if (is_numeric($livePrice) && $livePrice > 0) {
                    return round((float)$livePrice, $decimals);
                }
            } catch (Throwable $exception) {
                // Ignore API failure, fallback to reference math
            }
        }
        if (isset($asset['source_price']) && (float)$asset['source_price'] > 0) {
            return round((float)$asset['source_price'], $decimals);
        }
    }

    $minStep = pow(10, -$decimals);

    $seconds = $timestampMs / 1000.0;
    $seed = (int)($asset['market_seed'] ?? 100);
    $base = market_reference_price($asset);
    $volatility = max(0.0005, (float)($asset['volatility'] ?? 0.002));
    $phase = ($seed % 997) / 17.0;

    // Multi-period wave cycles for natural up and down market trends
    $macro = sin(($seconds + $phase * 10.0) / 600.0) * 0.50;
    $mid   = sin(($seconds + $phase * 31.0) / 180.0) * 0.30;
    $micro = sin(($seconds + $phase * 73.0) / 60.0)  * 0.20;

    // Smooth continuous spline drift
    $bucketSize = 10;
    $bucket = (int)floor($seconds / $bucketSize);
    $fraction = fmod($seconds, (float)$bucketSize) / $bucketSize;
    if ($fraction < 0) {
        $fraction += 1.0;
    }
    $smooth = $fraction * $fraction * (3.0 - 2.0 * $fraction);
    $noiseA = market_noise($seed, $bucket);
    $noiseB = market_noise($seed, $bucket + 1);
    $noise = $noiseA + (($noiseB - $noiseA) * $smooth);

    $fastNoise = market_noise($seed + 13, (int)floor($seconds / 2.0));

    $ratio = (($macro + $mid + $micro) * $volatility * 3.2) + ($noise * $volatility * 1.6) + ($fastNoise * $volatility * 0.3);
    $price = max($base * 0.1, $base * (1.0 + $ratio));

    return round($price, $decimals);
}

function market_candles(array $asset, int $intervalSeconds = 5, int $count = 800, ?int $endMs = null): array
{
    $intervalSeconds = max(1, min(300, $intervalSeconds));
    $count = max(10, min(3000, $count));
    $endMs = $endMs ?? ((int)floor(microtime(true) * 1000));
    $decimals = max(0, min(8, (int)$asset['decimal_places']));
    $source = $asset['price_source'] ?? 'internal';
    $apiSymbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));

    // Fetch real Binance klines if asset is configured for Binance / API
    if (($source === 'binance' || $source === 'api') && $apiSymbol !== '') {
        try {
            $intervalMs = max(1000, $intervalSeconds * 1000);
            $subCount = max(1, (int)floor(60000 / $intervalMs));
            // Request enough klines from Binance to cover the requested candle count (up to 10000 max).
            $neededBinanceKlines = max(10, min(10000, $count));
            $binanceKlines = fetch_binance_klines($apiSymbol, $intervalSeconds, $neededBinanceKlines, $endMs);

            if (!empty($binanceKlines)) {
                $candles = [];
                $minStep = pow(10, -$decimals);
                $volatility = max(0.0001, (float)($asset['volatility'] ?? 0.002));
                $seed = (int)($asset['market_seed'] ?? 100);

                // Pre-process raw klines to add natural trend motion across flat/stale price stretches
                $processedKlines = [];
                $prevKlineClose = null;

                foreach ($binanceKlines as $k) {
                    $openTime = (int)$k['openTime'];
                    $closeTime = (int)$k['closeTime'];
                    $open = (float)$k['open'];
                    $high = (float)$k['high'];
                    $low = (float)$k['low'];
                    $close = (float)$k['close'];

                    $isFlat = ($high <= $low || ($high === $low && $high === $open) || abs($close - $open) < $minStep);
                    if ($isFlat) {
                        $sec = $openTime / 1000.0;
                        $kWave = sin(($sec + $seed) / 240.0) * 0.55 + sin(($sec + $seed * 3) / 75.0) * 0.35 + sin(($sec + $seed * 7) / 25.0) * 0.10;
                        $kSpread = max($minStep * 4.0, $open * $volatility * 0.35);
                        $kDelta = $kSpread * $kWave;

                        $open = $prevKlineClose !== null ? $prevKlineClose : $open;
                        $close = max($minStep * 10.0, $open + $kDelta);
                        $bodyDelta = abs($close - $open);
                        $wickDelta = max($minStep, min($bodyDelta * 0.18, $open * $volatility * 0.03));

                        $high = max($open, $close) + $wickDelta;
                        $low = max($minStep, min($open, $close) - $wickDelta);
                    }

                    $prevKlineClose = $close;
                    $processedKlines[] = [
                        'openTime' => $openTime,
                        'closeTime' => $closeTime,
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close,
                    ];
                }

                foreach ($processedKlines as $k) {
                    $openTime = (int)$k['openTime'];
                    $closeTime = (int)$k['closeTime'];
                    $open = (float)$k['open'];
                    $high = (float)$k['high'];
                    $low = (float)$k['low'];
                    $close = (float)$k['close'];

                    $klineDurationMs = max(1000, $closeTime - $openTime);
                    $subUnits = max(1, (int)round($klineDurationMs / $intervalMs));

                    for ($subIdx = 0; $subIdx < $subUnits; $subIdx++) {
                        $subOpenTime = $openTime + ($subIdx * $intervalMs);
                        $subCloseTime = min($closeTime, $subOpenTime + $intervalMs - 1);
                        $f1 = $subIdx / $subUnits;
                        $f2 = ($subIdx + 1) / $subUnits;

                        $sOpen = ($subIdx === 0 && !empty($candles)) ? $candles[count($candles) - 1]['close'] : $open + ($close - $open) * $f1;
                        $sClose = $open + ($close - $open) * $f2;

                        if (abs($sClose - $sOpen) < $minStep) {
                            $dir = market_noise($seed + 33, (int)($subOpenTime / 1000));
                            $sClose = $dir >= 0 ? $sOpen + ($minStep * 2) : max($minStep, $sOpen - ($minStep * 2));
                        }

                        $bodySize = abs($sClose - $sOpen);
                        $n1 = abs(market_noise($seed + 77, (int)($subOpenTime / 1000)));
                        $n2 = abs(market_noise($seed + 88, (int)($subOpenTime / 1000)));
                        $minWick1 = $minStep * (1 + (int)floor($n1 * 3.0));
                        $minWick2 = $minStep * (1 + (int)floor($n2 * 3.0));
                        $wickUpper = max($minWick1, min($bodySize * 0.65, $open * $volatility * 0.10 * (0.3 + 0.7 * $n1)));
                        $wickLower = max($minWick2, min($bodySize * 0.65, $open * $volatility * 0.10 * (0.3 + 0.7 * $n2)));

                        $sOpenR = round($sOpen, $decimals);
                        $sCloseR = round($sClose, $decimals);
                        $sHighR = round(max($sOpen, $sClose) + $wickUpper, $decimals);
                        $sLowR  = round(max($minStep, min($sOpen, $sClose) - $wickLower), $decimals);

                        if ($sHighR <= max($sOpenR, $sCloseR)) {
                            $sHighR = round(max($sOpenR, $sCloseR) + $minStep * 2, $decimals);
                        }
                        if ($sLowR >= min($sOpenR, $sCloseR)) {
                            $sLowR = round(max($minStep, min($sOpenR, $sCloseR) - $minStep * 2), $decimals);
                        }

                        $subDurationMs = max(100, $subCloseTime - $subOpenTime);
                        $p1 = $sCloseR >= $sOpenR ? $sLowR : $sHighR;
                        $p2 = $sCloseR >= $sOpenR ? $sHighR : $sLowR;
                        $p1 = max($sLowR, min($sHighR, $p1));
                        $p2 = max($sLowR, min($sHighR, $p2));

                        $ticks = [
                            [$subOpenTime, $sOpenR],
                            [$subOpenTime + (int)round($subDurationMs * 0.33), $p1],
                            [$subOpenTime + (int)round($subDurationMs * 0.67), $p2],
                            [$subCloseTime, $sCloseR],
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

                // Ensure candles reach up to $endMs (current live time)
                if (!empty($candles)) {
                    $lastIdx = count($candles) - 1;
                    $lastCandleTime = (int)$candles[$lastIdx]['time'];
                    $prevClose = (float)$candles[$lastIdx]['close'];

                    // If historical candles have a huge time gap from current time, shift them forward
                    if (($endMs - $lastCandleTime) > ($count * $intervalMs * 2)) {
                        $shiftMs = $endMs - $lastCandleTime - $intervalMs;
                        foreach ($candles as &$c) {
                            $c['time'] += $shiftMs;
                            $c['lastTickTime'] += $shiftMs;
                            if (!empty($c['ticks']) && is_array($c['ticks'])) {
                                foreach ($c['ticks'] as &$tk) {
                                    $tk[0] += $shiftMs;
                                }
                                unset($tk);
                            }
                        }
                        unset($c);
                        $lastCandleTime = (int)$candles[$lastIdx]['time'];
                    }

                    if (($lastCandleTime + $intervalMs) <= $endMs) {
                        $currTime = $lastCandleTime + $intervalMs;
                        $fillCount = 0;
                        $maxFill = min(500, $count);
                        while ($currTime <= $endMs && $fillCount < $maxFill) {
                            $fillCount++;
                            $cEnd = min($currTime + $intervalMs - 1, $endMs);
                            $isCurrent = ($currTime + $intervalMs) > $endMs;
                            $cClose = $isCurrent ? market_price_at($asset, $endMs) : $prevClose;
                            $cOpen = $prevClose;
                            $cHigh = max($cOpen, $cClose);
                            $cLow = min($cOpen, $cClose);
                            if ($cHigh <= $cLow) {
                                $cHigh = round($cHigh + $minStep, $decimals);
                                $cLow = round(max($minStep, $cLow - $minStep), $decimals);
                            }
                            $subDur = max(100, $cEnd - $currTime);
                            $p1 = $cClose >= $cOpen ? $cLow : $cHigh;
                            $p2 = $cClose >= $cOpen ? $cHigh : $cLow;
                            $ticks = [
                                [$currTime, $cOpen],
                                [$currTime + (int)round($subDur * 0.33), $p1],
                                [$currTime + (int)round($subDur * 0.67), $p2],
                                [$cEnd, $cClose],
                            ];
                            $candles[] = [
                                'time' => $currTime,
                                'open' => $cOpen,
                                'high' => $cHigh,
                                'low' => $cLow,
                                'close' => $cClose,
                                'lastTickTime' => $cEnd,
                                'complete' => !$isCurrent,
                                'ticks' => $ticks,
                            ];
                            $prevClose = $cClose;
                            $currTime += $intervalMs;
                        }
                    } else {
                        // Current forming candle: ensure last tick reaches $endMs
                        $lastIdx = count($candles) - 1;
                        $candles[$lastIdx]['lastTickTime'] = $endMs;
                        $candles[$lastIdx]['complete'] = false;
                        $livePrice = (float)$candles[$lastIdx]['close'];
                        if (!empty($candles[$lastIdx]['ticks'])) {
                            $candles[$lastIdx]['ticks'][] = [$endMs, $livePrice];
                        }
                    }
                }

                // If count requested exceeds returned sub-candles, prepend synthetic sub-candles
                if (count($candles) > $count) {
                    $candles = array_slice($candles, -$count);
                }

                $extraCount = max(0, min(10000, $count - count($candles)));
                if ($extraCount > 0 && !empty($candles)) {
                    $firstTime = (int)$candles[0]['time'];
                    $firstOpen = (float)$candles[0]['open'];
                    $historicalCandles = [];
                    $anchorPrice = $firstOpen;

                    for ($i = 1; $i <= $extraCount; $i++) {
                        $tMs = $firstTime - ($i * $intervalMs);
                        $candleEnd = $tMs + $intervalMs - 1;

                        $noiseVal = market_noise($seed, (int)($tMs / 1000));
                        $changeRatio = $noiseVal * $volatility * 0.25;

                        $hClose = $anchorPrice;
                        $hOpen = max($minStep * 10.0, $hClose / (1.0 + $changeRatio));
                        $spreadVal = max($minStep * 3.0, max($hOpen, $hClose) * $volatility * 0.15);

                        $hHigh = max($hOpen, $hClose) + abs($spreadVal * (0.5 + 0.5 * market_noise($seed + 1, (int)($tMs / 1000))));
                        $hLow = max($minStep, min($hOpen, $hClose) - abs($spreadVal * (0.5 + 0.5 * market_noise($seed + 2, (int)($tMs / 1000)))));

                        $hOpenR = round($hOpen, $decimals);
                        $hHighR = round(max($hHigh, $hOpen, $hClose), $decimals);
                        $hLowR = round(min($hLow, $hOpen, $hClose), $decimals);
                        $hCloseR = round($hClose, $decimals);

                        if ($hHighR <= $hLowR) {
                            $spreadVal = max($minStep * 3.0, $hOpenR * $volatility * 0.2);
                            $hHighR = round(max($hOpenR, $hCloseR) + abs($spreadVal * (0.6 + 0.4 * $noiseVal)) + $minStep, $decimals);
                            $hLowR = round(max($minStep, min($hOpenR, $hCloseR) - abs($spreadVal * (0.6 + 0.4 * (1.0 - $noiseVal))) - $minStep), $decimals);
                            if ($hOpenR === $hCloseR) {
                                $hCloseR = $noiseVal >= 0 ? round($hOpenR + $minStep, $decimals) : round(max($minStep, $hOpenR - $minStep), $decimals);
                            }
                        }

                        $p1 = $hCloseR >= $hOpenR ? $hLowR : $hHighR;
                        $p2 = $hCloseR >= $hOpenR ? $hHighR : $hLowR;
                        $p1 = max($hLowR, min($hHighR, $p1));
                        $p2 = max($hLowR, min($hHighR, $p2));

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

                        $anchorPrice = $hOpen;
                    }

                    $candles = array_merge(array_reverse($historicalCandles), $candles);
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
    $seed = (int)($asset['market_seed'] ?? 100);
    $vol = max(0.0005, (float)($asset['volatility'] ?? 0.002));
    $candles = [];

    $targetPrice = (float)($asset['target_price'] ?? $asset['closed_price'] ?? $asset['price'] ?? 0.0);
    if ($targetPrice <= 0) {
        $targetPrice = market_price_at($asset, $endMs);
    }
    $endPriceCalc = market_price_at($asset, $endMs, true);
    $priceOffset = $targetPrice - $endPriceCalc;

    for ($i = 0; $i < $count; $i++) {
        $time = $startMs + ($i * $intervalMs);
        $candleEndMs = min($time + $intervalMs - 1, $endMs);
        if ($candleEndMs < $time) {
            continue;
        }

        $minStep = pow(10, -$decimals);
        // Continuous open from previous close, shifted seamlessly to land on targetPrice at $endMs
        $open = ($i === 0) ? round(market_price_at($asset, $time, true) + $priceOffset, $decimals) : $candles[$i - 1]['close'];
        $close = ($i === $count - 1) ? $targetPrice : round(market_price_at($asset, $candleEndMs, true) + $priceOffset, $decimals);

        // Ensure visible candle body with directional movement
        if (abs($close - $open) < $minStep) {
            $noiseDir = market_noise($seed + 55, (int)($time / 1000));
            $close = round($noiseDir >= 0 ? $open + ($minStep * 2) : max($minStep, $open - ($minStep * 2)), $decimals);
        }

        // Elegant, natural candle wicks: distinct upper and lower shadows, crisp and realistic
        $bodyHeight = abs($close - $open);
        $noiseUpper = abs(market_noise($seed + 99, (int)($time / 1000)));
        $noiseLower = abs(market_noise($seed + 101, (int)($time / 1000)));

        $minWickUpper = $minStep * (1 + (int)floor($noiseUpper * 3.0));
        $wickUpper = max($minWickUpper, min($bodyHeight * 0.70, $open * $vol * 0.12 * (0.3 + 0.7 * $noiseUpper)));
        $minWickLower = $minStep * (1 + (int)floor($noiseLower * 3.0));
        $wickLower = max($minWickLower, min($bodyHeight * 0.70, $open * $vol * 0.12 * (0.3 + 0.7 * $noiseLower)));

        $high = round(max($open, $close) + $wickUpper, $decimals);
        $low  = round(max($minStep, min($open, $close) - $wickLower), $decimals);

        if ($high <= max($open, $close)) {
            $high = round(max($open, $close) + $minStep * 2, $decimals);
        }
        if ($low >= min($open, $close)) {
            $low = round(max($minStep, min($open, $close) - $minStep * 2), $decimals);
        }

        $p1 = $close >= $open ? $low : $high;
        $p2 = $close >= $open ? $high : $low;
        $subDur = max(100, $candleEndMs - $time);
        $ticks = [
            [$time, $open],
            [$time + (int)round($subDur * 0.33), $p1],
            [$time + (int)round($subDur * 0.67), $p2],
            [$candleEndMs, $close],
        ];

        $candles[] = [
            'time'        => $time,
            'open'        => $open,
            'high'        => $high,
            'low'         => $low,
            'close'       => $close,
            'lastTickTime'=> $candleEndMs,
            'complete'    => $candleEndMs >= $time + $intervalMs - 1,
            'ticks'       => $ticks,
        ];
    }

    return $candles;
}

function market_reference_price(array $asset): float
{
    if (isset($asset['target_price']) && is_numeric($asset['target_price']) && (float)$asset['target_price'] > 0) {
        return (float)$asset['target_price'];
    }
    if (isset($asset['closed_price']) && is_numeric($asset['closed_price']) && (float)$asset['closed_price'] > 0) {
        return (float)$asset['closed_price'];
    }
    if (isset($asset['price']) && is_numeric($asset['price']) && (float)$asset['price'] > 0) {
        return (float)$asset['price'];
    }
    if (isset($asset['source_price']) && is_numeric($asset['source_price']) && (float)$asset['source_price'] > 0) {
        return (float)$asset['source_price'];
    }

    $source = $asset['price_source'] ?? 'internal';
    if ($source === 'api' || $source === 'binance') {
        $symbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));
        if ($symbol !== '') {
            try {
                $livePrice = fetch_market_price($symbol);
                if (is_numeric($livePrice) && $livePrice > 0) {
                    return (float)$livePrice;
                }
            } catch (Throwable $exception) {
                // Ignore API failure, fallback to base_price
            }
        }
    }

    return max(0.00000001, (float)($asset['base_price'] ?? 100.0));
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
    $twelvedataKey = (string)env('TWELVEDATA_API_KEY', '');
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
        $metalsKey = (string)env('METALS_API_KEY', '');
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

function market_status(array $asset, ?int $nowMs = null): array
{
    $nowMs = $nowMs ?? (int)floor(microtime(true) * 1000);
    $nowSec = (int)floor($nowMs / 1000);
    $isActive = (bool)($asset['is_active'] ?? true);
    $symbol = strtoupper(trim((string)($asset['symbol'] ?? '')));
    $category = strtolower(trim((string)($asset['category'] ?? 'forex')));
    
    // 1. Check if manually disabled/closed by admin
    if (!$isActive) {
        $updatedAt = !empty($asset['updated_at']) ? strtotime((string)$asset['updated_at']) : 0;
        if ($updatedAt <= 0) {
            $updatedAt = (int)floor($nowSec / 86400) * 86400;
        }
        $closedAtMs = $updatedAt * 1000;
        $closedPrice = market_price_at($asset, $closedAtMs);
        return [
            'isOpen' => false,
            'isOtc' => false,
            'reason' => 'disabled',
            'closedAt' => $closedAtMs,
            'closedPrice' => $closedPrice,
            'nextOpenAt' => null,
            'statusText' => 'Market is Closed'
        ];
    }

    $displayName = (string)($asset['display_name'] ?? '');
    $isOtc = (stripos($symbol, 'OTC') !== false || stripos($displayName, 'OTC') !== false || $category === 'otc' || stripos($category, 'otc') !== false);
    $isCrypto = ($category === 'crypto' || $category === 'cryptocurrency' || stripos($symbol, 'BTC') !== false || stripos($symbol, 'ETH') !== false || stripos($symbol, 'SOL') !== false || stripos($symbol, 'DOGE') !== false || stripos($symbol, 'XRP') !== false || stripos($symbol, 'BNB') !== false);

    // Crypto and OTC assets trade 24/7
    if ($isOtc || $isCrypto) {
        return [
            'isOpen' => true,
            'isOtc' => $isOtc,
            'reason' => 'open_24_7',
            'closedAt' => null,
            'closedPrice' => null,
            'nextOpenAt' => null,
            'statusText' => 'Market Open 24/7'
        ];
    }

    $isStock = ($category === 'stock' || $category === 'stocks' || in_array($symbol, ['AAPL', 'TSLA', 'NVDA', 'MSFT', 'AMZN', 'GOOGL', 'META', 'NFLX', 'BABA', 'INTC', 'AMD']));
    
    $dt = new DateTime('@' . $nowSec);
    $dt->setTimezone(new DateTimeZone('America/New_York')); // US Eastern Time
    
    $dayOfWeek = (int)$dt->format('w'); // 0 = Sun, 1 = Mon, ..., 6 = Sat
    $hour = (int)$dt->format('G'); // 0-23
    $minute = (int)$dt->format('i');
    $timeVal = $hour * 60 + $minute; // Minutes since midnight EST

    if ($isStock) {
        // US Stocks: Mon-Fri 9:30 AM (570 min) to 4:00 PM (960 min) EST
        $isWeekday = ($dayOfWeek >= 1 && $dayOfWeek <= 5);
        $isTradingHours = ($timeVal >= 570 && $timeVal < 960);
        
        if ($isWeekday && $isTradingHours) {
            return [
                'isOpen' => true,
                'isOtc' => false,
                'reason' => 'open_stock_session',
                'closedAt' => null,
                'closedPrice' => null,
                'nextOpenAt' => null,
                'statusText' => 'Market Open'
            ];
        }

        $closeDt = clone $dt;
        if ($isWeekday && $timeVal >= 960) {
            $closeDt->setTime(16, 0, 0);
        } elseif ($isWeekday && $timeVal < 570) {
            if ($dayOfWeek === 1) {
                $closeDt->modify('-3 days');
            } else {
                $closeDt->modify('-1 day');
            }
            $closeDt->setTime(16, 0, 0);
        } elseif ($dayOfWeek === 6) {
            $closeDt->modify('-1 day');
            $closeDt->setTime(16, 0, 0);
        } else {
            $closeDt->modify('-2 days');
            $closeDt->setTime(16, 0, 0);
        }

        $openDt = clone $dt;
        if ($isWeekday && $timeVal < 570) {
            $openDt->setTime(9, 30, 0);
        } elseif ($dayOfWeek === 5 && $timeVal >= 960) {
            $openDt->modify('+3 days');
            $openDt->setTime(9, 30, 0);
        } elseif ($dayOfWeek === 6) {
            $openDt->modify('+2 days');
            $openDt->setTime(9, 30, 0);
        } elseif ($dayOfWeek === 0) {
            $openDt->modify('+1 day');
            $openDt->setTime(9, 30, 0);
        } else {
            $openDt->modify('+1 day');
            $openDt->setTime(9, 30, 0);
        }

        $closedAtMs = $closeDt->getTimestamp() * 1000;
        $closedPrice = market_price_at($asset, $closedAtMs);

        return [
            'isOpen' => false,
            'isOtc' => false,
            'reason' => 'stock_hours_closed',
            'closedAt' => $closedAtMs,
            'closedPrice' => $closedPrice,
            'nextOpenAt' => $openDt->getTimestamp() * 1000,
            'statusText' => 'Market Closed (Opens ' . $openDt->format('D 9:30 AM EST') . ')'
        ];
    }

    // Forex / Commodities: Sun 17:00 EST (22:00 UTC) to Fri 17:00 EST (22:00 UTC)
    $isForexClosed = false;
    if ($dayOfWeek === 5 && $timeVal >= 1020) {
        $isForexClosed = true;
    } elseif ($dayOfWeek === 6) {
        $isForexClosed = true;
    } elseif ($dayOfWeek === 0 && $timeVal < 1020) {
        $isForexClosed = true;
    }

    if (!$isForexClosed) {
        return [
            'isOpen' => true,
            'isOtc' => false,
            'reason' => 'open_forex_session',
            'closedAt' => null,
            'closedPrice' => null,
            'nextOpenAt' => null,
            'statusText' => 'Market Open'
        ];
    }

    $closeDt = clone $dt;
    if ($dayOfWeek === 5) {
        $closeDt->setTime(17, 0, 0);
    } elseif ($dayOfWeek === 6) {
        $closeDt->modify('-1 day');
        $closeDt->setTime(17, 0, 0);
    } else {
        $closeDt->modify('-2 days');
        $closeDt->setTime(17, 0, 0);
    }

    $openDt = clone $dt;
    if ($dayOfWeek === 5) {
        $openDt->modify('+2 days');
        $openDt->setTime(17, 0, 0);
    } elseif ($dayOfWeek === 6) {
        $openDt->modify('+1 day');
        $openDt->setTime(17, 0, 0);
    } else {
        $openDt->setTime(17, 0, 0);
    }

    $closedAtMs = $closeDt->getTimestamp() * 1000;
    $closedPrice = market_price_at($asset, $closedAtMs);

    return [
        'isOpen' => false,
        'isOtc' => false,
        'reason' => 'weekend_closed',
        'closedAt' => $closedAtMs,
        'closedPrice' => $closedPrice,
        'nextOpenAt' => $openDt->getTimestamp() * 1000,
        'statusText' => 'Market Closed (Opens Sunday 5:00 PM EST)'
    ];
}

function market_asset_payload(array $asset, ?int $nowMs = null): array
{
    $nowMs = $nowMs ?? (int)floor(microtime(true) * 1000);
    $status = market_status($asset, $nowMs);
    $effectiveTime = $status['isOpen'] ? $nowMs : $status['closedAt'];
    $price = $status['isOpen'] ? market_price_at($asset, $nowMs) : (float)$status['closedPrice'];
    $past = market_price_at($asset, $effectiveTime - 300000);
    $change = $past > 0 ? (($price - $past) / $past) * 100 : 0.0;

    return [
        'symbol' => $asset['symbol'],
        'name' => $asset['display_name'],
        'category' => $asset['category'],
        'price' => $price,
        'decimals' => (int)$asset['decimal_places'],
        'payout' => $status['isOpen'] ? (float)$asset['payout_percent'] : 0.0,
        'changePercent' => round($change, 2),
        'active' => $status['isOpen'],
        'is_active' => $status['isOpen'] ? 1 : 0,
        'is_open' => $status['isOpen'],
        'closed_at' => $status['closedAt'],
        'closed_price' => $status['closedPrice'],
        'next_open_at' => $status['nextOpenAt'],
        'status_text' => $status['statusText'],
        'reason' => $status['reason'],
    ];
}

function market_snapshot(string $symbol, int $intervalSeconds = 5, int $count = 800, bool $includeCandles = true, ?int $endMs = null): array
{
    $asset = market_asset($symbol, false);
    if (!$asset) {
        $activeAssets = market_assets(true);
        $asset = !empty($activeAssets) ? $activeAssets[0] : null;
    }
    if (!$asset) {
        throw new InvalidArgumentException('Selected market is unavailable.');
    }

    $nowMs = (int)floor(microtime(true) * 1000);
    $status = market_status($asset, $nowMs);

    // Pre-warm Binance batch price cache so all binance assets are fetched in a single fast call
    try {
        fetch_binance_all_prices();
    } catch (Throwable $e) {}

    $assets = [];
    try {
        foreach (market_assets(false) as $row) {
            $assets[] = market_asset_payload($row, $nowMs);
        }
    } catch (Throwable $e) {
        $assets[] = market_asset_payload($asset, $nowMs);
    }

    $targetEndMs = $status['isOpen'] ? ($endMs !== null && $endMs > 0 ? $endMs : $nowMs) : $status['closedAt'];
    $targetPrice = $status['isOpen'] ? market_price_at($asset, $nowMs) : (float)$status['closedPrice'];
    $assetWithTarget = array_merge($asset, [
        'closed_price' => $status['closedPrice'],
        'target_price' => $targetPrice,
        'price' => $targetPrice,
    ]);

    $candles = [];
    if ($includeCandles) {
        try {
            $candles = market_candles($assetWithTarget, $intervalSeconds, $count, $targetEndMs);
        } catch (Throwable $e) {
            error_log('Failed to generate candles for ' . $symbol . ': ' . $e->getMessage());
        }
    }

    $assetPayload = market_asset_payload($asset, $nowMs);
    if (!empty($candles)) {
        $lastCandle = end($candles);
        if (isset($lastCandle['close']) && is_numeric($lastCandle['close'])) {
            $lastClose = (float)$lastCandle['close'];
            $assetPayload['price'] = $lastClose;
            if (!$status['isOpen']) {
                $assetPayload['closed_price'] = $lastClose;
            }
        }
    }

    foreach ($assets as &$a) {
        if (($a['symbol'] ?? '') === ($assetPayload['symbol'] ?? '')) {
            $a['price'] = $assetPayload['price'];
            if (isset($assetPayload['closed_price'])) {
                $a['closed_price'] = $assetPayload['closed_price'];
            }
        }
    }
    unset($a);

    return [
        'serverTime' => $nowMs,
        'asset' => $assetPayload,
        'assets' => $assets,
        'candles' => $candles,
        'engine' => 'market-engine-v1',
    ];
}
