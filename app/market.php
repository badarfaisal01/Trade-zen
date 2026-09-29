<?php
declare(strict_types=1);

function market_assets(bool $activeOnly = true): array
{
    static $cache = [];
    static $cleanedUp = false;
    $key = $activeOnly ? '1' : '0';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!$cleanedUp) {
        $cleanedUp = true;
        try {
            db()->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='crypto' OR category LIKE '%crypto%') AND symbol NOT IN ('BTCUSD','ETHUSD','SOLUSD','BNBUSD','XRPUSD','DOGEUSD')");
            db()->exec("UPDATE market_assets SET is_active=1 WHERE symbol IN ('BTCUSD','ETHUSD','SOLUSD','BNBUSD','XRPUSD','DOGEUSD')");

            db()->exec("INSERT INTO market_assets (symbol, display_name, category, base_price, decimal_places, payout_percent, volatility, market_seed, price_source, api_symbol, sort_order, is_active, created_at, updated_at) VALUES
                ('XAUUSD', 'Gold / USD', 'Commodities', 4136.00, 2, 85.00, 0.00250000, 88101, 'binance', 'PAXGUSDT', 1, 1, NOW(), NOW()),
                ('XAUTUSD', 'Tether Gold / USD', 'Commodities', 4131.00, 2, 85.00, 0.00250000, 88102, 'binance', 'XAUTUSDT', 2, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), category='Commodities', price_source='binance', api_symbol=VALUES(api_symbol), is_active=1, sort_order=VALUES(sort_order), updated_at=NOW()");
            db()->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='commodities' OR LOWER(category)='commodity' OR category LIKE '%commodit%') AND symbol NOT IN ('XAUUSD','XAUTUSD')");

            db()->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='currencies' OR LOWER(category)='currency' OR category LIKE '%currenc%') AND symbol NOT IN ('EURUSD','GBPUSD','AUDUSD')");
            db()->exec("UPDATE market_assets SET is_active=1, decimal_places=5, price_source='binance', api_symbol='EURUSDT', display_name='EUR/USD', payout_percent=88.00, volatility=0.00018 WHERE symbol='EURUSD'");
            db()->exec("UPDATE market_assets SET is_active=1, decimal_places=5, price_source='binance', api_symbol='GBPUSDT', display_name='GBP/USD', payout_percent=85.00, volatility=0.00020 WHERE symbol='GBPUSD'");
            db()->exec("UPDATE market_assets SET is_active=1, decimal_places=5, price_source='binance', api_symbol='AUDUSDT', display_name='AUD/USD', payout_percent=82.00, volatility=0.00018 WHERE symbol='AUDUSD'");
        } catch (Throwable $e) {}
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

