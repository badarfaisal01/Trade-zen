<?php
declare(strict_types=1);

require dirname(__DIR__, 6) . '/app/bootstrap.php';

require_user();
json_response(['modals' => []]);
