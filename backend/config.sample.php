<?php
// Copy this file to config.php and fill it in. Never commit config.php.
return [
    // MySQL on your host. For a quick local trial: 'dsn' => 'sqlite:' . __DIR__ . '/data/bhw.sqlite'
    'dsn' => 'mysql:host=localhost;dbname=bohol_health_watch;charset=utf8mb4',
    'db_user' => 'bhw_user',
    'db_pass' => 'change-me',

    // Create this with: php bin/hash-password.php
    'admin_password_hash' => '',

    // RSS 2.0 or Atom feeds checked by bin/collect.php.
    // Both URLs below returned valid RSS on 5 November 2026.
    'feeds' => [
        ['name' => 'Bohol Island News', 'url' => 'https://boholislandnews.com/feed/'],
        ['name' => 'The Bohol Tribune', 'url' => 'https://theboholtribune.com/feed/'],
    ],
];
