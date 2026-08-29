<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once BASE_PATH . '/app/public_pages.php';
render_public_information_page('about');
