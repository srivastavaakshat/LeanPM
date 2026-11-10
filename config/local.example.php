<?php
// Copy to config/local.php on the server (never commit it) and fill in your values.
return [
    'env'        => 'production',
    'debug'      => false,
    'url'        => 'https://leanpm.houseofjugaad.in',
    'base_path'  => '',
    'clean_urls' => true,
    'app_key'    => 'paste-a-long-random-string-here',   // php -r "echo bin2hex(random_bytes(32));"
    // @mention emails. Create a mailbox in hPanel → Emails (e.g. leanpm@houseofjugaad.in) and put its password here.
    'mail' => [
        'host'      => 'smtp.hostinger.com',
        'port'      => 465,                          // 465 = SSL
        'username'  => 'leanpm@houseofjugaad.in',
        'password'  => 'PASTE-THE-MAILBOX-PASSWORD',
        'from'      => 'leanpm@houseofjugaad.in',
        'from_name' => 'LeanPM',
        'reply_to'  => '',                           // optional, e.g. your own address
    ],
    // Sign in with Google (optional). Google Cloud Console → APIs & Services → Credentials → OAuth client ID.
    // Redirect URI: https://leanpm.houseofjugaad.in/auth/google/callback
    'google' => [
        'client_id'       => '',                     // ….apps.googleusercontent.com
        'client_secret'   => '',
        'allowed_domains' => [],                     // e.g. ['example.com'] to only allow those emails; [] = anyone you've added
    ],
    'database' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'u123456789_leanpm',
        'username' => 'u123456789_lpmuser',
        'password' => 'PASTE-THE-GENERATED-DB-PASSWORD',
    ],
];
