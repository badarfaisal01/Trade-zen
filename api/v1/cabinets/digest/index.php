<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/app/bootstrap.php';

// Cache-busting headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user = require_user();
json_response([
    'data' => original_trade_settings($user),
    'meta' => ['include' => [], 'custom' => []],
]);
