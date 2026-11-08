<p align="center"><img src="admin/img/logo-256.png" alt="Kiosky Shepherd logo" width="160" height="160"></p>

# Kiosky Shepherd

A management server for [Kiosky](https://github.com/MichelleFindlay/kiosky) wall
tablets, in plain PHP and MySQL. Tablets enroll with a code or QR code. After
that, their settings come only from the server and the local controls are
locked down.

- **Enrollment** with expiring, limited-use codes, shown as QR codes or typed by hand
- **Profiles**: named policies (start page, allowed sites, toolbar lockdown,
  technician PIN, boot, screen, orientation, updates…) with per-device overrides
- **Remote commands**: reload, open a page, restart, clear cache or sign-out
  data, show a message, identify, screenshot, check for updates
- **Managed updates**: upload Kiosky APKs, roll out "latest" or pin a version per
  profile or device. The server reads each APK's real version and package name.
- **Monitoring**: online status, current page, permissions, versions, event log
- **Web console** with viewer / operator / admin roles, **API tokens** for
  scripts, and a full **audit log**
- **Single sign-on** with Microsoft Entra ID or any OpenID Connect provider,
  with roles mapped from groups or app roles

The Kiosky app needs to implement the client side, which is specified in
[docs/PROTOCOL.md](docs/PROTOCOL.md). Kiosky 1.1.0 and later can enroll; the
**Lock the tablet to Kiosky** setting needs Kiosky 1.4.0 or later.

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
   It expires old commands and prunes events past `retention_days`. The
   console's Overview page shows when it last ran, and flags it after 30 minutes.
7. **Check the lockdown.** Each of these must return **403**:
   `https://kiosks.example.com/config/config.php`,
   `https://kiosks.example.com/src/Db.php`,
   `https://kiosks.example.com/sql/migrations/001_initial.sql`.
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

    location ~ ^/(src|config|storage|sql|bin|docs)(/|$) { deny all; return 404; }
    location ~ /\.(?!well-known/) { deny all; return 404; }
    location ~ \.(md|sql)$ { deny all; return 404; }

    location /admin/ { try_files $uri $uri/ =404; }
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

Behind a reverse proxy or load balancer, list its address in `trusted_proxies`
so the server sees client IPs and HTTPS correctly.

### Upgrading

Upload the new files over the old ones (keep `config/config.php` and `storage/`).
If the new version changes the database, admins see an **Update database**
banner in the console; click it. With SSH you can run `php bin/migrate.php`
instead, or import the new files from `sql/migrations/` in phpMyAdmin.

## Local development

```sh
cp config/config.example.php config/config.php   # set db + 'cookie_secure' => false
php bin/migrate.php
php bin/create-admin.php admin
php -S localhost:8080 index.php
```

Then open <http://localhost:8080/>. The built-in server only passes `/admin/`
files through; everything else goes to `index.php`.

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
| operator | Also rename devices, assign profiles, set device overrides, send commands, create and revoke enrollment codes, retire devices |
| admin | Also edit profiles, upload releases, manage users, read the audit log, delete retired devices |

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
| `GET /api/v1/admin/devices` | viewer | `?q=&profile_id=&status=active\|retired\|all&online=1\|0` |
| `GET /api/v1/admin/devices/{id}` | viewer | One device |
| `PATCH /api/v1/admin/devices/{id}` | operator | `{name, profile_id, notes, overrides: {key: value\|null}, clear_overrides}` |
| `DELETE /api/v1/admin/devices/{id}` | operator | Retire (releases the tablet). `?purge=1` on a retired device deletes it (admin). |
| `GET /api/v1/admin/devices/{id}/policy` | viewer | Effective policy and where each value comes from |
| `GET /api/v1/admin/devices/{id}/events` | viewer | Event log |
| `POST /api/v1/admin/devices/{id}/commands` | operator | `{type, params, ttl_hours}` |
| `GET /api/v1/admin/commands/types` | viewer | Command types and parameters |
| `GET /api/v1/admin/commands` | viewer | `?device_id=&status=` |
| `POST /api/v1/admin/commands` | operator | Bulk: `{type, params, device_ids \| profile_id \| all: true}` |
| `DELETE /api/v1/admin/commands/{id}` | operator | Cancel a pending command |
| `GET /api/v1/admin/commands/{id}/screenshot` | viewer | Screenshot image |
| `GET/POST /api/v1/admin/profiles` | viewer / admin | List, create `{name, description, settings}` |
| `GET/PATCH/DELETE /api/v1/admin/profiles/{id}` | viewer / admin | `settings` patches merge; `null` removes a key. Delete takes `?move_to=`. |
| `GET/POST /api/v1/admin/enrollment-tokens` | operator | `{profile_id, label, name_prefix, expires_hours, max_uses}` |
| `DELETE /api/v1/admin/enrollment-tokens/{id}` | operator | Revoke |
| `GET /api/v1/admin/releases` | viewer | Uploaded APKs |
| `POST /api/v1/admin/releases` | admin | multipart: `apk`, `notes` |
| `PATCH/DELETE /api/v1/admin/releases/{id}` | admin | `{active, notes}` / delete |
| `GET/POST/PATCH/DELETE /api/v1/admin/users[/{id}]` | admin | Console users |
| `GET/POST/DELETE /api/v1/admin/tokens[/{id}]` | viewer+ | API tokens (created from the console only) |
| `GET /api/v1/admin/audit` | admin | `?before=&target_type=&target_id=` |
| `GET /api/v1/admin/login-options` | none | Whether password sign-in is on, and the single sign-on providers |
| `POST /api/v1/admin/migrate` | admin | Apply pending database migrations; `GET …/session` lists them under `server.pending_migrations` |

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
- Logins, enrollment and first-run setup are rate-limited per IP (and per username for logins).
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
