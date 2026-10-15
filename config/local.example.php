<?php
// Copy to config/local.php on the server (never commit it) and fill in your values.
return [
    'env'        => 'production',
    'debug'      => false,
    'url'        => 'https://pm.houseofjugaad.com',
    'base_path'  => '',
    'clean_urls' => true,
    'app_key'    => 'paste-a-long-random-string-here',   // php -r "echo bin2hex(random_bytes(32));"
    'database' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'u123456789_leanpm',
        'username' => 'u123456789_lpmuser',
        'password' => 'PASTE-THE-GENERATED-DB-PASSWORD',
    ],
];
