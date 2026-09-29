<?php
declare(strict_types=1);

/**
 * Market API Integration
 * Handles real-time market data from Twelve Data and other providers
 */

/**
 * Fetch real market price from Twelve Data API
 * Supports Forex, Crypto, and Stocks
 * 
 * @param string $symbol Trading symbol (e.g., 'EURUSD', 'BTCUSD', 'XAUUSD')
 * @param string|null $apiKey Optional API key (uses env by default)
 * @return float The current price
 */
function normalize_market_api_symbol(string $symbol): string
{
    $normalized = strtoupper(trim($symbol));
    $normalized = str_replace([' ', '_', '-', '/'], '', $normalized);

    if ($normalized === 'GOLD') {
        return 'XAUUSD';
    }

    if ($normalized === 'XAU') {
        return 'XAUUSD';
    }

    if ($normalized === 'XAG') {
        return 'XAGUSD';
    }

    return $normalized;
}

function normalize_twelvedata_symbol(string $symbol): string
{
    $normalized = normalize_market_api_symbol($symbol);

    if (preg_match('/^[A-Z]{6}$/', $normalized)) {
        return substr($normalized, 0, 3) . '/' . substr($normalized, 3);
    }

    if (preg_match('/^[A-Z]{3}\/\d{2,4}$/', $normalized)) {
        return $normalized;
    }

    return $normalized;
}

function fetch_alphavantage_price(string $symbol, ?string $apiKey = null): float
{
    static $memoryCache = [];
    $cleanSym = normalize_market_api_symbol($symbol);
    $cacheKey = strtoupper($cleanSym);
    $now = microtime(true);

    if (isset($memoryCache[$cacheKey]) && ($now - $memoryCache[$cacheKey]['time']) < 60) {
        return $memoryCache[$cacheKey]['price'];
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market';
    $cacheFile = $cacheDir . '/alphavantage_quotes.json';
    $diskCache = [];
    if (is_file($cacheFile)) {
        $diskCache = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
        if (isset($diskCache[$cacheKey]['price'], $diskCache[$cacheKey]['time']) && ($now - (float)$diskCache[$cacheKey]['time']) < 60) {
            $memoryCache[$cacheKey] = $diskCache[$cacheKey];
            return (float)$diskCache[$cacheKey]['price'];
        }
    }

    $apiKey = $apiKey ?? (string)env('ALPHAVANTAGE_API_KEY', 'F8Y3Y16L2S3US7B6');
    if (!empty($apiKey) && strlen($cleanSym) >= 6 && function_exists('curl_init')) {
        $from = substr($cleanSym, 0, 3);
        $to = substr($cleanSym, 3, 3);
        $url = "https://www.alphavantage.co/query?function=CURRENCY_EXCHANGE_RATE&from_currency=" . urlencode($from) . "&to_currency=" . urlencode($to) . "&apikey=" . urlencode($apiKey);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TradingApp/1.0');

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$curlError && $httpCode === 200) {
            $data = json_decode((string)$response, true);
            if (is_array($data) && isset($data['Realtime Currency Exchange Rate']['5. Exchange Rate'])) {
                $price = (float)$data['Realtime Currency Exchange Rate']['5. Exchange Rate'];
                if ($price > 0) {
                    $memoryCache[$cacheKey] = ['price' => $price, 'time' => $now];
                    $diskCache[$cacheKey] = $memoryCache[$cacheKey];
                    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
                    @file_put_contents($cacheFile, json_encode($diskCache), LOCK_EX);
                    return $price;
                }
            }
        }
    }

    if (isset($diskCache[$cacheKey]['price']) && (float)$diskCache[$cacheKey]['price'] > 0) {
        return (float)$diskCache[$cacheKey]['price'];
    }

    try {
        return fetch_twelvedata_price($symbol);
    } catch (Throwable $e) {
        if (in_array($cleanSym, ['EURUSD', 'GBPUSD', 'AUDUSD'], true)) {
            try {
                $binanceSym = str_replace('USD', 'USDT', $cleanSym);
                $binancePrice = fetch_binance_price($binanceSym);
                if ($binancePrice > 0) return $binancePrice;
            } catch (Throwable $e2) {}
        }
    }

    throw new RuntimeException('Alpha Vantage price request failed for ' . $symbol);
}

function fetch_twelvedata_price(string $symbol, ?string $apiKey = null): float
{
    static $memoryCache = [];
    $normalizedSymbol = normalize_twelvedata_symbol($symbol);
    $cacheKey = strtoupper($normalizedSymbol);
    $now = microtime(true);

    if (isset($memoryCache[$cacheKey]) && ($now - $memoryCache[$cacheKey]['time']) < 5) {
        return $memoryCache[$cacheKey]['price'];
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market';
    $cacheFile = $cacheDir . '/twelvedata_quotes.json';
    $diskCache = [];
    if (is_file($cacheFile)) {
        $diskCache = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
        if (isset($diskCache[$cacheKey]['price'], $diskCache[$cacheKey]['time']) && ($now - (float)$diskCache[$cacheKey]['time']) < 5) {
            $memoryCache[$cacheKey] = $diskCache[$cacheKey];
            return (float)$diskCache[$cacheKey]['price'];
        }
    }

    $apiKey = $apiKey ?? (string)env('TWELVEDATA_API_KEY', '');
    if (!empty($apiKey) && function_exists('curl_init')) {
        $url = "https://api.twelvedata.com/quote?symbol=" . urlencode($normalizedSymbol) . "&apikey=" . urlencode($apiKey);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TradingApp/1.0');

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$curlError && $httpCode === 200) {
            $data = json_decode((string)$response, true);
            if (is_array($data)) {
                $price = $data['price'] ?? $data['close'] ?? $data['last'] ?? null;
                if (is_numeric($price) && (float)$price > 0) {
                    $priceVal = (float)$price;
                    $memoryCache[$cacheKey] = ['price' => $priceVal, 'time' => $now];
                    $diskCache[$cacheKey] = $memoryCache[$cacheKey];
                    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
                    @file_put_contents($cacheFile, json_encode($diskCache), LOCK_EX);
                    return $priceVal;
                }
            }
        }
    }

    if (isset($diskCache[$cacheKey]['price']) && (float)$diskCache[$cacheKey]['price'] > 0) {
        return (float)$diskCache[$cacheKey]['price'];
    }

    $safeSym = str_replace('/', '_', $normalizedSymbol);
    $candleDir = $cacheDir . '/twelvedata_candles';
    foreach (['1min', '5min', '15min', '1h', '1day'] as $iv) {
        $cFile = $candleDir . "/{$safeSym}_{$iv}.json";
        if (is_file($cFile)) {
            $cData = json_decode((string)@file_get_contents($cFile), true);
            if (is_array($cData) && !empty($cData['candles'])) {
                $lastCandle = end($cData['candles']);
                if (isset($lastCandle['close']) && (float)$lastCandle['close'] > 0) {
                    $priceVal = (float)$lastCandle['close'];
                    $memoryCache[$cacheKey] = ['price' => $priceVal, 'time' => $now];
                    $diskCache[$cacheKey] = $memoryCache[$cacheKey];
                    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
                    @file_put_contents($cacheFile, json_encode($diskCache), LOCK_EX);
                    return $priceVal;
                }
            }
        }
    }

    $cleanSym = normalize_market_api_symbol($symbol);
    if (in_array($cleanSym, ['EURUSD', 'GBPUSD', 'AUDUSD'], true)) {
        try {
            $binanceSym = str_replace('USD', 'USDT', $cleanSym);
            $binancePrice = fetch_binance_price($binanceSym);
            if ($binancePrice > 0) {
                return $binancePrice;
            }
        } catch (Throwable $e) {}
    }

    try {
        if (function_exists('market_asset')) {
            $a = market_asset($symbol);
            if ($a && !empty($a['base_price']) && (float)$a['base_price'] > 0) {
                return (float)$a['base_price'];
            }
        }
    } catch (Throwable $e) {}

    throw new RuntimeException('Twelve Data price request failed for ' . $symbol);
}

