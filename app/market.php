<?php
declare(strict_types=1);

function market_assets(bool $activeOnly = true): array
{
    static $cache = [];
    $key = $activeOnly ? '1' : '0';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $sql = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.price_source,a.api_symbol,a.is_active,a.sort_order,a.updated_at,c.price source_price,c.source source_name,c.fetched_at source_fetched_at FROM market_assets a LEFT JOIN market_price_cache c ON c.asset_id=a.id';
        if ($activeOnly) {
            $sql .= ' WHERE a.is_active = 1';
        }
        $sql .= ' ORDER BY a.sort_order ASC,a.display_name ASC';
        $cache[$key] = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        try {
            $fallbackSql = 'SELECT a.id,a.symbol,a.display_name,a.category,a.base_price,a.decimal_places,a.payout_percent,a.volatility,a.market_seed,a.is_active,a.sort_order,a.updated_at FROM market_assets a';
            if ($activeOnly) {
                $fallbackSql .= ' WHERE a.is_active = 1';
            }
            $fallbackSql .= ' ORDER BY a.sort_order ASC,a.display_name ASC';
            $cache[$key] = db()->query($fallbackSql)->fetchAll();
        } catch (Throwable $e2) {
            $cache[$key] = [];
        }
    }
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

    try {
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
    } catch (Throwable $e) {
        foreach (market_assets($activeOnly) as $row) {
            if (strtoupper((string)($row['symbol'] ?? '')) === $cleanSymbol) {
                $assetCache[$cacheKey] = $row;
                return $row;
            }
        }
    }

    $assetCache[$cacheKey] = null;
    return null;
}

function market_noise(int $seed, int $bucket): float
{
    $hash = hash('sha256', $seed . ':' . $bucket);
    $value = (float)hexdec(substr($hash, 0, 8));
    return ($value / 4294967295.0) * 2.0 - 1.0;
}

function market_is_currency(array $asset): bool
{
    return strtolower(trim((string)($asset['category'] ?? ''))) === 'currencies';
}

function determine_trade_outcome(array $trade, ?array $control = null): ?string
{
    $userId = (int)($trade['user_id'] ?? 0);
    $assetId = (int)($trade['asset_id'] ?? 0);
    $tradeId = (int)($trade['id'] ?? 0);

    if ($userId <= 0 || $assetId <= 0 || $tradeId <= 0) {
        return null;
    }

    if ($control === null) {
        if (function_exists('get_active_trading_control')) {
            $control = get_active_trading_control($userId, $assetId);
        } else {
            try {
                $stmt = db()->prepare(
                    "SELECT id, asset_id, user_id, direction, win_rate
                     FROM trading_controls
                     WHERE is_active = 1
                       AND (expires_at IS NULL OR expires_at > NOW())
                       AND (asset_id IS NULL OR asset_id = ?)
                       AND (user_id IS NULL OR user_id = ?)
                     ORDER BY
                       (asset_id IS NOT NULL AND user_id IS NOT NULL) DESC,
                       (user_id IS NOT NULL) DESC,
                       (asset_id IS NOT NULL) DESC,
                       id DESC
                     LIMIT 1"
                );
                $stmt->execute([$assetId, $userId]);
                $control = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $ignored) {
                $control = null;
            }
        }
    }

    if ($control === null) {
        return null;
    }

    $forcedDir = (string)($control['direction'] ?? 'none');
    $winRate = isset($control['win_rate']) && $control['win_rate'] !== null
        ? max(0.0, min(100.0, (float)$control['win_rate']))
        : null;

    if (in_array($forcedDir, ['up', 'down'], true)) {
        $isMatchingDir = $trade['direction'] === $forcedDir;
        if ($winRate !== null) {
            $effectiveRate = $isMatchingDir ? $winRate : max(0.0, 100.0 - $winRate);
            $seed = crc32((string)$tradeId . ':' . $userId . ':wctrl');
            $normalised = ($seed < 0 ? ($seed + 4294967296) : $seed) / 4294967295.0;
            return ($normalised * 100.0 < $effectiveRate) ? 'won' : 'lost';
        }
        return $isMatchingDir ? 'won' : 'lost';
    }

    if ($winRate !== null) {
        $seed = crc32((string)$tradeId . ':' . $userId . ':wctrl');
        $normalised = ($seed < 0 ? ($seed + 4294967296) : $seed) / 4294967295.0;
        return ($normalised * 100.0 < $winRate) ? 'won' : 'lost';
    }

    return null;
}

