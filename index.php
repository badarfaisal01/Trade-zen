<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';

if (!is_installed()) {
    header('Location: /install/');
    exit;
}

header('Location: /en/');
exit;