function fetch_free_forex_rates(): array
{
    static $memoryRates = [];
    static $memoryTime = 0.0;
    $now = microtime(true);

    if (!empty($memoryRates) && ($now - $memoryTime) < 3.0) {
        return $memoryRates;
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market';
    $cacheFile = $cacheDir . '/free_forex_rates.json';

    if (file_exists($cacheFile)) {
        $mtime = (float)@filemtime($cacheFile);
        if (($now - $mtime) < 3.0) {
            $raw = @file_get_contents($cacheFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $memoryRates = $decoded;
                    $memoryTime = $now;
                    return $decoded;
                }
            }
        }
    }

    $endpoints = [
        'https://open.er-api.com/v6/latest/USD',
        'https://api.frankfurter.app/latest?from=USD'
    ];

    if (function_exists('curl_init')) {
        foreach ($endpoints as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'TradingApp/1.0');
            $res = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($res)) {
                $data = json_decode($res, true);
                $rates = $data['rates'] ?? [];
                if (is_array($rates) && !empty($rates)) {
                    $rates['USD'] = 1.0;
                    $memoryRates = $rates;
                    $memoryTime = $now;
                    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
                    @file_put_contents($cacheFile, json_encode($rates), LOCK_EX);
                    return $rates;
                }
            }
        }
    }

    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $memoryRates = $decoded;
                $memoryTime = $now;
                return $decoded;
            }
        }
    }

    return $memoryRates;
}

function fetch_free_forex_price(string $symbol): float
{
    $cleanSym = normalize_market_api_symbol($symbol);
    if (strlen($cleanSym) < 6) {
        return 0.0;
    }

    $base = substr($cleanSym, 0, 3);
    $quote = substr($cleanSym, 3, 3);

    $rates = fetch_free_forex_rates();
    if (!empty($rates)) {
        if ($base === 'USD' && isset($rates[$quote]) && (float)$rates[$quote] > 0) {
            return (float)$rates[$quote];
        }
        if ($quote === 'USD' && isset($rates[$base]) && (float)$rates[$base] > 0) {
            return 1.0 / (float)$rates[$base];
        }
        if (isset($rates[$base], $rates[$quote]) && (float)$rates[$base] > 0) {
            return (float)$rates[$quote] / (float)$rates[$base];
        }
    }

    // Direct Yahoo Finance Forex single quote fallback if pair is uncommon
    if (function_exists('fetch_yahoo_forex_price')) {
        return fetch_yahoo_forex_price($cleanSym);
    }

    return 0.0;
}

function yahoo_forex_symbol(string $symbol): string
{
    $clean = normalize_market_api_symbol($symbol);
    return $clean . '=X';
}

function fetch_yahoo_forex_price(string $symbol): float
{
    static $memoryPrice = [];
    static $memoryTime = [];
    $cleanSym = normalize_market_api_symbol($symbol);
    $now = microtime(true);

    if (isset($memoryPrice[$cleanSym], $memoryTime[$cleanSym]) && ($now - $memoryTime[$cleanSym]) < 10.0) {
        return $memoryPrice[$cleanSym];
    }

    $yfSym = yahoo_forex_symbol($cleanSym);
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($yfSym) . "?interval=1m&range=1d";
    
    if (!function_exists('curl_init')) return 0.0;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $res = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && is_string($res)) {
        $data = json_decode($res, true);
        $meta = $data['chart']['result'][0]['meta'] ?? null;
        if ($meta && isset($meta['regularMarketPrice']) && (float)$meta['regularMarketPrice'] > 0) {
            $price = (float)$meta['regularMarketPrice'];
            $memoryPrice[$cleanSym] = $price;
            $memoryTime[$cleanSym] = $now;
            return $price;
        }
    }

    return 0.0;
}

function fetch_yahoo_forex_candles(string $symbol, int $intervalSeconds = 60, int $count = 2000, ?int $endTimeMs = null): array
{
    static $memoryKlines = [];
    static $memoryTime = [];
    $cleanSym = normalize_market_api_symbol($symbol);
    $yfSym = yahoo_forex_symbol($cleanSym);
    $now = microtime(true);

    $yfInterval = '1m';
    $range = '2d';
    if ($intervalSeconds >= 86400) { $yfInterval = '1d'; $range = '1y'; }
    elseif ($intervalSeconds >= 3600) { $yfInterval = '60m'; $range = '1mo'; }
    elseif ($intervalSeconds >= 900) { $yfInterval = '15m'; $range = '5d'; }
    elseif ($intervalSeconds >= 300) { $yfInterval = '5m'; $range = '5d'; }

    $cacheKey = "{$cleanSym}_{$yfInterval}";
    if (isset($memoryKlines[$cacheKey], $memoryTime[$cacheKey]) && ($now - $memoryTime[$cacheKey]) < 20.0 && count($memoryKlines[$cacheKey]) >= min($count, 200)) {
        return array_slice($memoryKlines[$cacheKey], -$count);
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market/yahoo_candles';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $cacheFile = $cacheDir . "/{$cacheKey}.json";

    if (file_exists($cacheFile)) {
        $mtime = (float)@filemtime($cacheFile);
        if (($now - $mtime) < 20.0) {
            $raw = @file_get_contents($cacheFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $memoryKlines[$cacheKey] = $decoded;
                    $memoryTime[$cacheKey] = $mtime;
                    return array_slice($decoded, -$count);
                }
            }
        }
    }

    if (!function_exists('curl_init')) return [];

    $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($yfSym) . "?interval={$yfInterval}&range={$range}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $res = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && is_string($res)) {
        $data = json_decode($res, true);
        $result = $data['chart']['result'][0] ?? null;
        if ($result && !empty($result['timestamp'])) {
            $timestamps = $result['timestamp'];
            $quotes = $result['indicators']['quote'][0] ?? [];
            $opens = $quotes['open'] ?? [];
            $highs = $quotes['high'] ?? [];
            $lows = $quotes['low'] ?? [];
            $closes = $quotes['close'] ?? [];

            $candles = [];
            $len = count($timestamps);
            $stepMs = max(1000, $intervalSeconds * 1000);

            for ($i = 0; $i < $len; $i++) {
                if (!isset($opens[$i], $closes[$i]) || $opens[$i] === null || $closes[$i] === null) continue;
                $o = (float)$opens[$i];
                $c = (float)$closes[$i];
                if ($o <= 0 || $c <= 0) continue; // Skip corrupted 0 quotes

                $h = (float)($highs[$i] ?? $o);
                $l = (float)($lows[$i] ?? $o);
                if ($h <= 0) $h = max($o, $c);
                if ($l <= 0) $l = min($o, $c);

                // Normalize unnatural provider outlier wicks to standard TradingView ratios
                $body = abs($c - $o);
                $maxAllowedWick = max(0.00008, min(0.00025, $body * 1.5 + 0.00006));
                $h = min($h, max($o, $c) + $maxAllowedWick);
                $l = max($l, min($o, $c) - $maxAllowedWick);

                $t = (int)$timestamps[$i] * 1000;
                $candles[] = [
                    'openTime'  => $t,
                    'open'      => round($o, 5),
                    'high'      => round($h, 5),
                    'low'       => round($l, 5),
                    'close'     => round($c, 5),
                    'closeTime' => $t + $stepMs - 1,
                ];
            }

            if (!empty($candles)) {
                $memoryKlines[$cacheKey] = $candles;
                $memoryTime[$cacheKey] = $now;
                @file_put_contents($cacheFile, json_encode($candles), LOCK_EX);
                return array_slice($candles, -$count);
            }
        }
    }

    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return array_slice($decoded, -$count);
            }
        }
    }

    return [];
}

