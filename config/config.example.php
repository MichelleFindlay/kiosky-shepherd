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

    // Reverse proxies, load balancers and CDNs in front of this server, whose
    // forwarding headers (client IP, HTTPS) are believed. Single IPs, CIDR ranges and
    // two presets: 'cloudflare' (Cloudflare's ranges, kept current by cron) and
    // 'private' (loopback and private networks: nginx/Caddy/Traefik on the same
    // machine, in Docker or on the LAN). Leave empty when visitors connect directly.
    // Server → Network in the console shows what the server sees and warns if this
    // looks wrong. Examples:
    //   ['cloudflare']                     Cloudflare straight to this server
    //   ['cloudflare', 'private']          Cloudflare → nginx on this machine → PHP
    //   ['10.0.0.5', '192.0.2.0/24']       your own load balancers
    'trusted_proxies' => [],

    // Which header holds the visitor's IP. 'auto' uses CF-Connecting-IP from
    // Cloudflare, then Forwarded / X-Forwarded-For / X-Real-IP. Or force one of:
    // 'x-forwarded-for', 'forwarded', 'cf-connecting-ip', 'true-client-ip' (Akamai,
    // Cloudflare Enterprise), 'x-real-ip', 'fastly-client-ip', 'x-azure-clientip'.
    'client_ip_header' => 'auto',

    // ---- Single sign-on (optional) -------------------------------------------
    // Console users can sign in with Microsoft Entra ID or any OpenID Connect
    // provider (Okta, Google Workspace, Keycloak, Authentik, Auth0…). Register
    // https://<base_url>/sso/<id>/callback as the redirect URI, where <id> is the
    // key below. Users are created on their first sign-in. See README.md.
    //
    // Roles: list claim values per role in 'role_map'. A user gets the highest
    // role whose values appear in 'role_claim'; with no match they get
    // 'default_role', and with null they're refused. When 'role_map' is set, the
    // provider sets the role at every sign-in and the console can't change it.
    'sso' => [
        // 'entra' => [
        //     'type' => 'entra',
        //     'label' => 'Sign in with Microsoft',
        //     'tenant' => '00000000-0000-0000-0000-000000000000', // Directory (tenant) ID
        //     'client_id' => '00000000-0000-0000-0000-000000000000', // Application (client) ID
        //     'client_secret' => '',
        //     // App roles (recommended). Or 'groups' with group object IDs as the values.
        //     'role_claim' => 'roles',
        //     'role_map' => [
        //         'admin' => ['Shepherd.Admin'],
        //         'operator' => ['Shepherd.Operator'],
        //         'viewer' => ['Shepherd.Viewer'],
        //     ],
        //     'default_role' => null,
        // ],
        // 'okta' => [
        //     'type' => 'oidc',
        //     'label' => 'Sign in with Okta',
        //     'issuer' => 'https://example.okta.com', // must match the provider's discovery "issuer"
        //     'client_id' => '',
        //     'client_secret' => '',
        //     'scopes' => ['openid', 'profile', 'email', 'groups'],
        //     'role_claim' => 'groups', // dotted paths work too, e.g. 'realm_access.roles' for Keycloak
        //     'role_map' => [
        //         'admin' => ['kiosk-admins'],
        //         'operator' => ['kiosk-operators'],
        //         'viewer' => ['kiosk-viewers'],
        //     ],
        //     'default_role' => null,
        //     // Optional: 'username_claim' => 'preferred_username', 'userinfo' => true,
        //     // 'token_auth' => 'client_secret_basic' | 'client_secret_post',
        //     // 'prompt' => 'select_account', 'disabled' => false,
        // ],
    ],

    // Apply database updates automatically on the first request after you upload
    // a new version. Set false to apply them yourself with the console's Update
    // database button or php bin/migrate.php (e.g. to take a backup first).
    'auto_migrate' => true,

    // Set false to allow only single sign-on in the console. Keep at least one
    // working sign-in method: API tokens keep working either way.
    'password_login' => true,

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

    // Default time zone for new scheduled commands, e.g. 'Europe/London'
    'timezone' => 'UTC',

    // While someone has a device's page open, it is asked to check in this often (15–60 s)
    'fast_checkin_seconds' => 15,

    // ---- Updating Kiosky Shepherd from the console ---------------------------
    // Admins see new releases from GitHub under Server, with an Install button.
    // PHP needs write access to the project files, plus the curl and zip extensions.
    'self_update' => [
        'enabled' => true,       // false: only report new versions, never install from the console
        'channel' => 'stable',   // 'stable' follows the main branch, 'dev' the dev branch
        'auto' => false,         // true: bin/cron.php installs the newest build on that branch by itself
        // 'branches' => ['stable' => 'main', 'dev' => 'dev'],
        // 'repo' => 'MichelleFindlay/kiosky-shepherd',
        // 'github_token' => '', // only if GitHub rate-limits your server's IP address
    ],

    // ---- Outgoing email ------------------------------------------------------
    // Alert emails go through PHP mail() unless you set an SMTP server here. Server
    // → Email in the console sends a test. Ports: 587 with 'starttls', 465 with 'tls'.
    // These can only be set here (not in the console): whoever controls the SMTP
    // host would receive the password.
    'mail' => [
        'transport' => 'mail',            // 'mail' (PHP mail()) or 'smtp'
        // 'host' => 'smtp.example.com',
        // 'port' => 587,
        // 'encryption' => 'starttls',    // 'starttls', 'tls' (implicit, port 465) or 'none'
        // 'username' => 'kiosks@example.com',
        // 'password' => '',
        // 'from' => 'kiosks@example.com', // must usually be an address the SMTP account may send as
        // 'from_name' => 'Kiosky Shepherd',
        // 'timeout' => 15,
        // 'verify_peer' => true,         // false only for a server with a self-signed certificate
    ],

    // ---- Offline alerts (optional) -------------------------------------------
    // bin/cron.php sends one message when devices go offline and one when they
    // come back. Use any mix of email and webhooks; the Overview page has a
    // "Send test alert" button. Devices with an ignored tag are never reported.
    'alerts' => [
        'offline_minutes' => 15,          // how long without a check-in counts as offline
        'ignore_tags' => ['no-alerts'],
        // 'email' => ['it@example.com'], // sent by SMTP (see 'mail' above) or PHP mail()
        // 'email_from' => 'kiosks@example.com',
        'webhooks' => [
            // ['type' => 'slack',   'url' => 'https://hooks.slack.com/services/…'], // also Mattermost, Rocket.Chat
            // ['type' => 'teams',   'url' => 'https://…logic.azure.com/…'],        // Teams Workflows webhook
            // ['type' => 'discord', 'url' => 'https://discord.com/api/webhooks/…'],
            // ['type' => 'ntfy',    'url' => 'https://ntfy.sh/your-secret-topic'],
            // ['type' => 'json',    'url' => 'https://example.com/hook', 'headers' => ['Authorization' => 'Bearer …']],
        ],
    ],
];
