<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (current_user()) {
    redirect('/en/trade/');
}

redirect('/en/sign-in/');