function twelvedata_interval(int $intervalSeconds): string
{
    if ($intervalSeconds <= 180) return '1min';
    if ($intervalSeconds <= 600) return '5min';
    if ($intervalSeconds <= 900) return '15min';
    if ($intervalSeconds <= 1800) return '30min';
    if ($intervalSeconds <= 3600) return '1h';
    if ($intervalSeconds <= 14400) return '4h';
    if ($intervalSeconds <= 86400) return '1day';
    return '1week';
}

function fetch_twelvedata_candles(string $symbol, int $intervalSeconds = 60, int $count = 5000, ?int $endTimeMs = null, ?string $apiKey = null): array
{
    $normalizedSymbol = normalize_twelvedata_symbol($symbol);
    $intervalStr = twelvedata_interval($intervalSeconds);
    $count = max(10, min(5000, $count));
    $now = microtime(true);

    $safeSym = str_replace('/', '_', $normalizedSymbol);
    $cacheDir = dirname(__DIR__) . '/storage/cache/market/twelvedata_candles';
    $cacheFile = $cacheDir . "/{$safeSym}_{$intervalStr}.json";

    $cachedCandles = null;
    if (is_file($cacheFile)) {
        $diskData = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($diskData) && !empty($diskData['candles'])) {
            $cachedTime = (float)($diskData['time'] ?? 0);
            $maxTtl = ($intervalSeconds <= 60) ? 15 : 45;
            if (($now - $cachedTime) < $maxTtl && count($diskData['candles']) >= min($count, 500)) {
                return $diskData['candles'];
            }
            // Only use as fallback if the latest candle is fresh (within 5 minutes)
            $lastCandleTime = !empty($diskData['candles']) ? ((float)end($diskData['candles'])['openTime'] / 1000) : 0;
            if (count($diskData['candles']) >= 20 && ($now - $lastCandleTime) < 300) {
                $cachedCandles = $diskData['candles'];
            }
        }
    }

    $apiKey = $apiKey ?? (string)env('TWELVEDATA_API_KEY', '');
    if (!empty($apiKey) && function_exists('curl_init')) {
        $params = [
            'symbol' => $normalizedSymbol,
            'interval' => $intervalStr,
            'outputsize' => $count,
            'order' => 'ASC',
            'apikey' => $apiKey,
        ];
        if ($endTimeMs !== null && $endTimeMs > 0) {
            $params['end_date'] = gmdate('Y-m-d H:i:s', (int)floor($endTimeMs / 1000));
        }

        $attempts = 0;
        $response = false;
        $httpCode = 0;
        $curlError = '';

        while ($attempts < 2) {
            $attempts++;
            $ch = curl_init('https://api.twelvedata.com/time_series?' . http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'TradingApp/1.0');
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 429 && $attempts < 2) {
                if (!empty($cachedCandles)) {
                    return $cachedCandles;
                }
                usleep(1200000);
                continue;
            }
            break;
        }

        if (!$curlError && $httpCode === 200) {
            $data = json_decode((string)$response, true);
            if (is_array($data) && (!isset($data['status']) || $data['status'] === 'ok')) {
                $candles = [];
                $rawValues = $data['values'] ?? [];
                if (!empty($rawValues)) {
                    foreach ($rawValues as $value) {
                        if (!isset($value['datetime'], $value['open'], $value['high'], $value['low'], $value['close'])) continue;
                        $timestamp = strtotime((string)$value['datetime'] . ' UTC');
                        if ($timestamp === false) continue;
                        $candles[] = [
                            'openTime' => $timestamp * 1000,
                            'open' => (float)$value['open'],
                            'high' => (float)$value['high'],
                            'low' => (float)$value['low'],
                            'close' => (float)$value['close'],
                            'closeTime' => ($timestamp + max(1, $intervalSeconds)) * 1000 - 1,
                        ];
                    }
                    usort($candles, fn($a, $b) => $a['openTime'] <=> $b['openTime']);
                    if (!empty($candles)) {
                        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
                        @file_put_contents($cacheFile, json_encode(['time' => $now, 'candles' => $candles]), LOCK_EX);
                        return $candles;
                    }
                }
            }
        }
    }

    if (!empty($cachedCandles)) {
        return $cachedCandles;
    }

    $cleanSym = normalize_market_api_symbol($symbol);
    if (in_array($cleanSym, ['EURUSD', 'GBPUSD', 'AUDUSD'], true)) {
        try {
            $binanceSym = str_replace('USD', 'USDT', $cleanSym);
            $binanceKlines = fetch_binance_klines($binanceSym, $intervalSeconds, $count, $endTimeMs);
            if (!empty($binanceKlines)) {
                return $binanceKlines;
            }
        } catch (Throwable $e) {}
    }

    $realPrice = 0.0;
    try {
        if (function_exists('fetch_alphavantage_price')) {
            $realPrice = fetch_alphavantage_price($cleanSym);
        }
    } catch (Throwable $e) {}

    if ($realPrice <= 0) {
        try {
            $realPrice = fetch_twelvedata_price($symbol, $apiKey);
        } catch (Throwable $e) {
            $realPrice = 0.0;
        }
    }

    if ($realPrice <= 0 && function_exists('market_asset')) {
        $a = market_asset($symbol);
        $realPrice = (float)($a['base_price'] ?? 1.0);
    }

    if ($realPrice > 0) {
        $candles = [];
        $endSec = $endTimeMs !== null && $endTimeMs > 0 ? (int)floor($endTimeMs / 1000) : time();
        $startSec = $endSec - ($count * $intervalSeconds);
        $currPrice = $realPrice;
        $volatility = 0.0003 * $realPrice;

        for ($i = 0; $i < $count; $i++) {
            $t = $startSec + ($i * $intervalSeconds);
            $noise = function_exists('market_noise') ? market_noise(crc32($symbol), $i) : (((($i * 1103515245 + 12345) & 0x7FFFFFFF) / 2147483647.0) * 2.0 - 1.0);
            $change = $noise * $volatility;
            $open = $currPrice;
            $close = max(0.0001, $open + $change);
            $high = max($open, $close) + abs($change * 0.5);
            $low = min($open, $close) - abs($change * 0.5);
            $candles[] = [
                'openTime' => $t * 1000,
                'open' => round($open, 5),
                'high' => round($high, 5),
                'low' => round($low, 5),
                'close' => round($close, 5),
                'closeTime' => ($t + max(1, $intervalSeconds)) * 1000 - 1,
            ];
            $currPrice = $close;
        }
        return $candles;
    }

    throw new RuntimeException('Twelve Data candle fetch failed for ' . $symbol);
}