function get_active_trade_price_bias(array $asset, int $timestampMs, ?int $userId = null, bool $fresh = false): float
{
    static $tradesCache = [];
    if ($fresh) {
        $tradesCache = [];
    }

    if ($userId === null) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 && function_exists('current_user')) {
            try {
                $u = current_user();
                $userId = $u ? (int)($u['id'] ?? 0) : 0;
            } catch (Throwable $ignored) {
                $userId = 0;
            }
        }
    }

    if ($userId <= 0) {
        return 0.0;
    }

    $assetId = (int)($asset['id'] ?? 0);
    if ($assetId <= 0) {
        return 0.0;
    }

    $cacheKey = $userId . ':' . $assetId;
    if (!array_key_exists($cacheKey, $tradesCache)) {
        try {
            $stmt = db()->prepare(
                "SELECT id, user_id, asset_id, direction, entry_price, opened_at, expires_at 
                 FROM trades 
                 WHERE user_id = ? AND asset_id = ? AND status = 'open' AND expires_at > NOW()
                 ORDER BY id DESC"
            );
            $stmt->execute([$userId, $assetId]);
            $tradesCache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $tradesCache[$cacheKey] = [];
        }
    }

    $openTrades = $tradesCache[$cacheKey];
    if (empty($openTrades)) {
        return 0.0;
    }

    $trade = $openTrades[0];
    $openedAtMs = (int)strtotime((string)$trade['opened_at']) * 1000;
    $expiresAtMs = (int)strtotime((string)$trade['expires_at']) * 1000;

    if ($timestampMs < $openedAtMs - 1000 || $timestampMs > $expiresAtMs + 2000) {
        return 0.0;
    }

    $outcome = determine_trade_outcome($trade);
    if ($outcome === null) {
        return 0.0;
    }

    $durationMs = max(1000, $expiresAtMs - $openedAtMs);
    $elapsedMs = max(0, $timestampMs - $openedAtMs);
    $progress = min(1.0, $elapsedMs / (float)$durationMs);

    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
    $tick = pow(10, -$decimals);
    $entryPrice = (float)$trade['entry_price'];

    // Calculate natural price without bias at $timestampMs
    $naturalPriceAtTime = market_price_at($asset, $timestampMs, true);

    $basePrice = $naturalPriceAtTime > 0 ? $naturalPriceAtTime : (float)($asset['base_price'] ?? 1.0);
    if ($basePrice <= 0) {
        $basePrice = market_reference_price($asset);
    }

    $maxDelta = max($tick * 4.0, $basePrice * 0.00035);

    if ($trade['direction'] === 'up') {
        $directionMultiplier = ($outcome === 'lost') ? -1.0 : 1.0;
    } else {
        $directionMultiplier = ($outcome === 'lost') ? 1.0 : -1.0;
    }

    $curve = pow($progress, 0.7);
    $smoothDrift = $maxDelta * $curve * $directionMultiplier;

    $sec = $timestampMs / 1000.0;
    $microFluctuation = sin($sec * 2.5) * ($maxDelta * 0.08);

    // Smooth drift bias steering towards outcome without forcing baseline jump
    $rawOffset = $smoothDrift + $microFluctuation;
    $maxOffsetLimit = $maxDelta * 3.0;
    if (abs($rawOffset) > $maxOffsetLimit) {
        $rawOffset = ($rawOffset > 0 ? 1.0 : -1.0) * $maxOffsetLimit;
    }

    return $rawOffset;
}