function get_active_trade_controlled_price(array $asset, int $timestampMs, ?int $userId = null, bool $fresh = false): ?float
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
        return null;
    }

    $assetId = (int)($asset['id'] ?? 0);
    if ($assetId <= 0) {
        return null;
    }

    $sec = (int)floor($timestampMs / 1000.0);
    $cacheKey = $userId . ':' . $assetId . ':' . $sec;

    if (!array_key_exists($cacheKey, $tradesCache)) {
        try {
            $timeStr = date('Y-m-d H:i:s', $sec);
            $decayStartStr = date('Y-m-d H:i:s', max(0, $sec - 6));
            $stmt = db()->prepare(
                "SELECT id, user_id, asset_id, direction, entry_price, opened_at, expires_at 
                 FROM trades 
                 WHERE user_id = ? AND asset_id = ? 
                   AND opened_at <= ? AND expires_at >= ?
                 ORDER BY expires_at ASC, id ASC"
            );
            $stmt->execute([$userId, $assetId, $timeStr, $decayStartStr]);
            $tradesCache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $tradesCache[$cacheKey] = [];
        }
    }

    $matchingTrades = $tradesCache[$cacheKey];
    if (empty($matchingTrades)) {
        return null;
    }

    $activeTrade = null;
    $decayTrade = null;

    foreach ($matchingTrades as $t) {
        $opMs = (int)strtotime((string)$t['opened_at']) * 1000;
        $exMs = (int)strtotime((string)$t['expires_at']) * 1000;

        if ($timestampMs >= $opMs && $timestampMs <= $exMs) {
            $activeTrade = $t;
            break;
        } elseif ($timestampMs > $exMs && $timestampMs <= $exMs + 4000) {
            if ($decayTrade === null) {
                $decayTrade = $t;
            }
        }
    }

    $trade = $activeTrade ?: $decayTrade;
    if (!$trade) {
        return null;
    }

    $openedAtMs = (int)strtotime((string)$trade['opened_at']) * 1000;
    $expiresAtMs = (int)strtotime((string)$trade['expires_at']) * 1000;
    $isDecaying = ($timestampMs > $expiresAtMs);

    if ($timestampMs < $openedAtMs || $timestampMs > $expiresAtMs + 4000) {
        return null;
    }

    $control = null;
    if (function_exists('get_active_trading_control')) {
        $control = get_active_trading_control($userId, $assetId);
    }
    $outcome = determine_trade_outcome($trade, $control);
    if ($outcome === null) {
        return null;
    }

    $durationMs = max(1000, $expiresAtMs - $openedAtMs);

    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
    $tick = pow(10, -$decimals);
    $entryPrice = (float)$trade['entry_price'];

    $basePrice = $entryPrice > 0 ? $entryPrice : (float)($asset['base_price'] ?? 1.0);
    if ($basePrice <= 0) {
        $basePrice = market_reference_price($asset);
    }

    // Tight, subtle margin: just enough past entry price so user loses/wins cleanly without huge vertical moves
    $tightMargin = max($tick * 2.0, $basePrice * 0.00015);
    $winRate = 50.0;
    if ($control !== null && isset($control['win_rate']) && $control['win_rate'] !== null) {
        $winRate = max(0.0, min(100.0, (float)$control['win_rate']));
    }

    $intensity = abs($winRate - 50.0) / 50.0;
    $targetMargin = $tightMargin * (1.0 + ($intensity * 0.5));

    if ($trade['direction'] === 'up') {
        $targetPrice = ($outcome === 'lost') ? ($entryPrice - $targetMargin) : ($entryPrice + $targetMargin);
    } else {
        $targetPrice = ($outcome === 'lost') ? ($entryPrice + $targetMargin) : ($entryPrice - $targetMargin);
    }

    if (!$isDecaying) {
        // Steering Curve Strategy:
        // Keep price floating close to entry price with subtle natural oscillations for the first ~75% of the trade duration.
        // In the final 25% (or last 8 seconds), steer smoothly and decisively just past entry price so the outcome is secured.
        $elapsedMs = max(0, $timestampMs - $openedAtMs);
        $remainingMs = max(0, $expiresAtMs - $timestampMs);
        $rawProgress = min(1.0, max(0.0, $elapsedMs / (float)$durationMs));

        // Define steering window: final 8000ms or last 25% of trade, whichever is shorter
        $steerWindowMs = min((float)$durationMs * 0.35, 8000.0);
        $sec = $timestampMs / 1000.0;

        if ($remainingMs > $steerWindowMs) {
            // Early/Mid trade: natural organic oscillation around entry price (+/- 2 ticks)
            $earlyWave = sin($sec * 1.8) * ($tightMargin * 0.40);
            $price = $entryPrice + $earlyWave;
        } else {
            // Final phase: smooth progress from current position to targetPrice
            $steerElapsed = $steerWindowMs - $remainingMs;
            $steerProgress = min(1.0, max(0.0, $steerElapsed / $steerWindowMs));
            $smoothFactor = $steerProgress * $steerProgress * (3.0 - 2.0 * $steerProgress);

            $earlyWaveAtSteerStart = sin(($expiresAtMs - $steerWindowMs) / 1000.0 * 1.8) * ($tightMargin * 0.40);
            $startPrice = $entryPrice + $earlyWaveAtSteerStart;
            $microFluctuation = sin($sec * 2.5) * ($tightMargin * 0.05 * (1.0 - $smoothFactor));

            $price = $startPrice + (($targetPrice - $startPrice) * $smoothFactor) + $microFluctuation;
        }

        return round($price, $decimals);
    } else {
        // Post-expiry decay window (0..4s): interpolate from targetPrice back to real market price
        $rawRealApiPrice = market_price_at($asset, $timestampMs, true);
        $elapsedPostMs = $timestampMs - $expiresAtMs;
        $rawPostProgress = min(1.0, max(0.0, $elapsedPostMs / 4000.0));
        $smoothFactor = $rawPostProgress * $rawPostProgress * (3.0 - 2.0 * $rawPostProgress);

        $price = $targetPrice + (($rawRealApiPrice - $targetPrice) * $smoothFactor);
        return round($price, $decimals);
    }
}

function get_active_trade_price_bias(array $asset, int $timestampMs, ?int $userId = null, bool $fresh = false, ?float $unbiasedBasePrice = null): float
{
    $controlledPrice = get_active_trade_controlled_price($asset, $timestampMs, $userId, $fresh);
    if ($controlledPrice === null) {
        return 0.0;
    }
    $rawRealPrice = ($unbiasedBasePrice !== null && $unbiasedBasePrice > 0)
        ? $unbiasedBasePrice
        : market_price_at($asset, $timestampMs, true);

    if ($rawRealPrice <= 0) {
        return 0.0;
    }

    $bias = $controlledPrice - $rawRealPrice;

    // Safety Clamp: Trade steering is designed for subtle tight margins (max +/- 0.005 of asset price or +/- 50 ticks)
    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));
    $tick = pow(10, -$decimals);
    $maxBias = max($tick * 50.0, $rawRealPrice * 0.005);

    return max(-$maxBias, min($maxBias, $bias));
}

