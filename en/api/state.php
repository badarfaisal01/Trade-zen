<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();

json_response(['ok' => true, 'data' => user_trade_state((int)$user['id'], 25)]);
