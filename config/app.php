<?php
/**
 * Application configuration.
 * Override any value in config/local.php (never committed), which returns an
 * array with the same shape. On Hostinger that file holds the database password.
 */
$config = [
    'name'          => 'LeanPM',
    'env'           => 'local',            // local | production
    'debug'         => true,
    'url'           => '',                 // e.g. https://leanpm.houseofjugaad.in (no trailing slash). Empty = auto-detect.
    'base_path'     => null,               // URL prefix. '' when the root .htaccess forwards into /public. null = auto-detect.
    'timezone'      => 'Asia/Kolkata',
    'clean_urls'    => true,               // false = index.php?r=/path (hosts without mod_rewrite)
    'session_name'  => 'lpm_session',
    'upload_max_mb' => 25,                 // per file. PHP's upload_max_filesize / post_max_size must be at least this.
    'storage_path'  => dirname(__DIR__) . '/storage',
    'app_key'       => '',                 // random string, set in config/local.php. Salts the login rate-limit hash.
    'database'      => require __DIR__ . '/database.php',
];

if (is_file(__DIR__ . '/local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/local.php');
}

return $config;
