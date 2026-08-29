<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!current_user()) {
    redirect('/en/admin/login.php');
}

$query = $_SERVER['QUERY_STRING'] ?? '';
redirect('/admin/' . ($query !== '' ? '?' . $query : ''));
