<?php

$appEnvironment = strtolower((string) (getenv('APP_ENV') ?: 'development'));
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'school_bookol';
$dbUsername = getenv('DB_USER') ?: getenv('DB_USERNAME') ?: ($appEnvironment === 'development' ? 'root' : '');
$dbPassword = getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '';

if (
    $dbUsername === ''
    || ($appEnvironment === 'production' && ($dbUsername === 'root' || $dbPassword === ''))
) {
    throw new RuntimeException('Configure a non-root database account and password using DB_USER and DB_PASSWORD.');
}

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

return new PDO($dsn, $dbUsername, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