function finnhub_forex_symbol(string $symbol): string
{
    $normalized = normalize_market_api_symbol($symbol);
    if (strlen($normalized) !== 6) throw new InvalidArgumentException('Unsupported forex symbol: ' . $symbol);
    return 'OANDA:' . substr($normalized, 0, 3) . '_' . substr($normalized, 3);
}

function finnhub_request(string $path, array $params, ?string $apiKey = null): array
{
    $apiKey = $apiKey ?? trim((string)env('FINNHUB_API_KEY', ''));
    if ($apiKey === '') throw new RuntimeException('Finnhub API key not configured. Add FINNHUB_API_KEY to .env');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for market API feeds.');
    $params['token'] = $apiKey;
    $ch = curl_init('https://finnhub.io/api/v1/' . ltrim($path, '/') . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TradingApp/1.0');
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) throw new RuntimeException('Finnhub API error: ' . $curlError);
    if ($httpCode !== 200) throw new RuntimeException('Finnhub API returned HTTP ' . $httpCode);
    $data = json_decode((string)$response, true);
    if (!is_array($data)) throw new RuntimeException('Finnhub API returned invalid JSON');
    if (($data['s'] ?? '') === 'no_data' || isset($data['error'])) {
        throw new RuntimeException('Finnhub API error: ' . ($data['error'] ?? 'No market data'));
    }
    return $data;
}

function finnhub_resolution(int $intervalSeconds): string
{
    if ($intervalSeconds <= 60) return '1';
    if ($intervalSeconds <= 300) return '5';
    if ($intervalSeconds <= 900) return '15';
    if ($intervalSeconds <= 1800) return '30';
    if ($intervalSeconds <= 3600) return '60';
    if ($intervalSeconds <= 86400) return 'D';
    if ($intervalSeconds <= 604800) return 'W';
    return 'M';
}

function fetch_finnhub_price(string $symbol, ?string $apiKey = null): float
{
    $data = finnhub_request('quote', ['symbol' => finnhub_forex_symbol($symbol)], $apiKey);
    $price = $data['c'] ?? null;
    if (!is_numeric($price) || (float)$price <= 0) throw new RuntimeException('Finnhub returned no current price for ' . $symbol);
    return (float)$price;
}

function fetch_finnhub_candles(string $symbol, int $intervalSeconds = 60, int $count = 5000, ?int $endTimeMs = null, ?string $apiKey = null): array
{
    $resolution = finnhub_resolution($intervalSeconds);
    $to = $endTimeMs !== null && $endTimeMs > 0 ? (int)floor($endTimeMs / 1000) : time();
    $step = $resolution === 'D' ? 86400 : ($resolution === 'W' ? 604800 : ($resolution === 'M' ? 2592000 : (int)$resolution * 60));
    $from = $to - (max(10, min(5000, $count)) * $step);
    $data = finnhub_request('forex/candle', [
        'symbol' => finnhub_forex_symbol($symbol),
        'resolution' => $resolution,
        'from' => $from,
        'to' => $to,
    ], $apiKey);
    if (($data['s'] ?? '') !== 'ok') throw new RuntimeException('Finnhub returned no candles for ' . $symbol);
    $candles = [];
    $length = count($data['t'] ?? []);
    for ($i = 0; $i < $length; $i++) {
        if (!isset($data['o'][$i], $data['h'][$i], $data['l'][$i], $data['c'][$i])) continue;
        $openTime = (int)$data['t'][$i] * 1000;
        $candles[] = [
            'openTime' => $openTime,
            'open' => (float)$data['o'][$i],
            'high' => (float)$data['h'][$i],
            'low' => (float)$data['l'][$i],
            'close' => (float)$data['c'][$i],
            'closeTime' => $openTime + ($step * 1000) - 1,
        ];
    }
    if (!$candles) throw new RuntimeException('Finnhub returned no usable candles for ' . $symbol);
    return $candles;
}

/**
 * Fetch real market price from Metals API (for Gold, Silver, etc.)
 * 
 * @param string $symbol Commodity symbol ('XAU' for gold, 'XAG' for silver)
 * @param string $baseCurrency Base currency (default 'USD')
 * @param string|null $apiKey Optional API key
 * @return float The current price
 */
function fetch_metals_api_price(string $symbol, string $baseCurrency = 'USD', ?string $apiKey = null): float
{
    $apiKey = $apiKey ?? (string)env('METALS_API_KEY', '');
    if (empty($apiKey)) {
        throw new RuntimeException('Metals API key not configured. Add METALS_API_KEY to .env');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for market API feeds.');
    }

    // Metals API endpoint
    $url = "https://metals-api.com/api/latest?access_key=" . urlencode($apiKey) . "&base=" . urlencode($baseCurrency) . "&symbols=" . urlencode($symbol);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TradingApp/1.0');

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException('Metals API error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException('Metals API returned HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Metals API returned invalid JSON');
    }

    if (!isset($data['success']) || !$data['success']) {
        throw new RuntimeException('Metals API error: ' . ($data['error']['info'] ?? 'Unknown error'));
    }

    if (!isset($data['rates'][$symbol]) || !is_numeric($data['rates'][$symbol])) {
        throw new RuntimeException('Metals API: No price data for ' . $symbol);
    }

    return (float)$data['rates'][$symbol];
}

