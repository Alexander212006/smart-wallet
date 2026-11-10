<?php

declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = new PDO(
        getenv('SMART_WALLET_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=smart_wallet;charset=utf8mb4',
        getenv('SMART_WALLET_DB_USER') !== false ? getenv('SMART_WALLET_DB_USER') : 'root',
        getenv('SMART_WALLET_DB_PASSWORD') !== false ? getenv('SMART_WALLET_DB_PASSWORD') : '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}
