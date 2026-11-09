<p align="center"><img src="admin/img/logo-256.png" alt="Kiosky Shepherd logo" width="160" height="160"></p>

# Kiosky Shepherd

A management server for [Kiosky](https://github.com/MichelleFindlay/kiosky) wall
tablets, in plain PHP and MySQL. Tablets enroll with a code or QR code. After
that, their settings come only from the server and the local controls are
locked down.

- **Enrollment** with expiring, limited-use codes, shown as QR codes or typed by
  hand, that can tag new devices and fill in their notes
- **Profiles**: named policies (start page, allowed sites, toolbar lockdown,
  technician PIN, boot, screen, orientation, updates…) with per-device overrides
- **Remote commands**: reload, open a page, restart, clear cache or sign-out
  data, show a message, identify, screenshot, check for updates. Send them to
  one device, a selection, a profile, a tag or everything, or **schedule** them
  (e.g. reload the lobby tablets every day at 05:00, or switch them to a Night
  profile at 22:00)
- **Tags** for grouping devices across profiles, with filtering and bulk tagging
- **Managed updates**: upload Kiosky APKs, roll out "latest" or pin a version per
  profile, tag ("canary" first, then everyone) or device, with a **dev channel**
  for test builds. The server reads each APK's real version and package name.
- **Monitoring**: online status, current page, permissions, versions, event log,
  a screenshot gallery, **live view**, daily **health history** (uptime, battery,
  Wi-Fi) with charts, and **alerts** (offline, page errors, low battery,
  escapable lockdown…) by email, Slack, Teams, Discord, ntfy, any webhook or browser
  push, with quiet hours and maintenance silences
- **Operations**: database backups you can download, a weekly fleet report email,
  CSV exports of devices and the audit log, outgoing event webhooks and a
  Prometheus `/metrics` endpoint
- **Web console** with viewer / operator / admin roles, **two-factor sign-in**
  (authenticator app or **passkeys**, optionally required), session management,
  **API tokens** for scripts, and a searchable **audit log**. It installs as an
  app on phones and desktops.
- **Profile history** with what changed in each save and one-click restore, and
  **export and import** as JSON for backups or copying setups between servers
- **Users limited to some devices** (by tag), e.g. one building's site manager
- **Home Assistant integration**: tablets as devices with sensors and buttons, and
  services such as "show a message on the hallway tablet"
- **Single sign-on** with Microsoft Entra ID or any OpenID Connect provider,
  with roles mapped from groups or app roles

The Kiosky app needs to implement the client side, which is specified in
[docs/PROTOCOL.md](docs/PROTOCOL.md). Kiosky 1.1.0 and later can enroll; the
**Lock the tablet to Kiosky** setting needs Kiosky 1.4.0 or later. The screen
schedule and dimming, going back to the start page when idle, the frozen-page
watchdog, camera and microphone access, battery care and the **Wake the screen**
command need Kiosky 1.7.0 or later.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `json`, and preferably `zip` (to read
  version info from uploaded APKs). Single sign-on also needs `openssl`, plus
  `curl` or `allow_url_fopen`.
- MySQL 8.0+ or MariaDB 10.5+
- Apache with `.htaccess` (`mod_rewrite`; `mod_headers` recommended), which is what
  most cPanel / shared hosts use. nginx works too; see below.
- HTTPS, because Kiosky refuses plain http

## Install on a subdomain (shared hosting / cPanel)

The whole project goes into the subdomain's document root, e.g.
`/home/you/kiosks.example.com/` or `public_html/kiosks/`. Only `index.php` and
`admin/` are served. `.htaccess` files deny web access to `src/`, `config/`,
`storage/`, `sql/`, `bin/` and `docs/`: the root `.htaccess` blocks them, and
each folder also has its own deny-all file.

1. **Upload** every file and folder, including the dotfiles `.htaccess` and
   `.user.ini`, into the subdomain's folder.
2. **Create a database** and user in the control panel (MySQL Databases), and
   give the user all privileges on it.
3. **Configure.** Copy `config/config.example.php` to `config/config.php` and set:
   - `db`: the database name, user and password
   - `base_url`: `https://kiosks.example.com`
   - `organisation`: your name, shown on the tablets
   - `setup_key`: a long random string, at least 16 characters
4. **Install from the browser.** Open `https://kiosks.example.com/`. The console
   asks for the setup key and a username and password, then creates the
   database tables and your admin account. Afterwards **remove `setup_key` from
   the config**. (With SSH you can instead run `php bin/migrate.php` and
   `php bin/create-admin.php admin`, or import `sql/migrations/001_initial.sql`
   in phpMyAdmin.)
5. **Make `storage/` writable** by PHP (usually it already is). It holds uploaded
   APKs and screenshots. If the host allows it, set `storage_dir` to a folder
   outside the web root.
6. **Add a cron job** (cPanel → Cron Jobs), every 10 minutes:
   `php /home/you/kiosks.example.com/bin/cron.php`
   It runs scheduled commands, sends offline alerts, expires old commands and
   prunes events past `retention_days`. The console's sidebar and Overview page
   show when it last ran, and flag it after 30 minutes.
7. **Check the lockdown.** Each of these must return **403**:
   `https://kiosks.example.com/config/config.php`,
   `https://kiosks.example.com/src/Db.php`,
   `https://kiosks.example.com/sql/migrations/001_initial.sql`,
   `https://kiosks.example.com/tests/smoke.php`,
   `https://kiosks.example.com/custom_components/kiosky_shepherd/api.py`.
   If any returns 200 or downloads, your host is ignoring `.htaccess`, and
   you must not continue until that's fixed.

APK uploads need large PHP limits. The included `.htaccess` and `.user.ini` ask
for 150 MB. If uploads fail, raise `upload_max_filesize` and `post_max_size` in
the host's PHP settings.

### nginx

```nginx
server {
    server_name kiosks.example.com;
    root /srv/kiosky-shepherd;
    index index.php;

    location ~ ^/(src|config|storage|sql|bin|docs|tests|custom_components)(/|$) { deny all; return 404; }
    location ~ /\.(?!well-known/) { deny all; return 404; }
    location ~ \.(md|sql|dist|neon|py)$ { deny all; return 404; }
    location = /hacs.json { deny all; return 404; }

    location /admin/ {
        try_files $uri $uri/ =404;
        location ~ \.(html|js|css)$ { add_header Cache-Control "no-cache"; try_files $uri =404; }
    }
    location / { try_files $uri /index.php$is_args$args; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
    client_max_body_size 160m;
}
```

Behind a reverse proxy, load balancer or CDN, see
[Reverse proxies and Cloudflare](#reverse-proxies-and-cloudflare).

## Reverse proxies and Cloudflare

When something sits in front of Kiosky Shepherd (Cloudflare, nginx, Caddy, Traefik,
HAProxy, a cloud load balancer…), PHP sees the proxy's address instead of the
visitor's, and may think HTTPS is plain HTTP. Tell it which proxies to believe in
`config/config.php`:

```php
'trusted_proxies' => ['cloudflare'],            // Cloudflare straight to this server
'trusted_proxies' => ['cloudflare', 'private'], // Cloudflare → nginx on this machine → PHP
'trusted_proxies' => ['private'],               // nginx / Caddy / Traefik / Docker in front
'trusted_proxies' => ['10.0.0.5', '192.0.2.0/24', '2001:db8::/48'], // your own load balancers
```

Entries are single IPs, CIDR ranges (IPv4 or IPv6), or the presets `cloudflare`
(Cloudflare's published ranges, built in and refreshed weekly by cron) and
`private` (loopback, private and link-local networks). Forwarding headers are
ignored from anyone else, so they can't be faked.

The visitor's address comes from `CF-Connecting-IP` when Cloudflare connects
directly, otherwise from the standard `Forwarded` or `X-Forwarded-For` chain
(skipping every trusted hop, so chains like Cloudflare → nginx work), then
`X-Real-IP`. Set `client_ip_header` to force one: `true-client-ip` (Akamai,
Cloudflare Enterprise), `fastly-client-ip`, `x-azure-clientip`, `x-real-ip`,
`x-forwarded-for`, `forwarded` or `cf-connecting-ip`. HTTPS is recognised from
`X-Forwarded-Proto`, `Forwarded: proto=`, Cloudflare's `CF-Visitor` and
`X-Forwarded-Ssl`.

**Server → Network** in the console shows the address and HTTPS state the server
sees for you, and warns when a proxy is in front but not trusted (then every
visitor looks like the proxy: rate limits, the audit log and device IPs are wrong).

With Cloudflare, also:

- use SSL/TLS mode **Full (strict)** with a valid certificate on the server;
- turn off **Rocket Loader** and **Email Address Obfuscation** for the site: they
  inject scripts that the console's security policy blocks;
- set **Browser Cache TTL** to "Respect Existing Headers", and don't add cache rules
  for `/api/` (responses are `no-store`) or `/admin/` (`no-cache`).
- Tablets work through Cloudflare unchanged; if Bot Fight Mode or a WAF rule
  challenges them, skip it for `/api/v1/device/`.

### Upgrading

From 1.5.0 on, admins can update from the console: **Server** lists the newest
release from GitHub with its notes and an **Install** button (see
[Updating from the console](#updating-from-the-console)). Otherwise, or to reach 1.5.0:

Upload the new files over the old ones (keep `config/config.php` and `storage/`).
If the new version changes the database, the first request afterwards (a console
page, a tablet check-in or cron) applies the update automatically and records it
in the audit log. Take a database backup before uploading if you want one.

To apply updates yourself instead, set `'auto_migrate' => false`. Admins then see
an **Update database** banner in the console; click it. With SSH you can run
`php bin/migrate.php` instead, or import the new files from `sql/migrations/` in
phpMyAdmin. The banner also appears if an automatic update fails; the reason is in
the PHP error log, and it isn't retried automatically until you upload new files.

**1.6.0** adds **Server → Settings** (see [Server settings in the console](#server-settings-in-the-console)).
There is no database change. PHP needs write access to `storage/` to save settings there.
It also adds CIDR ranges and the `cloudflare` / `private` presets to `trusted_proxies`,
`client_ip_header`, and **Server → Network** (see [Reverse proxies and Cloudflare](#reverse-proxies-and-cloudflare)).

**1.7.0** adds `sql/migrations/006_operations.sql` (applied automatically),
`admin/sw.js`, `admin/manifest.webmanifest`, `src/Api/ProvisioningApi.php` and
`blueprints/`. With alerts set up, page errors, escapable lockdown, low battery and
hot or failing batteries are now reported too; untick them under
Server → Settings → Alerts if you only want offline alerts. Uploading files by
hand: copy every file, as usual, keeping `config/config.php` and `storage/`.

**1.5.0** adds `sql/migrations/005_release_channels.sql` (applied automatically)
and the **Release channel** policy setting, so device policies change once more.
Existing releases become stable.

**1.4.0** adds `sql/migrations/004_insights.sql` (applied automatically) and a
`custom_components/` folder with its own `.htaccess`; upload both. It also adds
four "Screen schedule" policy settings, so every device's policy version changes
once and shows **Pending** until its next check-in.

**1.3.0** adds `sql/migrations/003_fleet.sql`. Until it's applied, everything
from 1.2.0 keeps working, but tags, schedules, two-factor setup, fast check-ins
and alerts are unavailable (they answer "needs the latest database update"). Also upload the new
`tests/.htaccess`, and copy the new `alerts`, `timezone` and
`fast_checkin_seconds` settings from `config/config.example.php` if you want them.

## Local development

```sh
cp config/config.example.php config/config.php   # set db + 'cookie_secure' => false
php bin/migrate.php
php bin/create-admin.php admin
php -S localhost:8080 index.php
```

Then open <http://localhost:8080/>. The built-in server only passes `/admin/`
files through; everything else goes to `index.php`.

### Tests

GitHub Actions ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs on every
push: `php -l` on PHP 8.1 and 8.4, `node --check` on the console, PHPStan
([phpstan.neon.dist](phpstan.neon.dist)), and an end-to-end smoke test against MySQL 8.0 and
MariaDB 10.6. The smoke test ([tests/smoke.php](tests/smoke.php)) enrolls a fake device and
works through check-ins, commands, tags, schedules and profile switches, profile
export, import, history and restore, release rings, health history, live view,
scoped users, two-factor sign-in, rate limits and retirement, then runs
`bin/cron.php`. The Home Assistant integration is imported against a real Home
Assistant install and checked with `hassfest`. To run it
locally against a development server (never production; it enrolls a device):

```sh
php tests/smoke.php http://localhost:8080 admin 'your-password'
```

## How lockdown works

Every policy setting has a built-in default. A **profile** can set any of them,
and a **device override** beats the profile. The device receives the merged
result with a version hash, applies it, and reports that version back, so the
console shows whether each tablet is up to date.

While managed, the app treats its local settings (start page, start on boot,
update checks) as read-only. The toolbar is hidden or limited according to the
policy. A technician PIN can unlock the full toolbar on the spot. A device
leaves management only when an admin **retires** it: the server then answers
`410 Gone` and the app returns to normal unmanaged Kiosky. Lost connections or
bad credentials never unlock it.

Without Android device-owner provisioning, no app can hide the system bars or
the Home button. Combine Shepherd with Android's app pinning for wall panels.

## Roles

| Role | Can |
| --- | --- |
| viewer | See everything except users and the audit log |
| operator | Also rename and tag devices, assign profiles, set device overrides, send and schedule commands, create and revoke enrollment codes, retire devices |
| admin | Also edit and restore profiles, upload releases and set release rings, manage users, read the audit log, delete retired devices |

### Users limited to some devices

An admin can limit an operator or viewer to devices carrying certain tags
(**Users → Devices…**), for example `building-a`. They then see only those devices,
their commands and screenshots everywhere in the console and API (their API tokens
too); "all devices" in a bulk command means all of *their* devices. They can't see
enrollment codes or schedules, which act on the whole fleet, and they can't remove
their own tags from a device. Admins always see everything.

## Two-factor sign-in, passkeys and sessions

Password users can add a second step under **Your account**:

- **An authenticator app** (Microsoft Authenticator, Google Authenticator,
  1Password, Bitwarden…). Turning it on shows ten single-use recovery codes.
- **Passkeys**: fingerprint, face or device PIN on this device, a phone or a
  security key. A passkey works as the second step after the password, or as the
  whole sign-in (**Sign in with a passkey** on the sign-in page). Passkeys are tied
  to the host in `base_url`, so open the console at that address.

**Require two-factor sign-in** (Server → Settings) makes password users without
either set one up before they can do anything else. Single sign-on users get their
provider's MFA instead, and API tokens are not affected.

**Sessions** under Your account lists your signed-in browsers and signs out any of
them, or all but this one. Changing your password signs out every other browser, as
do an admin resetting your password, disabling you or resetting your 2FA. Tick
**Email me when my account signs in from a new browser** (with your email address
set) to hear about sign-ins from browsers you haven't used before.

Locked out?

- Another admin can click **Reset 2FA** for you under **Users** (this also removes
  your passkeys).
- With SSH: `php bin/create-admin.php <you> admin` sets a new password and turns
  two-factor sign-in off.
- With only phpMyAdmin: `UPDATE admins SET totp_secret = NULL, totp_last_step = NULL, totp_recovery = NULL WHERE username = 'you';`
  and `DELETE FROM webauthn_credentials WHERE admin_id = <your id>;`

## Alerts

Set up the channels under **Server → Settings → Alerts** (or `alerts` in
`config/config.php`): email addresses, webhooks, and **browser notifications**
(Your account → Notifications on this device). **Send test alert** on the Overview
tries them all.

Every cron run reports, in one message each:

- devices that haven't checked in for `offline_minutes` (default 15, never less
  than 3 check-in intervals), and again when they come back;
- the problems ticked under **Also alert about**: page errors, lockdown that can be
  escaped, low battery (below the threshold and not charging), a missing
  permission, policy not applied, low uptime. Each is reported once it has lasted
  two cron runs (so flickers don't alert) and again when it clears.

**Quiet hours** hold alerts back (e.g. 22:00 to 07:00); whatever is still wrong when
they end is reported then. **Silences** pause alerts for one device (its page →
Alerts), a tag or everything (Overview → Server) for an hour up to a week, for
maintenance. Devices that never checked in, retired devices and devices tagged
`no-alerts` (`ignore_tags`) are never reported.

| `type` | Sends |
| --- | --- |
| `slack` | `{"text": …}`, which Slack, Mattermost and Rocket.Chat incoming webhooks accept |
| `teams` | An Adaptive Card, for a Teams **Workflows** webhook ("Post to a channel when a webhook request is received") |
| `discord` | `{"content": …}` |
| `ntfy` | Plain text with a title, to an [ntfy](https://ntfy.sh) topic URL |
| `json` | `{"event": "devices_offline" \| "devices_online" \| "device_problems" \| "device_problems_resolved" \| "test", "title", "text", "devices": [{id, name, last_seen_at}], "console", "server", "sent_at"}` |

Each webhook can add `'headers' => ['Authorization' => '…']` in config.php.

**Browser notifications** need HTTPS. They go to users who see the whole fleet
(not users limited to some devices). On iPhone and iPad, add the console to the
Home Screen first (Share → Add to Home Screen), then turn notifications on there.

### Email and SMTP

Alert emails go through PHP's `mail()` unless you configure an SMTP server under
`mail` in `config/config.php`:

```php
'mail' => [
    'transport' => 'smtp',
    'host' => 'smtp.example.com',
    'port' => 587,
    'encryption' => 'starttls',   // or 'tls' for port 465, 'none' for a local relay
    'username' => 'kiosks@example.com',
    'password' => '…',
    'from' => 'kiosks@example.com',
],
```

This works with Microsoft 365 / Exchange Online (`smtp.office365.com`, 587, with
SMTP AUTH enabled for the mailbox), Google Workspace (`smtp.gmail.com`, 587, with an
app password), Amazon SES, SendGrid, Mailgun, Postmark, your host's mail server and
most others. Certificates are checked (`verify_peer`), and a password is never sent
over an unencrypted connection except to this machine. **Server → Email** shows
how mail is sent and sends a test message. The SMTP settings can only be set in
config.php, because whoever controls the SMTP host would receive the password.

## Operations

- **Database backups** (Server → Database backups): **Back up now** writes the
  whole database as gzipped SQL into `storage/backups/` and lets you download it;
  turn on **Back up the database every night** (after 03:00) under Settings and
  choose how many to keep. Restore by importing the file in phpMyAdmin, or
  `gunzip -c db-….sql.gz | mysql <database>`. Backups contain password hashes and
  two-factor secrets, so keep downloaded copies safe.
- **Weekly report**: turn on **Email a weekly fleet report** under Settings for a
  Monday-morning email (offline devices, least reliable tablets, versions, failed
  commands, changes and failed sign-ins). Server → Reports and integrations
  previews it and sends it on demand.
- **CSV exports**: Devices → **Export CSV** (with the current filters; name, IDs,
  profile, tags, model, versions, IP, online, 7-day uptime, battery, Wi-Fi, page,
  last seen, notes), and Audit log → **Export CSV** with its filters (who, action,
  free text, dates).
- **Enrollment codes** can add tags and notes to every device that enrolls with
  them, so new tablets arrive ready for schedules, release rings and scoped users.
- **Diagnostics**: the **Collect diagnostics (logs)** command asks a tablet for its
  recent logs and WebView errors, downloadable from the command list (needs a
  Kiosky version that supports it, see the protocol).

### Event webhooks

Under **Server → Settings → Integrations**, add URLs that receive a JSON POST when
something happens: `device.enrolled`, `device.retired`, `device.offline`,
`device.online`, `device.problem`, `device.problem_resolved`,
`device.profile_changed`, `command.failed`, `profile.updated` (all, or the ones
you tick). The body is `{"event", "at", "server", "data": {…}}`. With a secret,
each request has `X-Shepherd-Signature: sha256=<hex HMAC-SHA256 of the body>`; check
it before trusting the request. Events are sent straight after the request that
caused them and retried with backoff for a few hours; Server → Reports and
integrations shows recent deliveries and sends a test. Good targets: Home
Assistant webhook automations, n8n, Zapier, or a ticketing system.

### Prometheus

`GET /metrics` serves Prometheus metrics to an API token (viewer is enough):

```yaml
scrape_configs:
  - job_name: kiosky
    scheme: https
    metrics_path: /metrics
    bearer_token: shp_…
    static_configs:
      - targets: ['kiosks.example.com']
```

Per device (labelled `uid`, `name`): `kiosky_device_up`,
`kiosky_device_last_checkin_age_seconds`, `kiosky_device_battery_percent`,
`kiosky_device_charging`, `kiosky_device_wifi_rssi_dbm`,
`kiosky_device_uptime_7d_ratio`, `kiosky_device_policy_applied` and
`kiosky_device_info` (profile, version, model, tags). Fleet-wide: `kiosky_devices`,
`kiosky_devices_online`, `kiosky_commands_pending`,
`kiosky_cron_last_run_timestamp_seconds` and `kiosky_shepherd_info`. A token of a
user limited to some devices only sees those.

## Page playlist

The **Page playlist** policy settings rotate a tablet between several pages (say a
dashboard, cameras and a calendar), each for a set time, pausing after a touch. They
need Kiosky 1.7.0 or later ([docs/PROTOCOL.md](docs/PROTOCOL.md) §5.2); older versions ignore
them.

## Setting up tablets with a QR code (no computer)

Each enrollment code has a **Provisioning QR**. Factory-reset a tablet, tap six
times in the same spot on its first setup screen to open the QR scanner, and scan
it. The tablet joins the Wi-Fi you typed (the password stays in your browser),
downloads Kiosky from this server, makes it the **device owner** (so full lockdown,
silent updates and scheduled reboots work) and enrolls with the code by itself.

It needs Kiosky 1.7.0 or later uploaded under **Releases** as a stable release, or
`provisioning.apk_url` in `config.php` pointing at a Kiosky APK (Android 9+
tablets only; builds signed with your own key also need
`provisioning.signature_checksum`, see [docs/PROTOCOL.md](docs/PROTOCOL.md) §3.3). The code must still be active when the
tablet downloads Kiosky and enrolls.

## Scheduled commands

**Schedules** queues a command on chosen weekdays at a time in a chosen time zone
(daylight saving is handled), for all devices, a profile or a tag. `bin/cron.php`
runs them, so they go out up to one cron interval after the set time; a run
missed by more than an hour (cron wasn't running) is skipped rather than sent
late. **Run now** queues one immediately. Commands from a schedule show its name
under **Sent by**.

### Switching profiles on a schedule

A schedule can also **Switch to profile**: it moves its devices to another
profile, and they apply that profile's policy at their next check-in. Two
schedules on a tag, e.g. "lobby → Night at 22:00" and "lobby → Day at 07:00", give
a night-time dashboard or a different start page out of hours, without any app
support. Each move is in the device's event log.

## Profile history

Every save of a profile is kept (the last 200 per profile). The profile page lists
them with who saved them and exactly what changed; **Restore** puts the profile
back as it was, recorded as a new version, so a restore can itself be undone.
Technician PINs show only as "set". Settings this server no longer knows are
dropped on restore, and a pinned release must still be uploaded.

## Staged rollouts

Under **Releases → Staged rollouts**, point a tag at a release ("latest" or a
version), e.g. tag three tablets `canary` and give the canary ring a new APK. Those
devices get it at their next check-in while the rest follow their profile; the
ring list shows how many are on it, and the release table how many devices each
release is targeted at. When it looks good, move the profiles over and remove the
ring. A device's own `updates.release` override still wins, and a device in
several rings follows the newest release.

## Server settings in the console

**Server → Settings** lets admins change everyday settings without editing files:
organisation, time zone, automatic database updates, session idle
time, command and check-in timings, upload limits, retention, offline alerts
(emails, webhooks, ignored tags, threshold) and automatic Kiosky Shepherd updates.

They're saved in `storage/settings.json` and applied on top of
`config/config.php`, which is never rewritten. Each setting shows its config.php
value and has **Reset**. Changes take effect on the next request and are recorded
in the audit log.

For safety, these stay in config.php only: the server address (`base_url`: single
sign-on callbacks are built from it, and enrolled tablets keep the address they
enrolled with), the database, single sign-on, `setup_key`,
password sign-in, trusted proxies, session cookie settings, the storage folder, the
Kiosky package name, and where updates come from (and whether the console may
install them). Webhooks that need extra headers are also set in config.php; the
console keeps their headers as long as the address isn't changed.

## Updating from the console

Kiosky Shepherd can update itself from GitHub, following one of two channels:

| Channel | Follows | For |
| --- | --- | --- |
| `stable` (default) | the newest commit on `main` | production servers |
| `dev` | the newest commit on `dev` | trying changes before they reach main |

Choose the channel and whether to install automatically under **Server →
Settings**. Cron checks every 6 hours; admins then see an "update available" banner,
and **Server** shows the installed build, the newest one on the branch, the commits
in between (and the release notes when that version has a GitHub release), and
**Install**. With **Install new releases automatically** on, cron installs each new
build on the branch by itself, so a server on `dev` stays on the newest dev build.
The exact commit installed is recorded, so dev builds that keep the same version
number are still picked up. Switching from dev back to an older stable version is
offered but never installed automatically.

Installing:

1. downloads that commit's archive from the configured repository on GitHub
   (HTTPS, GitHub's hosts only);
2. checks it: no unsafe paths, the expected files present, and `src/bootstrap.php`
   really is the version GitHub reported for that commit;
3. zips the current files into `storage/backups/` (the last five are kept);
4. writes the new files one at a time (each written aside, then renamed into
   place), leaving `config/config.php`, `storage/` and `.user.ini` alone. If writing
   fails part-way, the backup is put straight back;
5. clears PHP's opcode cache. Database updates apply on the next page load.

**Roll back** under **Server → Backups** restores an earlier build's files
(database changes stay; older versions ignore what they don't know).

It needs PHP's `curl` and `zip` extensions and write access to the project files,
which most cPanel hosts give PHP. **Server** says plainly what's missing. Settings,
under `self_update` in `config/config.php`:

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | `false` only reports new builds; the Install button is hidden (file only) |
| `channel` | `stable` | `stable` or `dev` (also in **Server → Settings**) |
| `auto` | `false` | `true` lets `bin/cron.php` install new builds by itself (also in **Server → Settings**) |
| `branches` | `['stable' => 'main', 'dev' => 'dev']` | the branch each channel follows (file only) |
| `repo` | `MichelleFindlay/kiosky-shepherd` | where builds come from (file only) |
| `github_token` | | only if GitHub rate-limits your server's IP address, or for a private repository |

Copies installed with git (a `.git` folder) refuse, and should use `git pull`.

## Release channels

Every uploaded APK is either **stable** or **dev** (chosen when uploading, and
changed later with **Promote to stable** / **Move to dev**). The **Release channel**
policy setting (`updates.channel`) decides what "latest" means for a device:

- `stable` (default): the newest stable release.
- `dev`: the newest release of either kind, so these tablets get test builds first.
  A stable release newer than the last dev build still wins.

Set it on a test profile, or as an override on a few devices. A pinned
`updates.release` version is installed whatever its channel, and release rings
pointing at "latest" follow each device's channel. On the `github` update source,
`dev` asks Kiosky to include GitHub pre-releases (needs app support, see
[docs/PROTOCOL.md](docs/PROTOCOL.md) §6). A typical flow: upload as dev, watch the dev tablets
update and behave, then promote it.

## Health history

At each check-in the server adds to a small daily summary per device: how long it
was online, its lowest and highest battery, Wi-Fi signal and page errors (days run
midnight to midnight in `timezone`). The device page charts the last 30 days, the
device list shows the share of the last 7 days each device was online, and the
Overview flags devices below 90%. Summaries are kept for a year.

## Live view

**Live view** on a device page streams the tablet's screen every 2 seconds for as
long as the page is open. It needs Kiosky 1.7.0 or later; older versions report
"unsupported command", and **Take screenshot** still works. Only the latest frame is
kept. **Collect diagnostics** (also 1.7.0+) fetches a log bundle with versions, the
status report, page console errors and the last crash.

## Screen schedule

The **Screen schedule** policy settings turn the screen off at night (e.g. 22:00
to 07:00, in the tablet's own time), with touch or motion to wake, and can dim it
for part of the day or when idle. **Screensaver and sound** add a clock screensaver
(or a page of your own), a volume and quiet hours. They need Kiosky 1.7.0 or later
(§5.2 of the protocol); older versions ignore them.

## Home Assistant

The `custom_components/kiosky_shepherd` folder is a Home Assistant integration.
Install it with [HACS](https://hacs.xyz) (add this repository as a custom
repository, type Integration) or copy the folder into Home Assistant's
`config/custom_components/`, restart, and add **Kiosky Shepherd** under
**Settings → Devices & services**. It asks for the server address and an API token
(**API tokens** in the console): viewer is enough for sensors, operator for
buttons and services. A token limited to some devices (see above) only brings in
those.

Each tablet becomes a device with:

- **Sensors**: Online, Battery, Charging, Screen on, Screen (normal, dim or off),
  Current page, Profile, Kiosky version, Last seen, Online last 7 days, Policy
  applied, Battery temperature and Wi-Fi signal (both off by default)
- **Buttons**: Reload page, Back to start page, Wake screen, Identify, Take
  screenshot, Clear cache, Restart Kiosky, Reboot tablet (off by default; device
  owner only)
- **Services**: `kiosky_shepherd.show_message`, `kiosky_shepherd.open_page` and
  `kiosky_shepherd.send_command`, targeting tablets by device

It polls every 30 seconds, and tablets enrolled later appear on their own. Commands
reach a tablet at its next check-in, so set a short check-in interval on profiles
used for instant reactions. For example, when the doorbell rings:

```yaml
automation:
  - alias: Doorbell on the hallway tablet
    triggers:
      - trigger: state
        entity_id: binary_sensor.front_door_ding
        to: "on"
    actions:
      - action: kiosky_shepherd.show_message
        data:
          device_id: <the hallway tablet's device id>
          message: Someone is at the front door
          seconds: 60
```

### Battery care blueprint

Tablets kept at 100% on charge wear out and can swell. If the charger is on a
smart plug, import
[blueprints/automation/kiosky_shepherd/battery_charge_plug.yaml](blueprints/automation/kiosky_shepherd/battery_charge_plug.yaml)
(**Settings → Automations → Blueprints → Import**, with the file's GitHub URL). It
keeps the battery between two levels (30–80% by default) and cuts charging when
the battery runs hot. It works on any tablet, unlike Kiosky's own charging limit,
which only works on some Samsung models.

## Single sign-on

Console users can sign in with **Microsoft Entra ID** or any **OpenID Connect**
provider (Okta, Google Workspace, Keycloak, Authentik, Auth0…). Add providers
under `sso` in `config/config.php`. Each one gets a button on the sign-in page.
The redirect URI to register with the provider is

    https://kiosks.example.com/sso/<id>/callback

where `<id>` is the provider's key in the config, for example `entra`.

A user's console account is created the first time they sign in. Accounts are
matched by provider and the token's `sub` claim, never by username, so a single
sign-on user can't take over an existing password user. If the name is taken,
the new account's username gets a `-2` suffix.

### Role mapping

- `role_claim` names the claim to read: `roles`, `groups`, or a dotted path
  such as `realm_access.roles`.
- `role_map` lists the claim values that grant each role. The user gets the
  highest role that matches.
- `default_role` is used when nothing matches. Leave it `null` to refuse
  those users.

With a `role_map`, the identity provider owns roles. The role is applied again
at every sign-in, and the console shows it but can't change it. Without one,
new users get `default_role`, and admins change it in the console. Disabling a
user in the console always blocks them, whatever the provider says.

Changes in the identity provider take effect at the user's next sign-in. A
console session that's already open keeps its role until it ends or goes idle
(`session_lifetime`). API tokens keep working until they're revoked or the user
is disabled. To cut someone off at once, **Disable** them under **Users**.

### Microsoft Entra ID

1. In the Entra admin center, go to **App registrations → New registration**.
   Choose *Accounts in this organizational directory only*. Under **Redirect
   URI**, pick *Web* and enter `https://kiosks.example.com/sso/entra/callback`.
2. Under **Certificates & secrets**, create a client secret. Copy its *Value*.
3. **App roles (recommended).** Under **App roles**, create roles for *Users/Groups*
   with the values `Shepherd.Admin`, `Shepherd.Operator` and `Shepherd.Viewer`.
   Then, in **Enterprise applications → (your app) → Users and groups**, assign
   people or groups to them. To keep everyone else out, also turn on
   *Assignment required* under **Properties**.
4. Add the provider to the config:

   ```php
   'sso' => [
       'entra' => [
           'type' => 'entra',
           'tenant' => '…',        // Directory (tenant) ID
           'client_id' => '…',     // Application (client) ID
           'client_secret' => '…',
           'role_claim' => 'roles',
           'role_map' => [
               'admin' => ['Shepherd.Admin'],
               'operator' => ['Shepherd.Operator'],
               'viewer' => ['Shepherd.Viewer'],
           ],
       ],
   ],
   ```

**Using groups instead:** under **Token configuration**, choose **Add groups
claim** and select *Groups assigned to the application* (or *Security groups*).
Then set `'role_claim' => 'groups'` and list group **object IDs** in `role_map`.
Entra leaves the groups out of the token when a user is in more than 200 of
them. Such users get a "too many groups" message. Use app roles, or send only
the groups assigned to the app, to avoid this.

The tenant must be a specific directory. The `common` and `organizations`
endpoints aren't accepted.

### Other OpenID Connect providers

```php
'okta' => [
    'type' => 'oidc',
    'label' => 'Sign in with Okta',
    'issuer' => 'https://example.okta.com',
    'client_id' => '…',
    'client_secret' => '…',
    'scopes' => ['openid', 'profile', 'email', 'groups'],
    'role_claim' => 'groups',
    'role_map' => ['admin' => ['kiosk-admins'], 'operator' => ['kiosk-operators'], 'viewer' => ['kiosk-viewers']],
],
```

`issuer` must match the `issuer` in the provider's
`/.well-known/openid-configuration`. If the role claim isn't in the ID token,
Shepherd also asks the userinfo endpoint (turn this off with `'userinfo' => false`).
For Keycloak, use `'role_claim' => 'realm_access.roles'`, or add a group
mapper to the client. Other options: `username_claim` (default
`preferred_username`), `token_auth` (`client_secret_basic` or
`client_secret_post`), `prompt` (e.g. `select_account`), and `disabled`.

### Troubleshooting

Failed sign-ins appear in the **Audit log** as `admin.sso_failed`, with the
reason. For `no_role`, the entry shows the claim values the provider sent, so
you can copy them into `role_map`. Network and configuration errors also go to
the PHP error log.

Keep one password admin as a break-glass account, in case the identity
provider is unavailable. `'password_login' => false` hides the password form
and refuses password sign-ins. `bin/create-admin.php` can still add a
password user over SSH, but that user can't sign in until password sign-in is
turned back on.

## Admin API

The console uses this API, and so can scripts. Authenticate with
`Authorization: Bearer shp_…` (create tokens under **API tokens**). JSON in,
JSON out. Errors are `{"error": {"code", "message", "details"}}`.

| Method & path | Role | Purpose |
| --- | --- | --- |
| `GET /api/v1/admin/overview` | viewer | Fleet summary |
| `GET /api/v1/admin/policy/schema` | viewer | Every policy key, type, default and help text |
| `GET /api/v1/admin/devices` | viewer | `?q=&profile_id=&tag=&status=active\|retired\|all&online=1\|0` |
| `GET /api/v1/admin/devices/{id}` | viewer | One device |
| `PATCH /api/v1/admin/devices/{id}` | operator | `{name, profile_id, notes, tags: [..], overrides: {key: value\|null}, clear_overrides}` |
| `DELETE /api/v1/admin/devices/{id}` | operator | Retire (releases the tablet). `?purge=1` on a retired device deletes it (admin). |
| `GET /api/v1/admin/devices/{id}/policy` | viewer | Effective policy and where each value comes from |
| `GET /api/v1/admin/devices/{id}/events` | viewer | Event log |
| `POST /api/v1/admin/devices/{id}/commands` | operator | `{type, params, ttl_hours}` |
| `POST /api/v1/admin/devices/{id}/watch` | viewer | Ask the device to check in every `fast_checkin_seconds` for the next 2 minutes |
| `GET /api/v1/admin/devices/export` | viewer | Devices as CSV (`?status=&tag=&profile_id=`) |
| `GET /api/v1/admin/devices/{id}/history` | viewer | `?days=7–90`: daily uptime, battery, Wi-Fi and page errors |
| `GET /api/v1/admin/devices/{id}/live` | viewer | The latest live-view frame (404 when none in the last minute) |
| `GET /api/v1/admin/tags` | viewer | Tags in use, with device counts |
| `POST /api/v1/admin/device-tags` | operator | Bulk: `{device_ids, add: [..], remove: [..]}` |
| `GET /api/v1/admin/commands/types` | viewer | Command types and parameters |
| `GET /api/v1/admin/commands` | viewer | `?device_id=&status=&type=&has_attachment=1` |
| `POST /api/v1/admin/commands` | operator | Bulk: `{type, params, device_ids \| profile_id \| tag \| all: true}` |
| `DELETE /api/v1/admin/commands/{id}` | operator | Cancel a pending command |
| `GET /api/v1/admin/commands/{id}/screenshot` | viewer | Screenshot image |
| `GET/POST /api/v1/admin/profiles` | viewer / admin | List, create `{name, description, settings}` |
| `GET/PATCH/DELETE /api/v1/admin/profiles/{id}` | viewer / admin | `settings` patches merge; `null` removes a key. Delete takes `?move_to=`. |
| `GET /api/v1/admin/profiles/export`, `…/profiles/{id}/export` | admin | Download profiles as JSON (PINs only as hashes) |
| `POST /api/v1/admin/profiles/import` | admin | An export file; creates new profiles, renaming on a clash, and lists skipped unknown settings |
| `GET /api/v1/admin/profiles/{id}/versions` | viewer | Saved versions, newest first, each with its changes |
| `POST /api/v1/admin/profiles/{id}/versions/{version}/restore` | admin | Put the profile back to that version |
| `GET/POST /api/v1/admin/schedules` | viewer / operator | `{name, type, params, target: {all\|profile_id\|tag\|device_ids}, days: ["mon",…], time: "HH:MM", timezone, ttl_hours, enabled}`. `type: "set_profile"` with `params: {profile_id}` moves devices instead of sending a command. |
| `PATCH/DELETE /api/v1/admin/schedules/{id}` | operator | Any of the fields above / delete |
| `POST /api/v1/admin/schedules/{id}/run` | operator | Queue it now |
| `GET/POST /api/v1/admin/enrollment-tokens` | operator | `{profile_id, label, name_prefix, expires_hours, max_uses}` |
| `DELETE /api/v1/admin/enrollment-tokens/{id}` | operator | Revoke |
| `GET /api/v1/admin/releases` | viewer | Uploaded APKs |
| `POST /api/v1/admin/releases` | admin | multipart: `apk`, `notes`, `channel` (`stable` or `dev`) |
| `PATCH/DELETE /api/v1/admin/releases/{id}` | admin | `{active, notes, channel}` / delete |
| `GET /api/v1/admin/releases/rings` | viewer | Release rings (staged rollouts) and their progress |
| `PUT/DELETE /api/v1/admin/releases/rings/{tag}` | admin | `{release: "latest" \| "1.2.0"}` / remove |
| `GET/POST/PATCH/DELETE /api/v1/admin/users[/{id}]` | admin | Console users. `PATCH {reset_2fa: true}` turns off someone's two-factor sign-in; `scope_tags: [..] \| null` limits a user to devices with those tags. |
| `GET/POST/DELETE /api/v1/admin/tokens[/{id}]` | viewer+ | API tokens (created from the console only) |
| `GET /api/v1/admin/audit` | admin | `?before=&target_type=&target_id=&actor=&action=&q=&from=&to=` |
| `GET /api/v1/admin/audit/export` | admin | The audit log as CSV, same filters |
| `GET/POST/DELETE /api/v1/admin/alerts/silences[/{id}]` | viewer / operator | Alert silences: `{device_id \| tag \| all: true, minutes, note}` |
| `GET/POST /api/v1/admin/system/backups`, `GET/DELETE …/backups/{name}` | admin | Database backups: list, make one now, download, delete |
| `GET/POST /api/v1/admin/system/report` | admin | Weekly report preview / send now |
| `GET /api/v1/admin/events`, `POST …/events/test` | admin | Event types and recent deliveries / send a test event |
| `GET /metrics` (or `/api/v1/admin/metrics`) | viewer | Prometheus metrics |
| `GET/POST/DELETE /api/v1/admin/push`, `POST …/push/test` | console | Browser push: public key, subscribe, unsubscribe, test |
| `GET /api/v1/admin/session/sessions`, `DELETE …/sessions/{id}`, `POST …/sessions/end-others` | console | Your signed-in browsers |
| `…/session/passkeys` (GET, POST, DELETE `/{id}`, POST `/options`); `POST …/session/passkey[/options]` | console | Your passkeys; signing in with one |
| `PATCH /api/v1/admin/session/account` | console | `{email, login_alerts}` |
| `GET /api/v1/admin/commands/{id}/attachment` | viewer | A diagnostics bundle or screenshot |
| `GET /api/v1/admin/login-options` | none | Whether password sign-in is on, and the single sign-on providers |
| `POST /api/v1/admin/migrate` | admin | Apply pending database migrations; `GET …/session` lists them under `server.pending_migrations` |
| `POST /api/v1/admin/alerts/test` | admin | Send a test message to every alert channel |
| `GET /api/v1/admin/system/mail` | admin | How email is sent (SMTP host, port, encryption, sender; never the password) |
| `POST /api/v1/admin/system/mail/test` | admin | `{to}`: send a test email |
| `GET /api/v1/admin/system/network` | admin | How your request reached the server: client IP, HTTPS, proxy headers, warnings |
| `GET/PATCH /api/v1/admin/system/settings` | admin | Console-editable settings with their config.php values; `PATCH {key: value \| null}` (null resets) |
| `GET /api/v1/admin/system/update` | admin | Installed version, last update check, what would stop an install, backups |
| `POST /api/v1/admin/system/update/check` | admin | Check GitHub now |
| `POST /api/v1/admin/system/update/install` | admin | `{sha}`: install the newest commit on the channel's branch (must match a fresh check) |
| `POST /api/v1/admin/system/update/rollback` | admin | `{backup}`: restore the files from a backup |
| `POST /api/v1/admin/session/2fa[/setup\|/enable\|/disable\|/recovery]` | console only | Second sign-in step, and managing your own two-factor sign-in |

Example: reload every tablet in profile 3.

```sh
curl -X POST https://kiosks.example.com/api/v1/admin/commands \
  -H "Authorization: Bearer shp_…" -H "Content-Type: application/json" \
  -d '{"type": "reload", "profile_id": 3}'
```

The device API (`/api/v1/device/…`) is documented in [docs/PROTOCOL.md](docs/PROTOCOL.md).

## Security notes

- Device secrets, API tokens and passwords are stored only as hashes
  (SHA-256 for random tokens, bcrypt/argon via `password_hash` for passwords).
- The console uses a `SameSite=Strict`, `HttpOnly`, `Secure` session cookie, plus
  a CSRF token on every change. Its CSP allows only same-origin scripts and styles.
- Passkeys follow WebAuthn: the challenge, origin, relying party (the host of
  `base_url`), user-presence and signature are checked on every use, signing in
  with a passkey alone also needs user verification, and a counter that goes
  backwards is refused. Browser push is VAPID-signed and end-to-end encrypted, and
  only accepted for the known browser push services.
- Forwarding headers (client IP, HTTPS) are only believed from `trusted_proxies`,
  which can only be set in config.php.
- Logins, two-factor codes, enrollment and first-run setup are rate-limited per IP
  (and per user). Each device is limited to 120 check-ins and 300 command results
  per 10 minutes and 30 APK downloads an hour.
- Updating from the console only downloads from the repository set in
  `config/config.php` (never one chosen in the console), over HTTPS from GitHub's
  hosts, and only admins can start it. Turn it off with `self_update.enabled`.
- Two-factor sign-in uses standard TOTP (RFC 6238). Each code works once, and
  recovery codes are stored only as SHA-256 hashes. The TOTP secret itself has to
  be stored as it is, so protect database backups.
- Single sign-on uses the authorization code flow with PKCE, `state` and `nonce`.
  Each pending sign-in is single-use, expires after 10 minutes, and is bound to
  the browser that started it by a `SameSite=Lax` cookie limited to `/sso/`.
  ID token signatures are checked against the provider's published keys (RS256/384/512,
  ES256/384/512), as are the issuer, audience and expiry. Discovery documents and
  keys are cached in `storage/cache` for an hour.
- Enrollment codes carry 60 bits of randomness, expire after 3 days by default,
  and can be limited to a number of devices.
- The technician PIN reaches devices only as a PBKDF2 hash. Even so, a short
  PIN can be brute-forced by anyone who extracts it from a rooted tablet.

## License

GNU GPL v3.0, the same as Kiosky; see [LICENSE](LICENSE).
