<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

// The captured React dropdown used this route for its former broker's
// registration modal. An already-authenticated 55AK user must never be sent
// through sign-up when selecting Demo/Live Account.
if (current_user()) {
    redirect('/en/trade/');
}

$next = isset($_GET['next']) ? '?next=' . rawurlencode((string)$_GET['next']) : '';
header('Location: /en/sign-up/' . $next, true, 302);
exit;
