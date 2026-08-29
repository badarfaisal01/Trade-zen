<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$filename = trim((string)setting('logo_filename', ''));
if ($filename === '') {
    http_response_code(404);
    exit('Logo not found.');
}
stream_protected_image('site-assets', $filename, false);