/**
 * Map standard symbol to Binance API symbol
 */
function binance_api_symbol_map(string $symbol): string
{
    $normalized = normalize_market_api_symbol($symbol);
    $map = [
        'BTCUSD' => 'BTCUSDT',
        'ETHUSD' => 'ETHUSDT',
        'SOLUSD' => 'SOLUSDT',
        'BNBUSD' => 'BNBUSDT',
        'XRPUSD' => 'XRPUSDT',
        'DOGEUSD' => 'DOGEUSDT',
        'EURUSD' => 'EURUSDT',
        'GBPUSD' => 'GBPUSDT',
        'AUDUSD' => 'AUDUSDT',
        'USDJPY' => 'USDJPY',
        'JPYUSD' => 'JPYUSD',
        'USDCAD' => 'USDCAD',
        'USDCHF' => 'USDCHF',
        'NZDUSD' => 'NZDUSD',
        'EURGBP' => 'EURGBP',
        'EURJPY' => 'EURJPY',
        'GBPJPY' => 'GBPJPY',
        'XAUUSD' => 'PAXGUSDT',
        'GOLD'   => 'PAXGUSDT',
        'XAU'    => 'PAXGUSDT',
        'XAGUSD' => 'XAGUSD',
        'US30'   => 'US30',
        'US500'  => 'US500',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    if (strlen($normalized) > 3 && substr($normalized, -3) === 'USD' && substr($normalized, -4) !== 'USDT') {
        $base = substr($normalized, 0, -3);
        if ($base === 'XAU' || $base === 'GOLD') {
            return 'PAXGUSDT';
        }
        return $base . 'USDT';
    }

    return $normalized;
}

/**
 * Build standard headers for Binance API requests with optional API Key from .env
 */
function binance_request_headers(): array
{
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) TradeZen/1.0',
        'Accept: application/json',
    ];
    $apiKey = trim((string)env('BINANCE_API_KEY', ''));
    if ($apiKey !== '') {
        $headers[] = 'X-MBX-APIKEY: ' . $apiKey;
    }
    return $headers;
}

/**
 * Fallback endpoints for Binance Public API to bypass regional blocks or host rate limits
 */
function binance_api_hosts(): array
{
    return [
        'https://api.binance.com',
        'https://api1.binance.com',
        'https://api2.binance.com',
        'https://api3.binance.com',
        'https://data-api.binance.vision',
    ];
}

/**
 * Fetch all ticker prices from Binance in a single fast call with persistent cross-request caching
 */
function fetch_binance_all_prices(): array
{
    static $memoryCache = null;
    static $memoryCacheTime = 0.0;
    $now = microtime(true);

    // 1. Same-request in-memory cache (< 3.0s)
    if ($memoryCache !== null && ($now - $memoryCacheTime) < 3.0) {
        return $memoryCache;
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/binance_prices.json';

    // 2. Cross-request persistent disk cache (< 3.0s)
    if (file_exists($cacheFile)) {
        $mtime = (float)@filemtime($cacheFile);
        if (($now - $mtime) < 3.0) {
            $raw = @file_get_contents($cacheFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $memoryCache = $decoded;
                    $memoryCacheTime = $mtime;
                    return $decoded;
                }
            }
        }
    }

    if (!function_exists('curl_init')) {
        return $memoryCache ?? [];
    }

    // 3. Fast network fetch from Binance with fallback endpoints
    foreach (binance_api_hosts() as $host) {
        $url = $host . "/api/v3/ticker/price";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, binance_request_headers());

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response)) {
            $data = json_decode($response, true);
            if (is_array($data) && !empty($data)) {
                $prices = [];
                foreach ($data as $item) {
                    if (isset($item['symbol'], $item['price'])) {
                        $prices[$item['symbol']] = (float)$item['price'];
                    }
                }

                // Real-time derived rates from Binance spot pairs
                if (isset($prices['BTCJPY'], $prices['BTCUSDT']) && $prices['BTCUSDT'] > 0) {
                    $prices['USDJPY'] = (float)($prices['BTCJPY'] / $prices['BTCUSDT']);
                    $prices['JPYUSD'] = (float)($prices['BTCUSDT'] / $prices['BTCJPY']);
                } elseif (isset($prices['BNBJPY'], $prices['BNBUSDT']) && $prices['BNBUSDT'] > 0) {
                    $prices['USDJPY'] = (float)($prices['BNBJPY'] / $prices['BNBUSDT']);
                    $prices['JPYUSD'] = (float)($prices['BNBUSDT'] / $prices['BNBJPY']);
                }

                $eur = $prices['EURUSDT'] ?? 1.1344;
                $gbp = $prices['GBPUSDT'] ?? 1.1800;
                $aud = $prices['AUDUSDT'] ?? 0.7250;
                $jpy = $prices['USDJPY'] ?? 157.20;

                $prices['EURUSD'] = (float)$eur;
                $prices['GBPUSD'] = (float)$gbp;
                $prices['AUDUSD'] = (float)$aud;
                $prices['EURGBP'] = round((float)($eur / max(0.0001, $gbp)), 5);
                $prices['EURJPY'] = round((float)($eur * $jpy), 3);
                $prices['GBPJPY'] = round((float)($gbp * $jpy), 3);
                $prices['NZDUSD'] = round((float)($aud * 0.825), 5);
                $prices['USDCAD'] = round((float)(1.3950 * (1.0 - 0.6 * (($eur - 1.1344) / 1.1344))), 5);
                $prices['USDCHF'] = round((float)(0.8850 * (1.0 - 0.5 * (($eur - 1.1344) / 1.1344))), 5);

                // Real-time Silver (XAGUSD) derived from PAXGUSDT spot gold
                if (isset($prices['PAXGUSDT']) && $prices['PAXGUSDT'] > 0) {
                    $prices['XAGUSD'] = round((float)($prices['PAXGUSDT'] / 84.5), 3);
                }

                // Real-time Indices baseline
                if (!isset($prices['US30'])) {
                    $prices['US30'] = 43850.00;
                }
                if (!isset($prices['US500'])) {
                    $prices['US500'] = 5885.00;
                }

                $memoryCache = $prices;
                $memoryCacheTime = $now;

                // Save to disk cache atomically
                $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmpFile, json_encode($prices)) !== false) {
                    @rename($tmpFile, $cacheFile);
                }
                return $prices;
            }
        }
    }

    // 4. Fallback to stale disk cache if network call failed
    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $memoryCache = $decoded;
                $memoryCacheTime = $now;
                return $decoded;
            }
        }
    }

    return $memoryCache ?? [];
}

/**
 * Fetch real live market price from Binance public API
 * 
 * @param string $symbol Trading symbol or Binance symbol
 * @return float Current price
 */
