<?php
declare(strict_types=1);

require dirname(__DIR__, 6) . '/app/bootstrap.php';

require_user();

// Cache-busting headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Build modals structure with all active market assets grouped by category
$assets = market_assets(false);
$byCategory = [];
foreach ($assets as $asset) {
    $rawCat = (string)($asset['category'] ?? 'Currencies');
    $catLower = strtolower(trim($rawCat));
    if ($catLower === 'forex' || $catLower === 'currency' || $catLower === 'currencies') $catKey = 'currencies';
    elseif ($catLower === 'crypto' || $catLower === 'cryptocurrency') $catKey = 'crypto';
    elseif ($catLower === 'commodity' || $catLower === 'commodities' || $catLower === 'metal' || $catLower === 'metals' || $catLower === 'gold') $catKey = 'commodities';
    elseif ($catLower === 'stock' || $catLower === 'stocks') $catKey = 'stocks';
    elseif ($catLower === 'indices' || $catLower === 'index') $catKey = 'indices';
    else $catKey = $catLower;

    if (!isset($byCategory[$catKey])) {
        $byCategory[$catKey] = [
            'id' => $catKey,
            'title' => ucfirst($catKey),
            'type' => 'market_list',
            'items' => []
        ];
    }
    $isActive = (bool)($asset['is_active'] ?? true);
    $byCategory[$catKey]['items'][] = [
        'symbol' => (string)$asset['symbol'],
        'name' => (string)$asset['display_name'],
        'category' => $rawCat,
        'payout' => $isActive ? (float)$asset['payout_percent'] : 0.0,
        'volatility' => (float)$asset['volatility'],
        'active' => $isActive,
        'is_open' => $isActive,
    ];
}

$modals = array_values($byCategory);
json_response(['modals' => $modals]);
