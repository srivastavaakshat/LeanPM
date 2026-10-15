<?php
/**
 * Database connection.
 * Hostinger: driver 'mysql' (MariaDB) with the credentials in config/local.php.
 * Local development: 'sqlite' needs no setup at all.
 */
return [
    'driver'      => 'sqlite',              // mysql | sqlite
    'host'        => '127.0.0.1',
    'port'        => 3306,
    'database'    => 'leanpm',
    'username'    => 'root',
    'password'    => '',
    'charset'     => 'utf8mb4',
    'sqlite_path' => dirname(__DIR__) . '/storage/database.sqlite',
];