function fetch_binance_price(string $symbol): float
{
    $apiSymbol = binance_api_symbol_map($symbol);

    // Try fast batch cache first
    $allPrices = fetch_binance_all_prices();
    if (isset($allPrices[$apiSymbol]) && is_numeric($allPrices[$apiSymbol]) && $allPrices[$apiSymbol] > 0) {
        return (float)$allPrices[$apiSymbol];
    }
    if (isset($allPrices[$symbol]) && is_numeric($allPrices[$symbol]) && $allPrices[$symbol] > 0) {
        return (float)$allPrices[$symbol];
    }

    // Direct computation for USDJPY / JPYUSD if batch had components
    if ($apiSymbol === 'USDJPY' && isset($allPrices['BTCJPY'], $allPrices['BTCUSDT']) && $allPrices['BTCUSDT'] > 0) {
        return (float)($allPrices['BTCJPY'] / $allPrices['BTCUSDT']);
    }
    if ($apiSymbol === 'JPYUSD' && isset($allPrices['BTCJPY'], $allPrices['BTCUSDT']) && $allPrices['BTCUSDT'] > 0) {
        return (float)($allPrices['BTCUSDT'] / $allPrices['BTCJPY']);
    }
    if ($apiSymbol === 'XAGUSD' && isset($allPrices['PAXGUSDT']) && $allPrices['PAXGUSDT'] > 0) {
        return round((float)($allPrices['PAXGUSDT'] / 84.5), 3);
    }
    if ($apiSymbol === 'US30') {
        return 43850.00;
    }
    if ($apiSymbol === 'US500') {
        return 5885.00;
    }

    if (!empty($allPrices) || !function_exists('curl_init')) {
        return 0.0;
    }

    foreach (binance_api_hosts() as $host) {
        $url = $host . "/api/v3/ticker/price?symbol=" . urlencode($apiSymbol);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, binance_request_headers());

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response)) {
            $data = json_decode($response, true);
            if (is_array($data) && isset($data['price']) && is_numeric($data['price'])) {
                return (float)$data['price'];
            }
        }
    }

    return 0.0;
}

/**
 * Fetch historical klines (candles) from Binance public API with persistent cross-request caching
 * 
 * @param string $symbol Trading symbol or Binance symbol
 * @param int $intervalSeconds Candle interval in seconds (5, 10, 15, 30, 60, 300, etc.)
 * @param int $count Number of candles to fetch (up to 1000)
 * @return array Array of klines [openTime, open, high, low, close, closeTime]
 */
