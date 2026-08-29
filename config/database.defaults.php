<?php
declare(strict_types=1);

/*
 * Copy the production values to database.local.php on the server.
 * Never commit real database credentials to the repository.
 */
return [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'your_database_name',
    'username' => 'your_database_user',
    'password' => 'change_this_password',
    'charset' => 'utf8mb4',
];
