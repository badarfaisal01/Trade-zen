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
 * Get price from configured market API provider
 * Automatically routes to the correct API based on symbol and configuration
 * 
 * @param string $symbol Trading symbol
 * @return float Current market price
 */
function fetch_market_price(string $symbol): float
{
    $normalized = normalize_market_api_symbol($symbol);

    // Determine which API to use based on symbol
    if (in_array($normalized, ['XAUUSD', 'XAU', 'GOLD'], true)) {
        // Gold/Precious metals
        try {
            return fetch_metals_api_price('XAU', 'USD');
        } catch (Exception $e) {
            error_log('Metals API failed for XAUUSD: ' . $e->getMessage());
        }
    } elseif (in_array($normalized, ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDCAD', 'EURJPY', 'BTCUSD', 'ETHUSD'], true)) {
        // Forex and Crypto
        try {
            return fetch_twelvedata_price($normalized);
        } catch (Exception $e) {
            error_log('Twelve Data API failed for ' . $normalized . ': ' . $e->getMessage());
        }
    }

    throw new RuntimeException('No API provider configured for symbol: ' . $normalized);
}

/**
 * Test market API connection and credentials
 * 
 * @return array Status information
 */
function test_market_api_connection(): array
{
    $results = [
        'twelvedata' => false,
        'metals' => false,
        'errors' => []
    ];

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