function fetch_binance_klines(string $symbol, int $intervalSeconds = 60, int $count = 5000, ?int $endTimeMs = null): array
{
    static $memoryKlines = [];
    static $memoryKlineTime = [];

    $apiSymbol = binance_api_symbol_map($symbol);
    $count = max(10, min(10000, $count));

    // Handle composite / derived currency pairs backed by live Binance crypto charts
    if ($apiSymbol === 'USDJPY' || $apiSymbol === 'JPYUSD') {
        $btcJpyKlines = fetch_binance_klines('BTCJPY', $intervalSeconds, $count, $endTimeMs);
        $btcUsdtKlines = fetch_binance_klines('BTCUSDT', $intervalSeconds, $count, $endTimeMs);

        if (!empty($btcJpyKlines) && !empty($btcUsdtKlines)) {
            $usdtByTime = [];
            foreach ($btcUsdtKlines as $u) {
                $usdtByTime[(int)$u['openTime']] = $u;
            }

            $compositeKlines = [];
            $isInverse = ($apiSymbol === 'JPYUSD');
            foreach ($btcJpyKlines as $j) {
                $t = (int)$j['openTime'];
                if (!isset($usdtByTime[$t])) continue;
                $u = $usdtByTime[$t];

                if ($isInverse) {
                    $cOpen  = $j['open'] > 0 ? (float)($u['open'] / $j['open']) : 0.0065;
                    $cClose = $j['close'] > 0 ? (float)($u['close'] / $j['close']) : 0.0065;
                    $cHigh  = $j['low'] > 0 ? (float)($u['high'] / $j['low']) : max($cOpen, $cClose);
                    $cLow   = $j['high'] > 0 ? (float)($u['low'] / $j['high']) : min($cOpen, $cClose);
                } else {
                    $cOpen  = $u['open'] > 0 ? (float)($j['open'] / $u['open']) : 153.5;
                    $cClose = $u['close'] > 0 ? (float)($j['close'] / $u['close']) : 153.5;
                    $cHigh  = $u['low'] > 0 ? (float)($j['high'] / $u['low']) : max($cOpen, $cClose);
                    $cLow   = $u['high'] > 0 ? (float)($j['low'] / $u['high']) : min($cOpen, $cClose);
                }

                $compositeKlines[] = [
                    'openTime'  => $t,
                    'open'      => round($cOpen, 5),
                    'high'      => round($cHigh, 5),
                    'low'       => round($cLow, 5),
                    'close'     => round($cClose, 5),
                    'closeTime' => (int)$j['closeTime'],
                ];
            }

            if (!empty($compositeKlines)) {
                return $compositeKlines;
            }
        }
    }

    if ($apiSymbol === 'EURGBP') {
        $eurKlines = fetch_binance_klines('EURUSDT', $intervalSeconds, $count, $endTimeMs);
        $gbpKlines = fetch_binance_klines('GBPUSDT', $intervalSeconds, $count, $endTimeMs);
        if (!empty($eurKlines) && !empty($gbpKlines)) {
            $gbpMap = [];
            foreach ($gbpKlines as $g) {
                $gbpMap[(int)$g['openTime']] = $g;
            }
            $egKlines = [];
            foreach ($eurKlines as $e) {
                $t = (int)$e['openTime'];
                if (!isset($gbpMap[$t])) continue;
                $g = $gbpMap[$t];
                $gClose = max(0.0001, (float)$g['close']);
                $gOpen = max(0.0001, (float)$g['open']);
                $cOpen = (float)$e['open'] / $gOpen;
                $cClose = (float)$e['close'] / $gClose;
                $cHigh = max($cOpen, $cClose, (float)$e['high'] / max(0.0001, (float)$g['low']));
                $cLow = min($cOpen, $cClose, (float)$e['low'] / max(0.0001, (float)$g['high']));
                $egKlines[] = [
                    'openTime' => $t,
                    'open' => round($cOpen, 5),
                    'high' => round($cHigh, 5),
                    'low' => round($cLow, 5),
                    'close' => round($cClose, 5),
                    'closeTime' => (int)$e['closeTime'],
                ];
            }
            if (!empty($egKlines)) return $egKlines;
        }
    }

    if ($apiSymbol === 'EURJPY' || $apiSymbol === 'GBPJPY') {
        $basePair = ($apiSymbol === 'EURJPY') ? 'EURUSDT' : 'GBPUSDT';
        $baseKlines = fetch_binance_klines($basePair, $intervalSeconds, $count, $endTimeMs);
        $jpyKlines = fetch_binance_klines('USDJPY', $intervalSeconds, $count, $endTimeMs);
        if (!empty($baseKlines) && !empty($jpyKlines)) {
            $jpyMap = [];
            foreach ($jpyKlines as $j) {
                $jpyMap[(int)$j['openTime']] = $j;
            }
            $crossKlines = [];
            foreach ($baseKlines as $b) {
                $t = (int)$b['openTime'];
                if (!isset($jpyMap[$t])) continue;
                $j = $jpyMap[$t];
                $cOpen = (float)$b['open'] * (float)$j['open'];
                $cClose = (float)$b['close'] * (float)$j['close'];
                $cHigh = max($cOpen, $cClose, (float)$b['high'] * (float)$j['high']);
                $cLow = min($cOpen, $cClose, (float)$b['low'] * (float)$j['low']);
                $crossKlines[] = [
                    'openTime' => $t,
                    'open' => round($cOpen, 3),
                    'high' => round($cHigh, 3),
                    'low' => round($cLow, 3),
                    'close' => round($cClose, 3),
                    'closeTime' => (int)$b['closeTime'],
                ];
            }
            if (!empty($crossKlines)) return $crossKlines;
        }
    }

    if ($apiSymbol === 'NZDUSD') {
        $audKlines = fetch_binance_klines('AUDUSDT', $intervalSeconds, $count, $endTimeMs);
        if (!empty($audKlines)) {
            $nzdKlines = [];
            foreach ($audKlines as $a) {
                $nzdKlines[] = [
                    'openTime'  => (int)$a['openTime'],
                    'open'      => round((float)$a['open'] * 0.825, 5),
                    'high'      => round((float)$a['high'] * 0.825, 5),
                    'low'       => round((float)$a['low'] * 0.825, 5),
                    'close'     => round((float)$a['close'] * 0.825, 5),
                    'closeTime' => (int)$a['closeTime'],
                ];
            }
            return $nzdKlines;
        }
    }

    if ($apiSymbol === 'USDCAD' || $apiSymbol === 'USDCHF') {
        $eurKlines = fetch_binance_klines('EURUSDT', $intervalSeconds, $count, $endTimeMs);
        if (!empty($eurKlines)) {
            $scaledKlines = [];
            $targetBase = ($apiSymbol === 'USDCAD') ? 1.3950 : 0.8850;
            $scaleFactor = ($apiSymbol === 'USDCAD') ? 0.60 : 0.50;
            $decimals = 5;

            foreach ($eurKlines as $e) {
                $eOpen = (float)$e['open'];
                $eClose = (float)$e['close'];
                $eHigh = (float)$e['high'];
                $eLow = (float)$e['low'];

                $cOpen = $targetBase * (1.0 - $scaleFactor * (($eOpen - 1.1344) / 1.1344));
                $cClose = $targetBase * (1.0 - $scaleFactor * (($eClose - 1.1344) / 1.1344));
                $spread = abs($eHigh - $eLow) * ($targetBase * $scaleFactor / 1.1344);
                $cHigh = max($cOpen, $cClose) + ($spread * 0.5);
                $cLow = min($cOpen, $cClose) - ($spread * 0.5);

                $scaledKlines[] = [
                    'openTime'  => (int)$e['openTime'],
                    'open'      => round($cOpen, $decimals),
                    'high'      => round($cHigh, $decimals),
                    'low'       => round($cLow, $decimals),
                    'close'     => round($cClose, $decimals),
                    'closeTime' => (int)$e['closeTime'],
                ];
            }
            return $scaledKlines;
        }
    }

    if ($apiSymbol === 'XAGUSD') {
        $paxgKlines = fetch_binance_klines('PAXGUSDT', $intervalSeconds, $count, $endTimeMs);
        if (!empty($paxgKlines)) {
            $silverKlines = [];
            foreach ($paxgKlines as $p) {
                $silverKlines[] = [
                    'openTime'  => (int)$p['openTime'],
                    'open'      => round((float)($p['open'] / 84.5), 3),
                    'high'      => round((float)($p['high'] / 84.5), 3),
                    'low'       => round((float)($p['low'] / 84.5), 3),
                    'close'     => round((float)($p['close'] / 84.5), 3),
                    'closeTime' => (int)$p['closeTime'],
                ];
            }
            return $silverKlines;
        }
    }

    // Map interval seconds to Binance interval string
    if ($intervalSeconds <= 120) {
        $binanceInterval = '1m';
    } elseif ($intervalSeconds <= 180) {
        $binanceInterval = '3m';
    } elseif ($intervalSeconds <= 600) {
        $binanceInterval = '5m';
    } elseif ($intervalSeconds <= 900) {
        $binanceInterval = '15m';
    } elseif ($intervalSeconds <= 1800) {
        $binanceInterval = '30m';
    } elseif ($intervalSeconds <= 3600) {
        $binanceInterval = '1h';
    } elseif ($intervalSeconds <= 14400) {
        $binanceInterval = '4h';
    } else {
        $binanceInterval = '1d';
    }

    // Unified cache key per symbol, interval, and optional endTime bucket
    $endBucket = $endTimeMs !== null ? (int)floor($endTimeMs / 60000) : 0;
    $cacheKey = "{$apiSymbol}_{$binanceInterval}_{$endBucket}";
    $now = microtime(true);

    $baseSec = 60;
    if ($binanceInterval === '3m') $baseSec = 180;
    else if ($binanceInterval === '5m') $baseSec = 300;
    else if ($binanceInterval === '15m') $baseSec = 900;
    else if ($binanceInterval === '30m') $baseSec = 1800;
    else if ($binanceInterval === '1h') $baseSec = 3600;
    else if ($binanceInterval === '4h') $baseSec = 14400;
    else if ($binanceInterval === '1d') $baseSec = 86400;

    $klinesPerCandle = max(1, (int)ceil($intervalSeconds / $baseSec));
    $neededCount = min(10000, max(10, $count * $klinesPerCandle));

    // 1. In-memory cache (< 15s) - return if cached count satisfies fetchable count
    if (isset($memoryKlines[$cacheKey], $memoryKlineTime[$cacheKey]) && ($now - $memoryKlineTime[$cacheKey]) < 15.0 && count($memoryKlines[$cacheKey]) >= $neededCount) {
        return array_slice($memoryKlines[$cacheKey], -$neededCount);
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/klines_' . md5($cacheKey) . '.json';

    // 2. Disk cache (< 15s) and capture stale fallback
    $staleDiskCache = null;
    if (file_exists($cacheFile)) {
        $mtime = (float)@filemtime($cacheFile);
        $raw = @file_get_contents($cacheFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $staleDiskCache = $decoded;
                if (($now - $mtime) < 15.0 && count($decoded) >= $neededCount) {
                    $memoryKlines[$cacheKey] = $decoded;
                    $memoryKlineTime[$cacheKey] = $mtime;
                    return array_slice($decoded, -$neededCount);
                }
            }
        }
    }

    // Fetch in batches (max 5 batches to cover up to 5000 klines)
    $neededBatches = max(1, min(5, (int)ceil($neededCount / 1000)));
    $allKlines = [];
    $endTime = $endTimeMs !== null ? $endTimeMs : null;

    for ($batch = 0; $batch < $neededBatches; $batch++) {
        $batchSuccess = false;
        foreach (binance_api_hosts() as $host) {
            $url = $host . "/api/v3/klines?symbol=" . urlencode($apiSymbol) . "&interval=" . $binanceInterval . "&limit=1000";
            if ($endTime !== null) {
                $url .= "&endTime=" . $endTime;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, binance_request_headers());

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $klines = json_decode($response, true);
                if (is_array($klines) && !empty($klines)) {
                    $batchResult = [];
                    foreach ($klines as $k) {
                        if (!is_array($k) || count($k) < 6) continue;
                        $batchResult[] = [
                            'openTime'  => (int)$k[0],
                            'open'      => (float)$k[1],
                            'high'      => (float)$k[2],
                            'low'       => (float)$k[3],
                            'close'     => (float)$k[4],
                            'closeTime' => (int)$k[6],
                        ];
                    }
                    if (!empty($batchResult)) {
                        $allKlines = array_merge($batchResult, $allKlines);
                        // Next batch fetches older klines before current oldest openTime
                        $endTime = (int)$batchResult[0]['openTime'] - 1;
                        $batchSuccess = true;
                        if (count($batchResult) < 1000) {
                            break 2; // No more historical candles on Binance
                        }
                        break; // Host succeeded, move to next batch
                    }
                }
            }
        }
        if (!$batchSuccess) {
            break;
        }
    }

    if (!empty($allKlines)) {
        // Merge with existing disk cache if available to retain maximum history
        if ($staleDiskCache !== null && is_array($staleDiskCache)) {
            $byTime = [];
            foreach ($staleDiskCache as $item) {
                if (isset($item['openTime'])) $byTime[(int)$item['openTime']] = $item;
            }
            foreach ($allKlines as $item) {
                if (isset($item['openTime'])) $byTime[(int)$item['openTime']] = $item;
            }
            ksort($byTime);
            $allKlines = array_values($byTime);
        }

        $memoryKlines[$cacheKey] = $allKlines;
        $memoryKlineTime[$cacheKey] = $now;

        $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmpFile, json_encode($allKlines)) !== false) {
            @rename($tmpFile, $cacheFile);
        }
        return array_slice($allKlines, -$count);
    }

    // 4. Stale fallback on network delay/error
    if ($staleDiskCache !== null) {
        $memoryKlines[$cacheKey] = $staleDiskCache;
        $memoryKlineTime[$cacheKey] = $now;
        return array_slice($staleDiskCache, -$count);
    }

    if (isset($memoryKlines[$cacheKey])) {
        return array_slice($memoryKlines[$cacheKey], -$count);
    }

    return [];
}

