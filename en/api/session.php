<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

json_response([
    'ok' => true,
    'data' => [
        'authenticated' => true,
        'userId' => (int)$user['id'],
        'serverTime' => (int)floor(microtime(true) * 1000),
    ],
]);