function market_price_at(array $asset, int $timestampMs, bool $withoutBias = false): float
{
    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
    $source = $asset['price_source'] ?? 'internal';
    $nowMs = (int)floor(microtime(true) * 1000);
    $isLive = abs($nowMs - $timestampMs) < 60000;

    $bias = $withoutBias ? 0.0 : get_active_trade_price_bias($asset, $timestampMs);

    if (!market_is_currency($asset) && $isLive && ($source === 'api' || $source === 'binance')) {
        $symbol = trim((string)($asset['api_symbol'] ?? $asset['symbol'] ?? ''));
        if ($symbol !== '') {
            try {
                $livePrice = fetch_market_price($symbol);
                if (is_numeric($livePrice) && $livePrice > 0) {
                    return round((float)$livePrice + $bias, $decimals);
                }
            } catch (Throwable $exception) {
                // Ignore API failure, fallback to reference math
            }
        }
        if (isset($asset['source_price']) && (float)$asset['source_price'] > 0) {
            return round((float)$asset['source_price'] + $bias, $decimals);
        }
        return 0.0;
    }

    $minStep = pow(10, -$decimals);

    $seconds = $timestampMs / 1000.0;
    $seed = (int)($asset['market_seed'] ?? 100);
    $base = market_reference_price($asset);

    $category = strtolower(trim((string)($asset['category'] ?? '')));
    $assetVol = (float)($asset['volatility'] ?? 0.001);

    if ($category === 'currencies' || market_is_currency($asset)) {
        // Currency / Forex base volatility: 0.00005 to 0.00035 (0.5 to 3.5 pips per minute)
        $volatility = max(0.00005, min(0.00035, $assetVol > 0 && $assetVol < 0.001 ? $assetVol : 0.00012));
    } elseif ($category === 'crypto') {
        $volatility = max(0.0002, min(0.003, $assetVol > 0 ? $assetVol : 0.0008));
    } elseif ($category === 'stocks' || $category === 'indices') {
        $volatility = max(0.00008, min(0.0008, $assetVol > 0 ? $assetVol : 0.00025));
    } else {
        $volatility = max(0.00008, min(0.001, $assetVol > 0 ? $assetVol : 0.0003));
    }

    $phase = ($seed % 997) / 17.0;

    // Harmonic multi-frequency wave cycles (micro oscillation, swing wave, trend cycle, macro context)
    $wMicro = sin(($seconds + $phase * 73.0) / 4.5) * 0.16;
    $wSwing = sin(($seconds + $phase * 31.0) / 18.0) * 0.32;
    $wTrend = sin(($seconds + $phase * 17.0) / 65.0) * 0.38;
    $wMacro = sin(($seconds + $phase * 7.0) / 220.0) * 0.24;

    $waveTotal = $wMicro + $wSwing + $wTrend + $wMacro;

    // Smooth continuous spline noise
    $bucketSize = 6;
    $bucket = (int)floor($seconds / $bucketSize);
    $fraction = fmod($seconds, (float)$bucketSize) / $bucketSize;
    if ($fraction < 0) {
        $fraction += 1.0;
    }
    $smooth = $fraction * $fraction * (3.0 - 2.0 * $fraction);
    $noiseA = market_noise($seed, $bucket);
    $noiseB = market_noise($seed, $bucket + 1);
    $noise = $noiseA + (($noiseB - $noiseA) * $smooth);

    $ratio = ($waveTotal + ($noise * 0.40)) * $volatility * 2.2;
    $price = max($base * 0.1, $base * (1.0 + $ratio));

    return round($price + $bias, $decimals);
}