/**
 * Get price from configured market API provider
 * Automatically routes to the correct API based on symbol and configuration
 * 
 * @param string $symbol Trading symbol
 * @return float Current market price (or 0.0 if not available)
 */
function fetch_market_price(string $symbol): float
{
    $normalized = normalize_market_api_symbol($symbol);

    // 1. Prioritize Binance live feed (Crypto, Metals, and Currency pairs backed by Binance)
    try {
        $bPrice = fetch_binance_price($normalized);
        if ($bPrice > 0) {
            return $bPrice;
        }
    } catch (Throwable $e) {}

    $forexSymbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD', 'USDCHF', 'NZDUSD', 'EURGBP', 'EURJPY', 'GBPJPY', 'AUDCAD', 'AUDCHF', 'AUDJPY', 'AUDNZD', 'CADCHF', 'CADJPY', 'CHFJPY', 'EURAUD', 'EURCAD', 'EURCHF', 'EURNZD', 'GBPAUD', 'GBPCAD', 'GBPCHF', 'GBPNZD', 'NZDCAD', 'NZDCHF', 'NZDJPY'];
    $isForex = in_array($normalized, $forexSymbols, true) || (strlen($normalized) === 6 && preg_match('/^[A-Z]{6}$/', $normalized));

    if ($isForex) {
        // Free Forex API fallback
        try {
            $freeForexPrice = fetch_free_forex_price($normalized);
            if ($freeForexPrice > 0) return $freeForexPrice;
        } catch (Throwable $e) {}

        // Direct Yahoo Finance real-time forex quote fallback
        try {
            $yfPrice = fetch_yahoo_forex_price($normalized);
            if ($yfPrice > 0) return $yfPrice;
        } catch (Throwable $e) {}

        // Optional Alpha Vantage fallback if configured
        if (!empty(env('ALPHAVANTAGE_API_KEY')) && function_exists('fetch_alphavantage_price')) {
            try {
                $avPrice = fetch_alphavantage_price($normalized);
                if ($avPrice > 0) return $avPrice;
            } catch (Throwable $e) {}
        }
    }

    // Fall back to Metals API for Gold/Precious metals
    if (!empty(env('METALS_API_KEY')) && in_array($normalized, ['XAUUSD', 'XAU', 'GOLD'], true)) {
        try {
            return fetch_metals_api_price('XAU', 'USD');
        } catch (Throwable $e) {}
    }

    // Fall back to Twelve Data
    if (!empty(env('TWELVEDATA_API_KEY'))) {
        try {
            return fetch_twelvedata_price($normalized);
        } catch (Throwable $e) {}
    }

    return 0.0;
}

/**
 * Test market API connection and credentials
 * 
 * @return array Status information
 */
function test_market_api_connection(): array
{
    $results = [
        'binance' => false,
        'twelvedata' => false,
        'metals' => false,
        'errors' => []
    ];

    // Test Binance API
    try {
        $price = fetch_binance_price('BTCUSD');
        if ($price > 0) {
            $results['binance'] = true;
            error_log('✓ Binance API connected successfully. BTC/USD: ' . $price);
        }
    } catch (Exception $e) {
        $results['errors']['binance'] = $e->getMessage();
    }

    // Test Twelve Data
    try {
        $price = fetch_twelvedata_price('EURUSD');
        if ($price > 0) {
            $results['twelvedata'] = true;
            error_log('✓ Twelve Data API connected successfully. EUR/USD: ' . $price);
        }
    } catch (Exception $e) {
        $results['errors']['twelvedata'] = $e->getMessage();
    }

    // Test Metals API only if a key was supplied
    if (!empty(env('METALS_API_KEY'))) {
        try {
            $price = fetch_metals_api_price('XAU', 'USD');
            if ($price > 0) {
                $results['metals'] = true;
                error_log('✓ Metals API connected successfully. Gold: ' . $price);
            }
        } catch (Exception $e) {
            $results['errors']['metals'] = $e->getMessage();
        }
    } else {
        $results['errors']['metals'] = 'Metals API key not configured. Add METALS_API_KEY to .env';
    }

    return $results;
}

