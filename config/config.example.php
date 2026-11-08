<?php

// Copy to config/config.php and fill in. config.php is git-ignored.

return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=shepherd;charset=utf8mb4',
        'user' => 'shepherd',
        'password' => 'change-me',
    ],

    // Public https:// address of this server, without a trailing slash. Used in
    // enrollment QR codes and APK download links handed to devices.
    'base_url' => 'https://kiosks.example.com',

    // Shown on managed devices ("Managed by …")
    'organisation' => 'Example Ltd',

    // One-time key for creating the first console user from the browser (useful
    // on hosting without SSH). Set a long random value, open the site, create
    // the admin, then remove this line. Ignored once any user exists.
    'setup_key' => '',

    // Where uploaded APKs and screenshots are kept. Must be writable by PHP.
    // The default <project>/storage is protected by .htaccess, but if your host
    // lets you, a folder outside public_html is safer, e.g. '/home/you/kiosky-storage'.
    // 'storage_dir' => '/home/you/kiosky-storage',

    // IPs of reverse proxies / load balancers whose X-Forwarded-For and
    // X-Forwarded-Proto headers should be trusted.
    'trusted_proxies' => [],

    // Session cookie is Secure by default; set false only for local http testing.
    'cookie_secure' => true,
    'session_lifetime' => 43200, // idle seconds before the console signs you out

    // Uploaded APKs must have this package name (Kiosky's applicationId).
    'apk_package' => 'uk.lp0.ha',
    'max_apk_mb' => 150,
    'max_screenshot_mb' => 4,

    'command_ttl_hours' => 24,          // queued commands expire after this
    'command_redeliver_seconds' => 300, // resend a delivered command if no result arrives
    'retention_days' => 90,             // bin/cron.php prunes events, audit and old commands
];
