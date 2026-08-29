<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

try {
    db()->query('SELECT 1')->fetchColumn();
    json_response(['ok' => true, 'status' => 'healthy', 'version' => app_config('version'), 'time' => date(DATE_ATOM)]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'status' => 'unavailable', 'time' => date(DATE_ATOM)], 503);
}
