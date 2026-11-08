<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Auth\OidcProvider;
use Shepherd\Auth\Principal;
use Shepherd\Auth\Totp;
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
            $admin = AdminAuth::verifyPassword($username, $password);
        } catch (HttpError $e) {
            Audit::log(null, 'admin.login_failed', 'admin', null, ['username' => mb_substr($username, 0, 64)], $req->ip());
            throw $e;
        }
        RateLimiter::clear('login-user:' . strtolower($username));
        if (AdminAuth::hasTotp($admin)) {
            AdminAuth::beginSecondFactor($req, $admin);
            return Response::json(['two_factor_required' => true]);
        }
        [$p, $csrf] = AdminAuth::establish($req, $admin);
        Audit::log($p, 'admin.login', 'admin', $p->id);
        return Response::json(self::sessionInfo($p, $csrf));
    }

    /** POST /api/v1/admin/session/2fa  {code}: the second step of a password sign-in */
    public static function secondFactor(Request $req): Response
    {
        $id = AdminAuth::pendingSecondFactor($req);
        if ($id === null) {
            throw new HttpError(401, 'two_factor_expired', 'The sign-in took too long. Enter your password again.');
        }
        RateLimiter::hit('2fa-ip:' . $req->ip(), 30, 900);
        RateLimiter::hit('2fa-user:' . $id, 10, 900);
        $code = trim((string) $req->input('code', ''));

        $admin = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $id]);
        if ($admin === null || (int) $admin['disabled'] === 1 || !AdminAuth::hasTotp($admin)) {
            throw new HttpError(401, 'two_factor_expired', 'The sign-in took too long. Enter your password again.');
        }
        $via = self::checkSecondFactor($admin, $code);
        if ($via === null) {
            Audit::log(null, 'admin.2fa_failed', 'admin', $id, ['username' => $admin['username']], $req->ip());
            throw new HttpError(401, 'two_factor_failed', 'That code is wrong or has already been used.');
        }
        RateLimiter::clear('2fa-user:' . $id);
        [$p, $csrf] = AdminAuth::establish($req, $admin);
        Audit::log($p, 'admin.login', 'admin', $p->id, ['via' => $via]);
        return Response::json(self::sessionInfo($p, $csrf));
    }

    /**
     * Accepts a TOTP code (once per time step) or an unused recovery code (once).
     *
     * @return string|null 'totp' or 'recovery_code', or null when the code is wrong
     */
    private static function checkSecondFactor(array $admin, string $code): ?string
    {
        $id = (int) $admin['id'];
        if (Totp::looksLikeRecoveryCode($code)) {
            $hash = Totp::hashRecoveryCode($code);
            return Db::transaction(static function () use ($id, $hash): ?string {
                $json = Db::value('SELECT totp_recovery FROM admins WHERE id = :id FOR UPDATE', ['id' => $id]);
                $left = Db::decode(is_string($json) ? $json : null);
                $i = array_search($hash, $left, true);
                if ($i === false) {
                    return null;
                }
                array_splice($left, (int) $i, 1);
                Db::update('admins', ['totp_recovery' => Db::json(array_values($left))], ['id' => $id]);
                return 'recovery_code';
            });
        }
        $last = $admin['totp_last_step'] === null ? null : (int) $admin['totp_last_step'];
        $step = Totp::verify((string) $admin['totp_secret'], $code, $last);
        if ($step === null) {
            return null;
        }
        // Conditional update, so two requests racing with the same code can't both win
        $n = Db::run('UPDATE admins SET totp_last_step = :s WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :s2)',
            ['s' => $step, 'id' => $id, 's2' => $step])->rowCount();
        return $n === 1 ? 'totp' : null;
    }

    /** The signed-in password user, for managing their own two-factor sign-in. */
    private static function passwordUser(Request $req): array
    {
        $p = AdminAuth::require($req);
        if ($p->type !== 'admin') {
            throw HttpError::forbidden('Manage two-factor sign-in from the web console, not with an API token.');
        }
        $admin = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $p->id]) ?? throw HttpError::unauthorized();
        if ($admin['password_hash'] === null) {
            throw HttpError::conflict('You sign in with single sign-on. Set up two-factor sign-in with your identity provider.');
        }
        return [$p, $admin];
    }

    /** POST /api/v1/admin/session/2fa/setup  starts setup: a new secret to scan, not saved yet */
    public static function totpSetup(Request $req): Response
    {
        [, $admin] = self::passwordUser($req);
        Migrator::requireTable('device_tags'); // the 1.3.0 migration also adds the TOTP columns
        if (AdminAuth::hasTotp($admin)) {
            throw HttpError::conflict('Two-factor sign-in is already on. Turn it off first to move to a new app.');
        }
        $secret = Totp::newSecret();
        $_SESSION['totp_setup'] = $secret;
        $issuer = (string) Config::get('organisation', '') !== '' ? 'Kiosky Shepherd (' . Config::get('organisation') . ')' : 'Kiosky Shepherd';
        return Response::json(['secret' => $secret, 'uri' => Totp::uri($secret, (string) $admin['username'], $issuer)]);
    }

    /** POST /api/v1/admin/session/2fa/enable  {code}: confirms the app works, turns 2FA on, returns recovery codes once */
    public static function totpEnable(Request $req): Response
    {
        [$p, $admin] = self::passwordUser($req);
        $secret = $_SESSION['totp_setup'] ?? null;
        if (!is_string($secret)) {
            throw HttpError::conflict('Start the setup again.');
        }
        if (AdminAuth::hasTotp($admin)) {
            throw HttpError::conflict('Two-factor sign-in is already on.');
        }
        RateLimiter::hit('2fa-user:' . $p->id, 10, 900);
        $step = Totp::verify($secret, (string) $req->input('code', ''), null);
        if ($step === null) {
            throw HttpError::validation(['code' => 'That code doesn\'t match. Check the time on your phone is set automatically.']);
        }
        $codes = Totp::newRecoveryCodes();
        Db::update('admins', [
            'totp_secret' => $secret,
            'totp_last_step' => $step,
            'totp_recovery' => Db::json(array_map([Totp::class, 'hashRecoveryCode'], $codes)),
        ], ['id' => $p->id]);
        unset($_SESSION['totp_setup']);
        Audit::log($p, 'admin.2fa_enabled', 'admin', $p->id);
        return Response::json(['recovery_codes' => $codes]);
    }

    /** POST /api/v1/admin/session/2fa/disable  {password} */
    public static function totpDisable(Request $req): Response
    {
        [$p, $admin] = self::passwordUser($req);
        self::confirmPassword($admin, $req);
        Db::update('admins', ['totp_secret' => null, 'totp_last_step' => null, 'totp_recovery' => null], ['id' => $p->id]);
        Audit::log($p, 'admin.2fa_disabled', 'admin', $p->id);
        return Response::json(['ok' => true]);
    }

    /** POST /api/v1/admin/session/2fa/recovery  {password}: replaces the recovery codes */
    public static function totpRecovery(Request $req): Response
    {
        [$p, $admin] = self::passwordUser($req);
        if (!AdminAuth::hasTotp($admin)) {
            throw HttpError::conflict('Two-factor sign-in is off.');
        }
        self::confirmPassword($admin, $req);
        $codes = Totp::newRecoveryCodes();
        Db::update('admins', ['totp_recovery' => Db::json(array_map([Totp::class, 'hashRecoveryCode'], $codes))], ['id' => $p->id]);
        Audit::log($p, 'admin.2fa_recovery_codes', 'admin', $p->id);
        return Response::json(['recovery_codes' => $codes]);
    }

    private static function confirmPassword(array $admin, Request $req): void
    {
        RateLimiter::hit('login-user:' . strtolower((string) $admin['username']), 10, 900);
        if (!password_verify((string) $req->input('password', ''), (string) $admin['password_hash'])) {
            throw HttpError::validation(['password' => 'That is not your password.']);
        }
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
        $server = ['version' => SHEPHERD_VERSION, 'cron' => OverviewApi::cronStatus()];
        if ($p->can('admin')) {
            $server['pending_migrations'] = Migrator::pending();
            // From the last check only; the session never waits for GitHub
            $u = \Shepherd\SelfUpdate::cached();
            $server['update'] = $u === null ? null : ['latest' => $u['latest'] ?? null, 'available' => (bool) ($u['available'] ?? false),
                'channel' => $u['channel'] ?? null, 'sha' => isset($u['sha']) ? substr((string) $u['sha'], 0, 7) : null];
        }
        $twoFactor = null;
        $recoveryLeft = null;
        if ($p->type === 'admin') {
            $row = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $p->id]) ?? [];
            $twoFactor = AdminAuth::hasTotp($row);
            $recoveryLeft = $twoFactor ? count(Db::decode($row['totp_recovery'] ?? null)) : null;
        }
        return [
            'user' => [
                'id' => $p->adminId,
                'username' => $p->name,
                'role' => $p->role,
                'via' => $p->type,
                'sso' => $sso === null ? null : ['id' => $sso, 'label' => OidcProvider::labelFor($sso)],
                'two_factor' => $twoFactor,
                'scope_tags' => $p->scopeTags,
                'recovery_codes_left' => $recoveryLeft,
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
