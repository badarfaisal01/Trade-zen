<?php
declare(strict_types=1);

function database_config_path(): string
{
    return dirname(__DIR__) . '/config/database.local.php';
}

function is_installed(): bool
{
    return is_file(database_config_path()) && is_file(dirname(__DIR__) . '/storage/installed.lock');
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = database_config_path();
    if (!is_file($path)) {
        throw new RuntimeException('Application database is not configured.');
    }

    $config = require $path;
    $host = (string)($config['host'] ?? 'localhost');
    $port = (int)($config['port'] ?? 3306);
    $database = (string)($config['database'] ?? '');
    $charset = (string)($config['charset'] ?? 'utf8mb4');
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($database === '' || $username === '') {
        throw new RuntimeException('Database name and username are required.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
    ]);
    $pdo->exec("SET time_zone = '+05:30'");

    return $pdo;
}

function db_transaction(callable $callback)
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $result = $callback($pdo);
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
