<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$stmt = db()->query("SELECT DISTINCT user_id FROM trades WHERE status = 'open' AND expires_at <= NOW() ORDER BY user_id ASC LIMIT 500");
$settled = 0;
foreach ($stmt->fetchAll() as $row) {
    $userId = (int)$row['user_id'];
    for ($batch = 0; $batch < 10; $batch++) {
        $count = settle_open_trades($userId);
        $settled += $count;
        if ($count < 50) {
            break;
        }
    }
}

db()->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 30 DAY)");
echo sprintf("[%s] settled=%d\n", date(DATE_ATOM), $settled);
