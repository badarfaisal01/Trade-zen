<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$user = require_user();
// The supplied React router shows a full-page LOADING screen and hard-navigates
// when a Demo profile is rendered on the Live URL. `/en/trade/` is the explicit
// Live route, so align the account before emitting any HTML. This renders the
// requested page in the same response and removes the redirect/refresh entirely.
if (trade_account_type() !== 'live') {
    set_trade_account_type('live', (int)$user['id']);
    $user = current_user(true) ?: $user;
}
render_original_trade_route($user);
