<?php
declare(strict_types=1);

require dirname(__DIR__, 6) . '/app/bootstrap.php';

require_user();

// Cache-busting headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Build modals structure with all active market assets grouped by category
$assets = market_assets(true);
$modals = [];

// Group assets by category
$byCategory = [];
foreach ($assets as $asset) {
    $category = (string)($asset['category'] ?? 'forex');
    if (!isset($byCategory[$category])) {
        $byCategory[$category] = [];
    }
    $byCategory[$category][] = $asset;
}

// Build modal for each category
foreach ($byCategory as $category => $categoryAssets) {
    $modals[] = [
        'id' => $category,
        'title' => ucfirst($category),
        'type' => 'market_list',
        'items' => array_map(static function(array $asset): array {
            return [
                'symbol' => (string)$asset['symbol'],
                'name' => (string)$asset['display_name'],
                'category' => (string)$asset['category'],
                'payout' => (float)$asset['payout_percent'],
                'volatility' => (float)$asset['volatility'],
            ];
        }, $categoryAssets),
    ];
}

json_response(['modals' => $modals]);
