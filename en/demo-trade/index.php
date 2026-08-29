<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = require_user();
if (!(bool)setting('demo_trading_enabled', true)) {
    redirect('/en/trade/');
}
set_trade_account_type('demo', (int)$user['id']);
$user = current_user(true) ?: $user;
render_original_trade_route($user);