function market_price_at(array $asset, int $timestampMs, bool $withoutBias = false, ?int $userId = null): float
{
    $decimals = max(0, min(8, (int)($asset['decimal_places'] ?? 2)));

    if (!$withoutBias) {
        $controlledPrice = get_active_trade_controlled_price($asset, $timestampMs, $userId);
        if ($controlledPrice !== null) {
            return round($controlledPrice, $decimals);
        }
    }

    $source = $asset['price_source'] ?? 'internal';
    $nowMs = (int)floor(microtime(true) * 1000);
    $isLive = abs($nowMs - $timestampMs) < 60000;
    $isCurrency = market_is_currency($asset) || strtolower((string)($asset['category'] ?? '')) === 'currencies';

    $symbol = trim((string)($asset['api_symbol'] ?? $asset['symbol'] ?? ''));
    if ($symbol !== '' && ($source === 'api' || $source === 'binance' || $isCurrency)) {
        if ($isLive && function_exists('fetch_market_price')) {
            try {
                $livePrice = fetch_market_price($symbol);
                if (is_numeric($livePrice) && $livePrice > 0) {
                    $liveRounded = round((float)$livePrice, $decimals);
                    $storedBase = (float)($asset['base_price'] ?? 0);
                    if ($storedBase <= 0 || abs($liveRounded - $storedBase) / max($storedBase, 0.0001) > 0.001) {
                        try {
                            db()->prepare(
                                'UPDATE market_assets SET base_price=? WHERE id=? AND (base_price IS NULL OR ABS(base_price - ?) / GREATEST(ABS(base_price), 0.0001) > 0.001)'
                            )->execute([$liveRounded, (int)($asset['id'] ?? 0), $liveRounded]);
                        } catch (Throwable $ignored) {}
                    }
                    return $liveRounded;
                }
            } catch (Throwable $exception) {}
        }
        if (isset($asset['source_price']) && (float)$asset['source_price'] > 0) {
            return round((float)$asset['source_price'], $decimals);
        }
        if (isset($asset['base_price']) && (float)$asset['base_price'] > 0) {
            return round((float)$asset['base_price'], $decimals);
        }
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

    return round($price, $decimals);
}

function currency_master_history(string $symbol, string $apiSymbol, int $count = 800): array
{
    static $cache = [];
    $clean = strtoupper(str_replace(['/', '_', '-', ' '], '', $symbol));
    if (isset($cache[$clean]) && count($cache[$clean]) >= 400) {
        return $cache[$clean];
    }

    $dir = dirname(__DIR__) . '/storage/cache/market';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . '/currencies_master_history_' . $clean . '.json';
    $candles = [];

    if (file_exists($file)) {
        $content = (string)@file_get_contents($file);
        $decoded = json_decode($content, true);
        if (is_array($decoded) && count($decoded) >= 400) {
            $candles = $decoded;
        }
    }

    if (empty($candles)) {
        $candles = fetch_binance_klines($apiSymbol ?: ($clean . 'USDT'), 60, max(800, $count));
        if (!empty($candles) && count($candles) >= 100) {
            @file_put_contents($file, json_encode($candles, JSON_PRETTY_PRINT));
        }
    }

    $cache[$clean] = $candles;
    return $candles;
}

function market_candles(array $asset, int $intervalSeconds = 5, int $count = 800, ?int $endMs = null, ?int $userId = null): array
{
    $intervalSeconds = max(1, min(86400, $intervalSeconds));
    $count = max(400, min(3000, $count));
    $now = (int)floor(microtime(true) * 1000);
    if ($endMs !== null && ($now - $endMs) < 600000 && !isset($_GET['before'])) {
        $endMs = $now;
    }
    $endMs = $endMs ?? $now;
    $decimals = max(0, min(8, (int)$asset['decimal_places']));
    $source = $asset['price_source'] ?? 'internal';
    $apiSymbol = trim((string)($asset['api_symbol'] ?? $asset['symbol'] ?? ''));
    $intervalMs = max(1000, $intervalSeconds * 1000);
    $minStep = pow(10, -$decimals);
    $volatility = max(0.0001, (float)($asset['volatility'] ?? 0.002));
    $seed = (int)($asset['market_seed'] ?? 100);

    $effectiveUserId = $userId;
    if ($effectiveUserId === null) {
        $effectiveUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($effectiveUserId <= 0 && function_exists('current_user')) {
            try {
                $u = current_user();
                $effectiveUserId = $u ? (int)($u['id'] ?? 0) : 0;
            } catch (Throwable $ignored) {
                $effectiveUserId = 0;
            }
        }
    }

    $isCurrency = market_is_currency($asset) || strtolower((string)($asset['category'] ?? '')) === 'currencies';

    if ($isCurrency) {
        $masterKlines = currency_master_history((string)($asset['symbol'] ?? 'EURUSD'), $apiSymbol, 800);
        if (!empty($masterKlines)) {
            $targetEndBucket = (int)(floor($endMs / $intervalMs) * $intervalMs);
            $targetStartBucket = $targetEndBucket - (($count - 1) * $intervalMs);

            $masterCount = count($masterKlines);
            $lastMasterKline = end($masterKlines);
            $masterLastClose = (float)($lastMasterKline['close'] ?? 1.1370);

            $livePrice = market_price_at($asset, $endMs, true, $effectiveUserId);
            if ($livePrice <= 0) {
                $livePrice = (float)($asset['base_price'] ?? $masterLastClose);
            }

            // Offset alignment so the latest historical candle close connects seamlessly to the live price
            $priceShift = $livePrice - $masterLastClose;

            $candles = [];
            $targetPrices = [];
            for ($i = 0; $i < $count; $i++) {
                $klineIdx = ($masterCount - $count) + $i;
                if ($klineIdx < 0) {
                    $klineIdx = ($i % $masterCount);
                } elseif ($klineIdx >= $masterCount) {
                    $klineIdx = $masterCount - 1;
                }
                $targetPrices[] = (float)$masterKlines[$klineIdx]['close'] + $priceShift;
            }

            $firstTarget = $targetPrices[0] ?? $livePrice;
            $prevClose = round($firstTarget, $decimals);
            $idx = 0;

            for ($t = $targetStartBucket; $t <= $targetEndBucket; $t += $intervalMs, $idx++) {
                $rawTarget = $targetPrices[$idx] ?? $livePrice;
                $isLastCandle = ($t + $intervalMs) > $endMs || $idx === ($count - 1);

                $cOpen = $prevClose;
                if ($isLastCandle) {
                    $cClose = round($livePrice, $decimals);
                } else {
                    $macroDiff = $rawTarget - $cOpen;
                    // Compact, natural 5-decimal body movement
                    $subMicro = sin($idx * 0.38 + 1.2) * ($minStep * 1.8) + cos($idx * 0.74) * ($minStep * 1.1);
                    $cClose = round($cOpen + ($macroDiff * 0.35) + $subMicro, $decimals);
                }

                $body = abs($cClose - $cOpen);

                // Small, elegant, subtle TradingView wicks (0.1 to 0.35 pips max)
                $wickNoiseH = abs(sin($idx * 0.83 + 2.1));
                $wickNoiseL = abs(cos($idx * 0.91 + 1.5));
                $wickH = round(max($minStep * 0.6, min($minStep * 3.0, $body * 0.22 + $wickNoiseH * ($minStep * 1.4))), $decimals);
                $wickL = round(max($minStep * 0.6, min($minStep * 3.0, $body * 0.22 + $wickNoiseL * ($minStep * 1.4))), $decimals);

                $cHigh = round(max($cOpen, $cClose) + $wickH, $decimals);
                $cLow  = round(max($minStep, min($cOpen, $cClose) - $wickL), $decimals);

                $prevClose = $cClose;
                $isComplete = ($t + $intervalMs) <= $endMs;

                $p1 = $cClose >= $cOpen ? $cLow : $cHigh;
                $p2 = $cClose >= $cOpen ? $cHigh : $cLow;

                $ticks = [
                    [$t, $cOpen],
                    [(int)($t + $intervalMs * 0.33), $p1],
                    [(int)($t + $intervalMs * 0.67), $p2],
                    [(int)min($t + $intervalMs - 1, $endMs), $cClose],
                ];

                $candles[] = [
                    'time' => $t,
                    'open' => $cOpen,
                    'high' => $cHigh,
                    'low' => $cLow,
                    'close' => $cClose,
                    'lastTickTime' => (int)min($t + $intervalMs - 1, $endMs),
                    'complete' => $isComplete,
                    'ticks' => $ticks,
                ];
            }

            return $candles;
        }
    }

    $realKlines = [];

    if ($source === 'binance' && $apiSymbol !== '') {
        try {
            if ($intervalSeconds <= 120) $baseSec = 60;
            elseif ($intervalSeconds <= 180) $baseSec = 180;
            elseif ($intervalSeconds <= 600) $baseSec = 300;
            elseif ($intervalSeconds <= 900) $baseSec = 900;
            elseif ($intervalSeconds <= 1800) $baseSec = 1800;
            elseif ($intervalSeconds <= 3600) $baseSec = 3600;
            elseif ($intervalSeconds <= 14400) $baseSec = 14400;
            else $baseSec = 86400;

            $klinesPerCandle = max(1, (int)ceil($intervalSeconds / $baseSec));
            $neededBinanceKlines = max(10, min(10000, $count * $klinesPerCandle));
            $realKlines = fetch_binance_klines($apiSymbol, $intervalSeconds, $neededBinanceKlines, $endMs);
        } catch (Throwable $e) {
            error_log('Failed to fetch Binance klines for ' . $apiSymbol . ': ' . $e->getMessage());
        }
    } elseif ($isCurrency && function_exists('fetch_yahoo_forex_candles')) {
        try {
            $realKlines = fetch_yahoo_forex_candles((string)($asset['symbol'] ?? 'EURUSD'), $intervalSeconds, $count, $endMs);
        } catch (Throwable $e) {
            error_log('Failed to fetch Yahoo Forex candles for ' . $asset['symbol'] . ': ' . $e->getMessage());
        }
    }

    $twelvedataKey = (string)env('TWELVEDATA_API_KEY', '');
    if ($source === 'api' && empty($realKlines) && $isCurrency && $twelvedataKey !== '' && $apiSymbol !== '') {
        try {
            if ($intervalSeconds <= 180) $tdBaseSec = 60;
            elseif ($intervalSeconds <= 600) $tdBaseSec = 300;
            elseif ($intervalSeconds <= 900) $tdBaseSec = 900;
            elseif ($intervalSeconds <= 1800) $tdBaseSec = 1800;
            elseif ($intervalSeconds <= 3600) $tdBaseSec = 3600;
            elseif ($intervalSeconds <= 14400) $tdBaseSec = 14400;
            else $tdBaseSec = 86400;

            $klinesPerCandle = max(1, (int)ceil($intervalSeconds / $tdBaseSec));
            $neededCount = max(20, min(5000, $count * $klinesPerCandle));
            $realKlines = fetch_twelvedata_candles($apiSymbol, $intervalSeconds, $neededCount, $endMs, $twelvedataKey);
        } catch (Throwable $e) {
            error_log('Failed to fetch Twelve Data candles for ' . $apiSymbol . ': ' . $e->getMessage());
        }
    }

    if (!empty($realKlines)) {
        // Build a minute lookup map for real klines indexed by openTime (ms)
        $bMap = [];
        foreach ($realKlines as $k) {
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

        // Initial prevClose matching targetStartBucket
        $startKey = null;
        if (isset($bMap[$targetStartBucket])) {
            $startKey = $targetStartBucket;
        } else {
            $floorMin = (int)(floor($targetStartBucket / 60000) * 60000);
            if (isset($bMap[$floorMin])) {
                $startKey = $floorMin;
            } else {
                foreach ($sortedTimes as $st) {
                    if ($st <= $targetStartBucket) {
                        $startKey = $st;
                    } else {
                        break;
                    }
                }
            }
        }

        $prevClose = 0.0;
        if ($startKey !== null && isset($bMap[$startKey])) {
            $prevClose = (float)$bMap[$startKey]['open'];
        }
        if ($prevClose <= 0) {
            $prevClose = market_price_at($asset, $targetStartBucket, true);
        }

        $candles = [];
        for ($t = $targetStartBucket; $t <= $targetEndBucket; $t += $intervalMs) {
            $sec = (int)($t / 1000);
            $cOpen = $prevClose;
            $hasRealKline = false;

            if ($intervalSeconds >= 60) {
                // Standard 1m+ candle: aggregate all klines falling into [$t, $t + $intervalMs)
                $matchingKlines = [];
                foreach ($sortedTimes as $st) {
                    if ($st >= $t && $st < ($t + $intervalMs)) {
                        $matchingKlines[] = $bMap[$st];
                    } elseif ($st >= ($t + $intervalMs)) {
                        break;
                    }
                }

                if (!empty($matchingKlines)) {
                    $hasRealKline = true;
                    if ($t === $targetStartBucket || $prevClose <= 0) {
                        $cOpen = (float)$matchingKlines[0]['open'];
                    } else {
                        $cOpen = $prevClose;
                    }
                    $targetClose = (float)$matchingKlines[count($matchingKlines) - 1]['close'];
                    $targetHigh  = (float)$matchingKlines[0]['high'];
                    $targetLow   = (float)$matchingKlines[0]['low'];
                    foreach ($matchingKlines as $mk) {
                        if ((float)$mk['high'] > $targetHigh) $targetHigh = (float)$mk['high'];
                        if ((float)$mk['low'] < $targetLow)   $targetLow  = (float)$mk['low'];
                    }

                    // Enrich candle if flat (zero-wick or zero-body)
                    $noiseClose = market_noise($seed + 41, $sec);
                    $noiseWickH = abs(market_noise($seed + 73, $sec));
                    $noiseWickL = abs(market_noise($seed + 97, $sec));

                    $macroDelta = $targetClose - $cOpen;
                    $microNoise = ($noiseClose * $minStep * ($isCurrency ? 2.5 : 1.5));
                    $cCloseRaw = $cOpen + ($macroDelta * 0.75) + $microNoise;

                    $minBody = $minStep * ($isCurrency ? 0.8 : 0.8);
                    if (abs($cCloseRaw - $cOpen) < $minBody) {
                        $cCloseRaw = ($noiseClose >= 0) ? ($cOpen + $minBody) : ($cOpen - $minBody);
                    }
                    $cClose = round($cCloseRaw, $decimals);

                    $bodySpan = abs($cClose - $cOpen);
                    $baseWick = max($minStep * 0.8, min($minStep * 2.5, $bodySpan * 0.25 + ($minStep * 0.8)));
                    $wickUp = $baseWick * (0.3 + $noiseWickH * 0.5);
                    $wickDown = $baseWick * (0.3 + $noiseWickL * 0.5);

                    // Clamp wicks tightly so they are never large outlier spikes
                    $maxAllowedHigh = max($cOpen, $cClose, $targetHigh) + $wickUp;
                    $minAllowedLow  = min($cOpen, $cClose, $targetLow) - $wickDown;
                    if ($isCurrency) {
                        $maxWickLimit = max($minStep * 2.2, $bodySpan * 1.5 + ($minStep * 1.8));
                        $maxAllowedHigh = min($maxAllowedHigh, max($cOpen, $cClose) + $maxWickLimit);
                        $minAllowedLow  = max($minAllowedLow, min($cOpen, $cClose) - $maxWickLimit);
                    }

                    $cHigh = round($maxAllowedHigh, $decimals);
                    $cLow  = round(max($minStep, $minAllowedLow), $decimals);
                } else {
                    // Bucket missing: continuous organic candle
                    $noiseVal = market_noise($seed, $sec);
                    $cClose = round(max($minStep * 10.0, $cOpen * (1.0 + $noiseVal * $volatility * 0.08)), $decimals);
                    $minWick = max($minStep * 1.5, $cOpen * $volatility * 0.015);
                    $cHigh  = round(max($cOpen, $cClose) + $minWick * 0.25, $decimals);
                    $cLow   = round(max($minStep, min($cOpen, $cClose) - $minWick * 0.25), $decimals);
                }
            } else {
                // Sub-minute candle (5s, 10s, 15s, 30s) - TradingView compact microstructure
                $floorMin = (int)(floor($t / 60000) * 60000);
                $bKey = isset($bMap[$floorMin]) ? $floorMin : null;
                if ($bKey === null) {
                    foreach ($sortedTimes as $st) {
                        if ($st <= $t) $bKey = $st;
                        else break;
                    }
                }

                $hasRealKline = ($bKey !== null && isset($bMap[$bKey]));
                if ($hasRealKline) {
                    $bKline = $bMap[$bKey];
                    $bOpen  = (float)$bKline['open'];
                    $bClose = (float)$bKline['close'];
                    $bHigh  = (float)$bKline['high'];
                    $bLow   = (float)$bKline['low'];

                    if ($t === $targetStartBucket || $prevClose <= 0) {
                        $cOpen = $bOpen;
                    } else {
                        $cOpen = $prevClose;
                    }

                    $secInMin = (int)(($t - $floorMin) / 1000) % 60;
                    $progress = min(1.0, max(0.0, ($secInMin + $intervalSeconds) / 60.0));

                    $microNoise = market_noise($seed + 19, $sec);
                    $wickNoiseH = abs(market_noise($seed + 43, $sec));
                    $wickNoiseL = abs(market_noise($seed + 67, $sec));

                    $subWave = sin($sec / 3.5) * ($minStep * 1.3) + cos($sec / 7.2) * ($minStep * 0.9) + ($microNoise * $minStep * 0.7);
                    $targetClose = $bOpen + (($bClose - $bOpen) * $progress) + $subWave;

                    // Ensure visible non-zero candle body with compact size
                    $minBody = $minStep * ($isCurrency ? 0.5 : 0.6);
                    if (abs($targetClose - $cOpen) < $minBody) {
                        $targetClose = ($microNoise >= 0) ? ($cOpen + $minBody) : ($cOpen - $minBody);
                    }
                    $cClose = round($targetClose, $decimals);

                    $bodySpan = abs($cClose - $cOpen);
                    $baseWick = max($minStep * 0.6, min($minStep * 1.8, $bodySpan * 0.20 + ($minStep * 0.6)));
                    $wickUp = $baseWick * (0.25 + $wickNoiseH * 0.45);
                    $wickDown = $baseWick * (0.25 + $wickNoiseL * 0.45);

                    $cHigh = round(max($cOpen, $cClose) + $wickUp, $decimals);
                    $cLow  = round(max($minStep, min($cOpen, $cClose) - $wickDown), $decimals);
                } else {
                    $noiseVal = market_noise($seed, $sec);
                    $cClose = round(max($minStep * 10.0, $cOpen * (1.0 + $noiseVal * $volatility * 0.08)), $decimals);
                    $minWick = max($minStep * 1.5, $cOpen * $volatility * 0.015);
                    $cHigh  = round(max($cOpen, $cClose) + $minWick * 0.25, $decimals);
                    $cLow   = round(max($minStep, min($cOpen, $cClose) - $minWick * 0.25), $decimals);
                }
            }

            // Guarantee minimum wick span so no candle is a 1-pixel flat line
            $minSpan = max($minStep * 1.5, $cOpen * ($isCurrency ? 0.000015 : ($volatility * 0.02)));
            if (($cHigh - $cLow) < $minSpan) {
                $cHigh = round(max($cOpen, $cClose) + $minSpan * 0.5, $decimals);
                $cLow  = round(max($minStep, min($cOpen, $cClose) - $minSpan * 0.5), $decimals);
            }

            $cEnd = min($t + $intervalMs - 1, $endMs);
            $cBiasStart = get_active_trade_price_bias($asset, (int)$t, $effectiveUserId, false, $cOpen);
            $cBiasEnd   = get_active_trade_price_bias($asset, (int)$cEnd, $effectiveUserId, false, $cClose);

            $cOpenR  = round($cOpen + $cBiasStart, $decimals);
            $cCloseR = round($cClose + $cBiasEnd, $decimals);

            $cHighR  = round(max($cHigh + max($cBiasStart, $cBiasEnd), $cOpenR, $cCloseR), $decimals);
            $cLowR   = round(max($minStep, min($cLow + min($cBiasStart, $cBiasEnd), $cOpenR, $cCloseR)), $decimals);

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

        $minBody = max($minStep * 3.0, $open * 0.00004);
        // Ensure visible candle body with directional movement (except last candle which must match targetPrice)
        if ($i < $count - 1 && abs($close - $open) < $minBody) {
            $noiseDir = market_noise($seed + 55, (int)($time / 1000));
            $close = round($noiseDir >= 0 ? $open + $minBody : max($minStep, $open - $minBody), $decimals);
        }

        // Elegant, natural candle wicks: distinct upper and lower shadows, crisp and realistic
        $bodyHeight = abs($close - $open);
        $noiseUpper = abs(market_noise($seed + 99, (int)($time / 1000)));
        $noiseLower = abs(market_noise($seed + 101, (int)($time / 1000)));

        $wickUpper = max(0.0, min($bodyHeight * 0.25, $bodyHeight * 0.10 * $noiseUpper + ($open * $vol * 0.01 * $noiseUpper)));
        $wickLower = max(0.0, min($bodyHeight * 0.25, $bodyHeight * 0.10 * $noiseLower + ($open * $vol * 0.01 * $noiseLower)));

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
    $source = $asset['price_source'] ?? 'internal';
    $symbol = trim((string)($asset['api_symbol'] ?: $asset['symbol']));
    if ($symbol !== '' && ($source === 'api' || $source === 'binance' || market_is_currency($asset))) {
        try {
            $livePrice = fetch_market_price($symbol);
            if (is_numeric($livePrice) && $livePrice > 0) {
                return (float)$livePrice;
            }
        } catch (Throwable $exception) {}
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
    $displayName = (string)($asset['display_name'] ?? '');

    $isOtc = (stripos($symbol, 'OTC') !== false || stripos($displayName, 'OTC') !== false || $category === 'otc' || stripos($category, 'otc') !== false);
    $isCrypto = (
        stripos($category, 'crypto') !== false ||
        stripos($symbol, 'BTC') !== false ||
        stripos($symbol, 'ETH') !== false ||
        stripos($symbol, 'SOL') !== false ||
        stripos($symbol, 'DOGE') !== false ||
        stripos($symbol, 'XRP') !== false ||
        stripos($symbol, 'BNB') !== false ||
        stripos($symbol, 'BITCOIN') !== false ||
        stripos($displayName, 'Bitcoin') !== false ||
        stripos($displayName, 'Crypto') !== false
    );

    $isAlwaysOpen = (
        $isOtc ||
        $isCrypto ||
        ($asset['price_source'] ?? '') === 'binance' ||
        stripos($symbol, 'XAU') !== false ||
        stripos($symbol, 'XAUT') !== false ||
        stripos($symbol, 'PAXG') !== false ||
        stripos($displayName, 'Gold') !== false
    );

    // Crypto, Binance Commodities (Gold/Tether Gold) and OTC assets trade 24/7 (Always Open)
    if ($isAlwaysOpen) {
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

    // 1. Check if manually disabled/closed by admin (for non-crypto assets)
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

function market_snapshot(string $symbol, int $intervalSeconds = 5, int $count = 800, bool $includeCandles = true, ?int $endMs = null, ?int $userId = null): array
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

    $effectiveUserId = $userId;
    if ($effectiveUserId === null) {
        $effectiveUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($effectiveUserId <= 0 && function_exists('current_user')) {
            try {
                $u = current_user();
                $effectiveUserId = $u ? (int)($u['id'] ?? 0) : 0;
            } catch (Throwable $ignored) {
                $effectiveUserId = 0;
            }
        }
    }

    $nowMs = (int)floor(microtime(true) * 1000);
    $status = market_status($asset, $nowMs);
    $source = $asset['price_source'] ?? 'internal';

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

    if ($status['isOpen']) {
        if ($endMs !== null && ($nowMs - $endMs) < 600000 && !isset($_GET['before'])) {
            $endMs = null;
        }
        $targetEndMs = ($endMs !== null && $endMs > 0) ? $endMs : $nowMs;
    } else {
        $targetEndMs = $status['closedAt'];
    }
    $targetPrice = $status['isOpen'] ? market_price_at($asset, $nowMs, false, $effectiveUserId) : (float)$status['closedPrice'];
    $assetWithTarget = array_merge($asset, [
        'closed_price' => $status['closedPrice'],
        'target_price' => $targetPrice,
        'price' => $targetPrice,
    ]);

    $candles = [];
    if ($includeCandles) {
        try {
            $candles = market_candles($assetWithTarget, $intervalSeconds, $count, $targetEndMs, $effectiveUserId);
        } catch (Throwable $e) {
            error_log('Failed to generate candles for ' . $symbol . ': ' . $e->getMessage());
        }
    }

    // Reconcile targetPrice with candle data: the last candle's close is the most
    // reliable price because it comes directly from the real-time data provider
    // (TwelveData time_series or Binance klines).
    if ($status['isOpen'] && !empty($candles)) {
        $lastCandleClose = (float)$candles[count($candles) - 1]['close'];
        if ($lastCandleClose > 0) {
            $divergence = abs($targetPrice - $lastCandleClose) / max($lastCandleClose, 0.0001);
            if ($divergence > 0.0001 || $source === 'api' || $source === 'binance') {
                $targetPrice = $lastCandleClose;
            }
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
        $lastIdx = count($candles) - 1;
        $intervalMs = max(1000, $intervalSeconds * 1000);
        $activeBucket = (int)(floor($targetEndMs / $intervalMs) * $intervalMs);
        $candleClose = (float)$candles[$lastIdx]['close'];

        if ($candleClose > 0 && ($source === 'api' || $source === 'binance' || abs($targetPrice - $candleClose) / max($candleClose, 0.0001) > 0.0005)) {
            $targetPrice = $candleClose;
        }

        if (!empty($candles[$lastIdx]['complete']) && $activeBucket > $candles[$lastIdx]['time']) {
            // Last candle from generator is historical & complete; append active forming candle for current bucket
            $cOpen  = $candleClose;
            $cClose = $targetPrice;
            $cHigh  = max($cOpen, $cClose);
            $cLow   = min($cOpen, $cClose);
            $cEnd   = min($activeBucket + $intervalMs - 1, $targetEndMs);

            $candles[] = [
                'time'        => $activeBucket,
                'open'        => $cOpen,
                'high'        => $cHigh,
                'low'         => $cLow,
                'close'       => $cClose,
                'lastTickTime'=> $cEnd,
                'complete'    => false,
                'ticks'       => [
                    [$activeBucket, $cOpen],
                    [$cEnd, $cClose],
                ],
            ];
        } else {
            // Update active incomplete forming candle
            $candles[$lastIdx]['close'] = $targetPrice;
            $candles[$lastIdx]['high']  = max((float)$candles[$lastIdx]['high'], $targetPrice);
            $candles[$lastIdx]['low']   = min((float)$candles[$lastIdx]['low'], $targetPrice);
            if (!empty($candles[$lastIdx]['ticks']) && is_array($candles[$lastIdx]['ticks']) && count($candles[$lastIdx]['ticks']) > 0) {
                $tCount = count($candles[$lastIdx]['ticks']);
                $candles[$lastIdx]['ticks'][$tCount - 1][1] = $targetPrice;
            }
        }
        $assetPayload['price'] = $targetPrice;
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
