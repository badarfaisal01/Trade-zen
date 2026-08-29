<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/app/bootstrap.php';

$user = require_user();
json_response([
    'data' => original_trade_settings($user),
    'meta' => ['include' => [], 'custom' => []],
]);
