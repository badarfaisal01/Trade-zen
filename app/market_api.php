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

function fetch_twelvedata_price(string $symbol, ?string $apiKey = null): float
{
    $apiKey = $apiKey ?? getenv('TWELVEDATA_API_KEY');
    if (empty($apiKey)) {
        throw new RuntimeException('Twelve Data API key not configured. Add TWELVEDATA_API_KEY to .env');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for market API feeds.');
    }

    $normalizedSymbol = normalize_twelvedata_symbol($symbol);

    // Twelve Data API endpoint
    $url = "https://api.twelvedata.com/quote?symbol=" . urlencode($normalizedSymbol) . "&apikey=" . urlencode($apiKey);

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
        throw new RuntimeException('Twelve Data API error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException('Twelve Data API returned HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Twelve Data API returned invalid JSON');
    }

    if (isset($data['status']) && $data['status'] !== 'ok' && (!isset($data['symbol']) || $data['symbol'] === '')) {
        throw new RuntimeException('Twelve Data API error: ' . ($data['message'] ?? 'Unknown error'));
    }

    $price = $data['price'] ?? $data['close'] ?? $data['last'] ?? null;
    if (!is_numeric($price)) {
        throw new RuntimeException('Twelve Data API: No price data for symbol ' . $symbol);
    }

    return (float)$price;
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
    $apiKey = $apiKey ?? getenv('METALS_API_KEY');
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
        'XAUUSD' => 'PAXGUSDT',
        'GOLD'   => 'PAXGUSDT',
        'XAU'    => 'PAXGUSDT',
        'USDJPY' => 'USDJPY',
        'JPYUSD' => 'JPYUSD',
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
 * Fetch all ticker prices from Binance in a single fast call with persistent cross-request caching
 */
function fetch_binance_all_prices(): array
{
    static $memoryCache = null;
    static $memoryCacheTime = 0.0;
    $now = microtime(true);

    // 1. Same-request in-memory cache (< 2.5s)
    if ($memoryCache !== null && ($now - $memoryCacheTime) < 2.5) {
        return $memoryCache;
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/market';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/binance_prices.json';

    // 2. Cross-request persistent disk cache (< 2.5s)
    if (file_exists($cacheFile)) {
        $mtime = (float)@filemtime($cacheFile);
        if (($now - $mtime) < 2.5) {
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

    // 3. Fast network fetch from Binance with tight timeouts
    $url = "https://api.binance.com/api/v3/ticker/price";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) 55AKTrade/1.0');

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

    // Direct computation for USDJPY / JPYUSD if batch had components
    if ($apiSymbol === 'USDJPY' && isset($allPrices['BTCJPY'], $allPrices['BTCUSDT']) && $allPrices['BTCUSDT'] > 0) {
        return (float)($allPrices['BTCJPY'] / $allPrices['BTCUSDT']);
    }
    if ($apiSymbol === 'JPYUSD' && isset($allPrices['BTCJPY'], $allPrices['BTCUSDT']) && $allPrices['BTCJPY'] > 0) {
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

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for market API feeds.');
    }

    $url = "https://api.binance.com/api/v3/ticker/price?symbol=" . urlencode($apiSymbol);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) 55AKTrade/1.0');

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException('Binance API error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException('Binance API returned HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['price']) || !is_numeric($data['price'])) {
        throw new RuntimeException('Binance API: No valid price data for ' . $symbol);
    }

    return (float)$data['price'];
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

    // Handle composite pairs: USDJPY, JPYUSD, XAGUSD
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
                    'open'      => $cOpen,
                    'high'      => $cHigh,
                    'low'       => $cLow,
                    'close'     => $cClose,
                    'closeTime' => (int)$j['closeTime'],
                ];
            }

            if (!empty($compositeKlines)) {
                return $compositeKlines;
            }
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
    if ($intervalSeconds <= 60) {
        $binanceInterval = '1m';
    } elseif ($intervalSeconds <= 180) {
        $binanceInterval = '3m';
    } elseif ($intervalSeconds <= 300) {
        $binanceInterval = '5m';
    } elseif ($intervalSeconds <= 900) {
        $binanceInterval = '15m';
    } elseif ($intervalSeconds <= 1800) {
        $binanceInterval = '30m';
    } elseif ($intervalSeconds <= 3600) {
        $binanceInterval = '1h';
    } else {
        $binanceInterval = '1d';
    }

    // Unified cache key per symbol, interval, and optional endTime bucket
    $endBucket = $endTimeMs !== null ? (int)floor($endTimeMs / 60000) : 0;
    $cacheKey = "{$apiSymbol}_{$binanceInterval}_{$endBucket}";
    $now = microtime(true);

    $neededCount = min(3000, $count);

    // 1. In-memory cache (< 15s) - return if cached count satisfies fetchable count
    if (isset($memoryKlines[$cacheKey], $memoryKlineTime[$cacheKey]) && ($now - $memoryKlineTime[$cacheKey]) < 15.0 && count($memoryKlines[$cacheKey]) >= $neededCount) {
        return array_slice($memoryKlines[$cacheKey], -$count);
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
                    return array_slice($decoded, -$count);
                }
            }
        }
    }

    // Fetch in batches (max 3 batches to avoid blocking PHP too long)
    $neededBatches = max(1, min(3, (int)ceil(min(3000, max(1000, $count)) / 1000)));
    $allKlines = [];
    $endTime = $endTimeMs !== null ? $endTimeMs : null;

    for ($batch = 0; $batch < $neededBatches; $batch++) {
        $url = "https://api.binance.com/api/v3/klines?symbol=" . urlencode($apiSymbol) . "&interval=" . $binanceInterval . "&limit=1000";
        if ($endTime !== null) {
            $url .= "&endTime=" . $endTime;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) 55AKTrade/1.0');

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
                    if (count($batchResult) < 1000) {
                        break; // No more historical candles on Binance
                    }
                    continue;
                }
            }
        }
        break;
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
 * @return float Current market price
 */
function fetch_market_price(string $symbol): float
{
    $normalized = normalize_market_api_symbol($symbol);

    // 1. First try Binance API (free, reliable, real-time for Crypto, Metals/PAXG, Forex/USDT)
    try {
        return fetch_binance_price($normalized);
    } catch (Exception $e) {
        error_log('Binance API failed for ' . $normalized . ': ' . $e->getMessage());
    }

    // 2. Fall back to Metals API for Gold/Precious metals
    if (in_array($normalized, ['XAUUSD', 'XAU', 'GOLD'], true)) {
        try {
            return fetch_metals_api_price('XAU', 'USD');
        } catch (Exception $e) {
            error_log('Metals API failed for XAUUSD: ' . $e->getMessage());
        }
    }

    // 3. Fall back to Twelve Data
    try {
        return fetch_twelvedata_price($normalized);
    } catch (Exception $e) {
        error_log('Twelve Data API failed for ' . $normalized . ': ' . $e->getMessage());
    }

    throw new RuntimeException('No API provider configured or available for symbol: ' . $normalized);
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
    if (!empty(getenv('METALS_API_KEY'))) {
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

