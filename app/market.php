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
    $seconds = $timestampMs / 1000.0;
    $seed = (int)$asset['market_seed'];
    $base = market_reference_price($asset);
    $volatility = max(0.00005, (float)$asset['volatility']);
    $phase = ($seed % 997) / 17.0;

    $macro = sin(($seconds + $phase) / 181.0) * 0.58;
    $mid = sin(($seconds + ($phase * 3.1)) / 47.0) * 0.27;
    $micro = sin(($seconds + ($phase * 7.7)) / 11.0) * 0.15;

    $bucketSize = 4;
    $bucket = (int)floor($seconds / $bucketSize);
    $fraction = fmod($seconds, (float)$bucketSize) / $bucketSize;
    if ($fraction < 0) {
        $fraction += 1.0;
    }
    $smooth = $fraction * $fraction * (3.0 - 2.0 * $fraction);
    $noiseA = market_noise($seed, $bucket);
    $noiseB = market_noise($seed, $bucket + 1);
    $noise = $noiseA + (($noiseB - $noiseA) * $smooth);

    $ratio = (($macro + $mid + $micro) * $volatility * 2.2) + ($noise * $volatility * 0.75);
    $price = max($base * 0.1, $base * (1.0 + $ratio));
    return round($price, (int)$asset['decimal_places']);
}

function market_candles(array $asset, int $intervalSeconds = 5, int $count = 72, ?int $endMs = null): array
{
    $intervalSeconds = max(1, min(300, $intervalSeconds));
    $count = max(10, min(180, $count));
    $endMs = $endMs ?? ((int)floor(microtime(true) * 1000));
    $intervalMs = $intervalSeconds * 1000;
    $currentBucketMs = (int)(floor($endMs / $intervalMs) * $intervalMs);
    $startMs = $currentBucketMs - (($count - 1) * $intervalMs);
    $decimals = max(0, min(8, (int)$asset['decimal_places']));
    $density = max(4, min(16, (int)setting('chart_tick_density', 10)));
    $candles = [];

    for ($i = 0; $i < $count; $i++) {
        $time = $startMs + ($i * $intervalMs);
        $candleEndMs = min($time + $intervalMs - 1, $endMs);
        if ($candleEndMs < $time) {
            continue;
        }

        // The captured chart consumes ticks and builds its own OHLC bars. The
        // old adapter sent only four synthetic points per candle and, for the
        // current candle, included points from the future. That made the final
        // bar jump backwards or collapse into a thin/half candle. Emit a dense,
        // chronological tick series and stop exactly at the current server time.
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
    if (($asset['price_source'] ?? 'internal') === 'api' && isset($asset['source_price']) && (float)$asset['source_price'] > 0) {
        return (float)$asset['source_price'];
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
    $template = trim((string)setting('market_api_url', ''));
    $path = str_replace('{symbol}', $symbol, trim((string)setting('market_api_price_path', 'price')));
    $url = str_replace('{symbol}', rawurlencode($symbol), $template);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Configure a valid market API URL containing {symbol}.');
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
    if (!is_finite($price) || $price <= 0 || $price > 1000000000000) {
        throw new RuntimeException('Market API returned an invalid price.');
    }
    $price = round($price, (int)$asset['decimal_places']);
    $upsert = db()->prepare('INSERT INTO market_price_cache (asset_id,price,source,fetched_at,raw_response_hash) VALUES (?,? ,?,NOW(),?) ON DUPLICATE KEY UPDATE price=VALUES(price),source=VALUES(source),fetched_at=NOW(),raw_response_hash=VALUES(raw_response_hash)');
    $upsert->execute([$assetId, $price, $host, hash('sha256', $raw)]);
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