function market_candles(array $asset, int $intervalSeconds = 5, int $count = 800, ?int $endMs = null): array
{
    $intervalSeconds = max(1, min(86400, $intervalSeconds));
    $count = max(10, min(3000, $count));
    $endMs = $endMs ?? ((int)floor(microtime(true) * 1000));
    $decimals = max(0, min(8, (int)$asset['decimal_places']));
    $source = $asset['price_source'] ?? 'internal';
    $apiSymbol = trim((string)($asset['api_symbol'] ?? $asset['symbol'] ?? ''));
    $intervalMs = max(1000, $intervalSeconds * 1000);
    $minStep = pow(10, -$decimals);
    $volatility = max(0.0001, (float)($asset['volatility'] ?? 0.002));
    $seed = (int)($asset['market_seed'] ?? 100);

    $binanceKlines = [];

    // Fetch real Binance klines if asset is configured for Binance / API
    if (!market_is_currency($asset) && !$binanceKlines && ($source === 'binance' || $source === 'api') && $apiSymbol !== '') {
        try {
            // Request enough klines from Binance to cover the requested candle count (up to 10000 max).
            $neededBinanceKlines = max(10, min(10000, $count));
            $binanceKlines = fetch_binance_klines($apiSymbol, $intervalSeconds, $neededBinanceKlines, $endMs);

            if (!empty($binanceKlines)) {
                // Build a minute lookup map for Binance klines indexed by openTime (ms)
                $bMap = [];
                foreach ($binanceKlines as $k) {
                    $bOpenTime = (int)$k['openTime'];
                    $bMap[$bOpenTime] = [
                        'open' => (float)$k['open'],
                        'high' => (float)$k['high'],
                        'low' => (float)$k['low'],
                        'close' => (float)$k['close'],
                        'closeTime' => (int)$k['closeTime'],
                    ];
                }

                $sortedTimes = array_keys($bMap);
                sort($sortedTimes);

                $targetEndBucket = (int)(floor($endMs / $intervalMs) * $intervalMs);
                $targetStartBucket = $targetEndBucket - (($count - 1) * $intervalMs);

                // Initial prevClose from lowest available kline or market price
                $firstTime = $sortedTimes[0] ?? $targetStartBucket;
                $prevClose = (float)($bMap[$firstTime]['open'] ?? market_price_at($asset, $targetStartBucket, true));
                if ($prevClose <= 0) {
                    $prevClose = market_price_at($asset, $targetStartBucket, true);
                }

                $candles = [];
                for ($t = $targetStartBucket; $t <= $targetEndBucket; $t += $intervalMs) {
                    $bKey = null;
                    if (isset($bMap[$t])) {
                        $bKey = $t;
                    } else {
                        // Find closest floor binance time <= $t
                        $floorMin = (int)(floor($t / 60000) * 60000);
                        if (isset($bMap[$floorMin])) {
                            $bKey = $floorMin;
                        } else {
                            // Find most recent available binance time <= $t
                            foreach ($sortedTimes as $st) {
                                if ($st <= $t) {
                                    $bKey = $st;
                                } else {
                                    break;
                                }
                            }
                        }
                    }

                    // Always chain open from prevClose to prevent vertical price gaps between candles
                    $cOpen = $prevClose;

                    if ($bKey !== null && isset($bMap[$bKey])) {
                        $bKline = $bMap[$bKey];

                        if ($intervalSeconds >= 60) {
                            // Standard 1m+ candle: target close is the Binance close
                            $targetClose = (float)$bKline['close'];
                            $targetHigh  = (float)$bKline['high'];
                            $targetLow   = (float)$bKline['low'];

                            $cClose = round($targetClose, $decimals);
                            $cHigh  = round(max($cOpen, $cClose, $targetHigh), $decimals);
                            $cLow   = round(min($cOpen, $cClose, $targetLow), $decimals);
                        } else {
                            // Sub-minute candle (5s, 10s, 15s, 30s): interpolate smoothly across the 60s Binance kline
                            $secInMin = (int)(($t - $bKey) / 1000) % 60;
                            $fractionStart = $secInMin / 60.0;
                            $fractionEnd   = min(1.0, ($secInMin + $intervalSeconds) / 60.0);

                            $bOpen  = (float)$bKline['open'];
                            $bClose = (float)$bKline['close'];
                            $bHigh  = (float)$bKline['high'];
                            $bLow   = (float)$bKline['low'];

                            // Progression through the minute
                            $trendClose = $bOpen + ($bClose - $bOpen) * $fractionEnd;
                            $noise = market_noise($seed + 77, (int)($t / 1000)) * $volatility * 0.05;
                            $cClose = round(max($minStep, $trendClose + ($bOpen * $noise)), $decimals);

                            $subSpan = abs($cClose - $cOpen);
                            $wick = max($minStep * 2.0, $subSpan * 0.4);
                            $cHigh = round(max($cOpen, $cClose) + $wick, $decimals);
                            $cLow  = round(max($minStep, min($cOpen, $cClose) - $wick), $decimals);

                            // Bound within minute extremes
                            $cHigh = min(round(max($bHigh, $cOpen, $cClose), $decimals), $cHigh);
                            $cLow  = max(round(min($bLow, $cOpen, $cClose), $decimals), $cLow);
                        }
                    } else {
                        // Bucket missing (low volume gap): continuous micro-trend
                        $noiseVal = market_noise($seed, (int)($t / 1000));
                        $cClose = round(max($minStep * 10.0, $cOpen * (1.0 + $noiseVal * $volatility * 0.1)), $decimals);
                        $cHigh  = round(max($cOpen, $cClose) + $minStep * 3.0, $decimals);
                        $cLow   = round(max($minStep, min($cOpen, $cClose) - $minStep * 3.0), $decimals);
                    }

                    if ($cHigh <= $cLow) {
                        $cHigh = round(max($cOpen, $cClose) + $minStep * 2, $decimals);
                        $cLow  = round(max($minStep, min($cOpen, $cClose) - $minStep * 2), $decimals);
                    }

                    $cOpenR  = round($cOpen, $decimals);
                    $cHighR  = round(max($cHigh, $cOpenR, $cClose), $decimals);
                    $cLowR   = round(min($cLow, $cOpenR, $cClose), $decimals);
                    $cCloseR = round($cClose, $decimals);

                    $cEnd = min($t + $intervalMs - 1, $endMs);
                    $isComplete = ($t + $intervalMs) <= $endMs;

                    $p1 = $cCloseR >= $cOpenR ? $cLowR : $cHighR;
                    $p2 = $cCloseR >= $cOpenR ? $cHighR : $cLowR;

                    $ticks = [
                        [$t, $cOpenR],
                        [$t + (int)round($intervalMs * 0.33), $p1],
                        [$t + (int)round($intervalMs * 0.67), $p2],
                        [$cEnd, $cCloseR],
                    ];

                    $candles[] = [
                        'time' => $t,
                        'open' => $cOpenR,
                        'high' => $cHighR,
                        'low' => $cLowR,
                        'close' => $cCloseR,
                        'lastTickTime' => $cEnd,
                        'complete' => $isComplete,
                        'ticks' => $ticks,
                    ];

                    $prevClose = $cCloseR;
                }

                return $candles;
            }
        } catch (Throwable $e) {
            error_log('Failed to fetch Binance klines for ' . $apiSymbol . ': ' . $e->getMessage());
        }
    }

    // Fallback generator if API is unavailable, symbol not on Binance, or internal asset

    // Fallback generator if API is unavailable or internal asset
    $intervalMs = $intervalSeconds * 1000;
    $currentBucketMs = (int)(floor($endMs / $intervalMs) * $intervalMs);
    $startMs = $currentBucketMs - (($count - 1) * $intervalMs);
    $seed = (int)($asset['market_seed'] ?? 100);

    $category = strtolower(trim((string)($asset['category'] ?? '')));
    $assetVol = (float)($asset['volatility'] ?? 0.001);
    if ($category === 'currencies' || market_is_currency($asset)) {
        $vol = max(0.00005, min(0.00035, $assetVol > 0 && $assetVol < 0.001 ? $assetVol : 0.00012));
    } elseif ($category === 'crypto') {
        $vol = max(0.0002, min(0.003, $assetVol > 0 ? $assetVol : 0.0008));
    } else {
        $vol = max(0.00005, min(0.001, $assetVol > 0 ? $assetVol : 0.00025));
    }

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
            $close = round($noiseDir >= 0 ? $open + $minStep : max($minStep, $open - $minStep), $decimals);
        }

        // Elegant, natural candle wicks: distinct upper and lower shadows, crisp and realistic
        $bodyHeight = abs($close - $open);
        $noiseUpper = abs(market_noise($seed + 99, (int)($time / 1000)));
        $noiseLower = abs(market_noise($seed + 101, (int)($time / 1000)));

        $wickUpper = max(0.0, min($bodyHeight * 0.55, $bodyHeight * 0.22 * $noiseUpper + ($open * $vol * 0.05 * $noiseUpper)));
        $wickLower = max(0.0, min($bodyHeight * 0.55, $bodyHeight * 0.22 * $noiseLower + ($open * $vol * 0.05 * $noiseLower)));

        $high = round(max($open, $close) + $wickUpper, $decimals);
        $low  = round(max($minStep, min($open, $close) - $wickLower), $decimals);

        if ($high <= max($open, $close)) {
            $high = round(max($open, $close) + ($noiseUpper > 0.4 ? $minStep : 0.0), $decimals);
        }
        if ($low >= min($open, $close)) {
            $low = round(max($minStep, min($open, $close) - ($noiseLower > 0.4 ? $minStep : 0.0)), $decimals);
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
    if (!market_is_currency($asset) && isset($asset['source_price']) && is_numeric($asset['source_price']) && (float)$asset['source_price'] > 0) {
        return (float)$asset['source_price'];
    }

    $source = $asset['price_source'] ?? 'internal';
    if (!market_is_currency($asset) && ($source === 'api' || $source === 'binance')) {
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

    // Use Twelve Data for forex assets.
    $twelvedataKey = (string)env('TWELVEDATA_API_KEY', '');
    if (strtolower((string)($asset['category'] ?? '')) === 'currencies' && $twelvedataKey !== '') {
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
            throw new InvalidArgumentException('Configure TWELVEDATA_API_KEY in .env or set up a custom market API URL with {symbol} placeholder in Settings.');
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

function market_asset_payload(array $asset, ?int $nowMs = null, bool $resolveLivePrice = true): array
{
    $nowMs = $nowMs ?? (int)floor(microtime(true) * 1000);
    $status = market_status($asset, $nowMs);
    $effectiveTime = $status['isOpen'] ? $nowMs : $status['closedAt'];
    if ($status['isOpen'] && !$resolveLivePrice) {
        $price = (float)($asset['source_price'] ?? $asset['base_price'] ?? 0);
        $past = $price;
    } else {
        $price = $status['isOpen'] ? market_price_at($asset, $nowMs) : (float)$status['closedPrice'];
        $past = market_price_at($asset, $effectiveTime - 300000);
    }
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
        $allAssets = market_assets(false);
        $asset = !empty($allAssets) ? $allAssets[0] : null;
    }
    if (!$asset) {
        $asset = [
            'id' => 1,
            'symbol' => strtoupper($symbol) ?: 'BTCUSD',
            'display_name' => strtoupper($symbol) ?: 'Bitcoin / USD',
            'category' => 'Crypto',
            'base_price' => 60000.0,
            'decimal_places' => 2,
            'payout_percent' => 85,
            'volatility' => 0.002,
            'market_seed' => 100,
            'price_source' => 'binance',
            'api_symbol' => strtoupper($symbol) ?: 'BTCUSDT',
            'is_active' => 1,
            'sort_order' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    $nowMs = (int)floor(microtime(true) * 1000);
    $status = market_status($asset, $nowMs);

    // Pre-warm Binance batch price cache so all binance assets are fetched in a single fast call
    if (!market_is_currency($asset)) {
        try {
            fetch_binance_all_prices();
        } catch (Throwable $e) {}
    }

    $assets = [];
    try {
        foreach (market_assets(false) as $row) {
            $isSelected = strtoupper((string)($row['symbol'] ?? '')) === strtoupper((string)($asset['symbol'] ?? ''));
            $assets[] = market_asset_payload($row, $nowMs, $isSelected);
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
    if (!$status['isOpen']) {
        $officialClosedP = (float)$status['closedPrice'];
        $assetPayload['price'] = $officialClosedP;
        $assetPayload['closed_price'] = $officialClosedP;

        if (!empty($candles)) {
            $lastIdx = count($candles) - 1;
            $candles[$lastIdx]['close'] = $officialClosedP;
            $candles[$lastIdx]['high'] = max((float)$candles[$lastIdx]['high'], $officialClosedP);
            $candles[$lastIdx]['low'] = min((float)$candles[$lastIdx]['low'], $officialClosedP);
            if (!empty($candles[$lastIdx]['ticks']) && is_array($candles[$lastIdx]['ticks']) && count($candles[$lastIdx]['ticks']) > 0) {
                $tCount = count($candles[$lastIdx]['ticks']);
                $candles[$lastIdx]['ticks'][$tCount - 1][1] = $officialClosedP;
            }
        }
    } else if (!empty($candles)) {
        $bias = get_active_trade_price_bias($asset, $nowMs);
        if ($bias != 0.0) {
            $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
            $minStep = pow(10, -$decimals);

            // Apply active trade control bias smoothly across open trade candles
            foreach ($candles as &$c) {
                $cTime = (int)($c['time'] ?? 0);
                $cEnd = (int)($c['lastTickTime'] ?? ($cTime + ($intervalSeconds * 1000) - 1));
                $bStart = get_active_trade_price_bias($asset, $cTime);
                $bEnd = get_active_trade_price_bias($asset, $cEnd);

                if ($bStart != 0.0 || $bEnd != 0.0) {
                    $cOpen = round((float)$c['open'] + $bStart, $decimals);
                    $cClose = round((float)$c['close'] + $bEnd, $decimals);
                    $cHigh = round(max((float)$c['high'] + max($bStart, $bEnd), $cOpen, $cClose), $decimals);
                    $cLow = round(max($minStep, min((float)$c['low'] + min($bStart, $bEnd), $cOpen, $cClose)), $decimals);

                    $c['open'] = $cOpen;
                    $c['close'] = $cClose;
                    $c['high'] = $cHigh;
                    $c['low'] = $cLow;

                    if (!empty($c['ticks']) && is_array($c['ticks'])) {
                        foreach ($c['ticks'] as &$tk) {
                            $tMs = (int)$tk[0];
                            $tB = get_active_trade_price_bias($asset, $tMs);
                            $tk[1] = round((float)$tk[1] + $tB, $decimals);
                        }
                        unset($tk);
                    }
                }
            }
            unset($c);

            $lastIdx = count($candles) - 1;
            $candles[$lastIdx]['close'] = $targetPrice;
            $candles[$lastIdx]['high'] = max((float)$candles[$lastIdx]['high'], $targetPrice);
            $candles[$lastIdx]['low'] = min((float)$candles[$lastIdx]['low'], $targetPrice);
            if (!empty($candles[$lastIdx]['ticks']) && is_array($candles[$lastIdx]['ticks']) && count($candles[$lastIdx]['ticks']) > 0) {
                $tCount = count($candles[$lastIdx]['ticks']);
                $candles[$lastIdx]['ticks'][$tCount - 1][1] = $targetPrice;
            }
            $assetPayload['price'] = $targetPrice;
        } else {
            $lastCandle = end($candles);
            if (isset($lastCandle['close']) && is_numeric($lastCandle['close'])) {
                $lastClose = (float)$lastCandle['close'];
                $assetPayload['price'] = $lastClose;
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
