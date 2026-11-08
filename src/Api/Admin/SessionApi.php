<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Auth\OidcProvider;
use Shepherd\Auth\Principal;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Migrator;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\RateLimiter;

final class SessionApi
{
    /** POST /api/v1/admin/session */
    public static function login(Request $req): Response
    {
        $username = trim((string) $req->input('username', ''));
        $password = (string) $req->input('password', '');
        if (!Config::get('password_login', true)) {
            throw HttpError::forbidden('Password sign-in is turned off. Use single sign-on.');
        }
        if ($username === '' || $password === '') {
            throw HttpError::validation(['username' => 'Enter your username and password.']);
        }
        RateLimiter::hit('login-ip:' . $req->ip(), 30, 900);
        RateLimiter::hit('login-user:' . strtolower($username), 10, 900);

        try {
            [$p, $csrf] = AdminAuth::login($req, $username, $password);
        } catch (HttpError $e) {
            Audit::log(null, 'admin.login_failed', 'admin', null, ['username' => mb_substr($username, 0, 64)], $req->ip());
            throw $e;
        }
        RateLimiter::clear('login-user:' . strtolower($username));
        Audit::log($p, 'admin.login', 'admin', $p->id);
        return Response::json(self::sessionInfo($p, $csrf));
    }

    /** GET /api/v1/admin/session */
    public static function me(Request $req): Response
    {
        $p = AdminAuth::require($req);
        return Response::json(self::sessionInfo($p, $p->type === 'admin' ? AdminAuth::csrfToken() : null));
    }

    /** What the console needs after signing in: who you are, how you signed in, the server version. */
    private static function sessionInfo(Principal $p, ?string $csrf): array
    {
        $sso = $p->type === 'admin' ? AdminAuth::sessionSsoProvider() : null;
        $server = ['version' => SHEPHERD_VERSION];
        if ($p->can('admin')) {
            $server['pending_migrations'] = Migrator::pending();
        }
        return [
            'user' => [
                'id' => $p->adminId,
                'username' => $p->name,
                'role' => $p->role,
                'via' => $p->type,
                'sso' => $sso === null ? null : ['id' => $sso, 'label' => OidcProvider::labelFor($sso)],
            ],
            'csrf_token' => $csrf,
            'server' => $server,
        ];
    }

    /** POST /api/v1/admin/migrate  applies database updates after an upgrade (for hosts without SSH) */
    public static function migrate(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        try {
            $done = Migrator::run();
        } catch (\PDOException $e) {
            throw new HttpError(500, 'migration_failed', 'Updating the database failed: ' . $e->getMessage());
        }
        if ($done !== []) {
            Audit::log($p, 'server.migrated', null, null, ['applied' => $done]);
        }
        return Response::json(['applied' => $done]);
    }

    /** DELETE /api/v1/admin/session */
    public static function logout(Request $req): Response
    {
        $p = AdminAuth::current($req);
        if ($p !== null) {
            Audit::log($p, 'admin.logout', 'admin', $p->id);
        }
        AdminAuth::logout($req);
        return Response::noContent();
    }

    /** GET /api/v1/admin/setup  whether the first-run form should be offered */
    public static function setupStatus(Request $req): Response
    {
        $install = !Migrator::tableExists('admins');
        $noUsers = $install || (int) Db::value('SELECT COUNT(*) FROM admins') === 0;
        return Response::json([
            'needed' => $noUsers,
            'enabled' => $noUsers && strlen((string) Config::get('setup_key', '')) >= 16,
            'install' => $install, // the database tables will be created too
        ]);
    }

    /**
     * POST /api/v1/admin/setup  {setup_key, username, password}
     * Creates any missing database tables, then the first admin, then signs in.
     */
    public static function setup(Request $req): Response
    {
        if (Migrator::tableExists('rate_limits')) {
            RateLimiter::hit('setup:' . $req->ip(), 10, 900);
        }
        $key = (string) Config::get('setup_key', '');
        $sent = (string) $req->input('setup_key', '');
        if (strlen($key) < 16) {
            throw HttpError::forbidden('Set a setup_key of at least 16 characters in config/config.php first.');
        }
        if (!hash_equals($key, $sent)) {
            throw HttpError::validation(['setup_key' => 'That is not the setup key from config/config.php.']);
        }
        if (Migrator::tableExists('admins') && (int) Db::value('SELECT COUNT(*) FROM admins') > 0) {
            throw HttpError::conflict('Setup has already been done. Sign in instead.');
        }

        // Only the holder of the setup key gets here, so database errors can be shown in full
        try {
            Migrator::run();
        } catch (\PDOException $e) {
            throw new HttpError(500, 'install_failed', 'Creating the database tables failed: ' . $e->getMessage());
        }

        $username = trim((string) $req->input('username', ''));
        $password = (string) $req->input('password', '');
        if (!preg_match('/^[A-Za-z0-9._@\-]{2,64}$/', $username)) {
            throw HttpError::validation(['username' => 'Use 2–64 letters, digits or . _ @ -']);
        }
        if (($err = AdminAuth::validatePassword($password)) !== null) {
            throw HttpError::validation(['password' => $err]);
        }
        Db::transaction(static function () use ($username, $password): void {
            // Locking read so two simultaneous setups can't both succeed
            if ((int) Db::value('SELECT COUNT(*) FROM admins FOR UPDATE') > 0) {
                throw HttpError::conflict('Setup has already been done. Sign in instead.');
            }
            Db::insert('admins', [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
        });
        [$p, $csrf] = AdminAuth::login($req, $username, $password);
        Audit::log($p, 'admin.setup', 'admin', $p->id);
        return Response::json(self::sessionInfo($p, $csrf), 201);
    }

    /** POST /api/v1/admin/session/password */
    public static function changePassword(Request $req): Response
    {
        $p = AdminAuth::require($req);
        if ($p->type !== 'admin') {
            throw HttpError::forbidden('Change passwords from the web console, not with an API token.');
        }
        $current = (string) $req->input('current_password', '');
        $new = (string) $req->input('new_password', '');
        $hash = Db::value('SELECT password_hash FROM admins WHERE id = :id', ['id' => $p->id]);
        if ($hash === null) {
            throw HttpError::conflict('You sign in with single sign-on, so you have no password here.');
        }
        if (!password_verify($current, (string) $hash)) {
            throw HttpError::validation(['current_password' => 'That is not your current password.']);
        }
        if (($err = AdminAuth::validatePassword($new)) !== null) {
            throw HttpError::validation(['new_password' => $err]);
        }
        Db::update('admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $p->id]);
        session_regenerate_id(true);
        Audit::log($p, 'admin.password_changed', 'admin', $p->id);
        return Response::json(['ok' => true]);
    }
}
