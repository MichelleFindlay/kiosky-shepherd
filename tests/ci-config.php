<?php

// config/config.php for the CI smoke test (.github/workflows/ci.yml). Not for production.

return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=shepherd;charset=utf8mb4',
        'user' => 'shepherd',
        'password' => 'shepherd',
    ],
    'base_url' => 'http://127.0.0.1:8080',
    'organisation' => 'CI',
    'cookie_secure' => false,
    'timezone' => 'Europe/London',
    // The smoke test fakes proxy headers from 127.0.0.1; without them it connects directly
    'trusted_proxies' => ['private', 'cloudflare'],
];
