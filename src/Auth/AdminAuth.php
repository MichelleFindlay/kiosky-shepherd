<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;

/**
 * Admin authentication. Two ways in:
 *  - Session cookie (the web console). State-changing requests must send the
 *    session's CSRF token in an X-CSRF-Token header.
 *  - API token: "Authorization: Bearer shp_…" for scripts and integrations.
 * Console sessions are started by a password (login), optionally followed by a
 * TOTP or recovery code (two-factor sign-in), or by single sign-on (Sso).
 */
final class AdminAuth
{
    public const TOKEN_PREFIX = 'shp_';

    public static function startSession(Request $req): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) Config::get('session_lifetime', 43200);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_name((string) Config::get('session_name', 'shepherd_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) Config::get('cookie_secure', true) || $req->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        // Idle timeout
        $now = time();
        if (isset($_SESSION['seen']) && $now - (int) $_SESSION['seen'] > $lifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = $now;
    }

    /** Returns the authenticated admin principal, or throws 401. */
    public static function require(Request $req, string $role = 'viewer'): Principal
    {
        $p = self::current($req);
        if ($p === null) {
            throw HttpError::unauthorized();
        }
        $p->require($role);
        return $p;
    }

    public static function current(Request $req): ?Principal
    {
        $bearer = $req->bearer();
        if ($bearer !== null) {
            return self::fromToken($req, $bearer);
        }

        self::startSession($req);
        $adminId = $_SESSION['admin_id'] ?? null;
        if (!is_int($adminId)) {
            return null;
        }
        $admin = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $adminId]);
        if ($admin === null || (int) $admin['disabled'] === 1) {
            $_SESSION = [];
            return null;
        }
        if (!self::touchSession($req, $admin)) {
            $_SESSION = [];
            return null; // signed out from another browser, or "sign out everywhere"
        }
        if (!$req->isSafeMethod()) {
            $sent = (string) $req->header('x-csrf-token');
            if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
                throw new HttpError(403, 'csrf_failed', 'The page is out of date. Reload it and try again.');
            }
        }
        if (self::mustSetUpSecondFactor($admin) && !self::allowedBeforeSecondFactor($req)) {
            throw new HttpError(403, 'two_factor_required',
                'Your organisation requires two-factor sign-in. Set it up under Your account to continue.');
        }
        return new Principal('admin', (int) $admin['id'], (string) $admin['username'], (string) $admin['role'],
            $req->ip(), (int) $admin['id'], self::scopeOf($admin));
    }

    // ---- Second factor policy -------------------------------------------------------

    /** TOTP or at least one passkey. */
    public static function hasSecondFactor(array $admin): bool
    {
        return self::hasTotp($admin) || Passkeys::count((int) $admin['id']) > 0;
    }

    /**
     * True when 'require_2fa' is on and this password user has no second factor yet.
     * Single sign-on users are left to their identity provider's MFA.
     */
    public static function mustSetUpSecondFactor(array $admin): bool
    {
        return (bool) Config::get('require_2fa', false) && ($admin['password_hash'] ?? null) !== null
            && ($admin['sso_provider'] ?? null) === null && !self::hasSecondFactor($admin);
    }

    /** What such a user may still do: see who they are, set up a factor, sign out. */
    private static function allowedBeforeSecondFactor(Request $req): bool
    {
        $p = $req->path;
        return $p === '/api/v1/admin/session' || str_starts_with($p, '/api/v1/admin/session/2fa/')
            || str_starts_with($p, '/api/v1/admin/session/passkeys');
    }

    // ---- Tracked sessions (admin_sessions) ------------------------------------------

    private const BROWSER_COOKIE = 'shepherd_browser';

    private static function sessionsTracked(): bool
    {
        return \Shepherd\Migrator::has('admin_sessions');
    }

    /** A long-lived random cookie per browser, for "new sign-in" emails. Returns its hash. */
    private static function browserHash(Request $req): string
    {
        $v = $_COOKIE[self::BROWSER_COOKIE] ?? null;
        if (!is_string($v) || !preg_match('/^[A-Za-z0-9_\-]{32,64}$/', $v)) {
            $v = \Shepherd\Util::token(32);
            setcookie(self::BROWSER_COOKIE, $v, [
                'expires' => time() + 400 * 86400, 'path' => '/',
                'secure' => (bool) Config::get('cookie_secure', true) || $req->isHttps(),
                'httponly' => true, 'samesite' => 'Strict',
            ]);
        }
        return hash('sha256', $v);
    }

    /** Records a new sign-in; emails the user when it's from a browser they haven't used. */
    private static function startTracked(Request $req, array $admin, string $method): void
    {
        if (!self::sessionsTracked()) {
            return;
        }
        $token = \Shepherd\Util::token(32);
        $browser = self::browserHash($req);
        $seenBefore = Db::value('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = :a AND browser_hash = :b',
            ['a' => (int) $admin['id'], 'b' => $browser]) > 0;
        Db::insert('admin_sessions', [
            'id' => hash('sha256', $token),
            'admin_id' => (int) $admin['id'],
            'browser_hash' => $browser,
            'ip' => $req->ip(),
            'user_agent' => \Shepherd\Util::clip($req->header('user-agent'), 255),
            'method' => $method,
        ]);
        $_SESSION['sid'] = $token;
        $firstEver = Db::value('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = :a', ['a' => (int) $admin['id']]) <= 1;
        if (!$seenBefore && !$firstEver && !empty($admin['login_alerts']) && filter_var($admin['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $to = (string) $admin['email'];
            $ip = $req->ip();
            $ua = (string) $req->header('user-agent');
            \Shepherd\Util::afterResponse(static fn () => \Shepherd\Mailer::send([$to], 'New sign-in to your account',
                "Someone signed in to Kiosky Shepherd as {$admin['username']} from a browser not used before.\n\n"
                . "When: " . gmdate('Y-m-d H:i') . " UTC\nAddress: $ip\nBrowser: $ua\nMethod: $method\n\n"
                . "If this wasn't you, sign in, open Your account → Sessions, sign out the other sessions and change your password.\n"
                . \Shepherd\Util::baseUrl() . '/admin/#/account'));
        }
    }

    /** Checks this request's tracked session still exists. False when it was ended elsewhere. */
    private static function touchSession(Request $req, array $admin): bool
    {
        if (!self::sessionsTracked()) {
            return true;
        }
        $token = $_SESSION['sid'] ?? null;
        if (!is_string($token)) {
            // Signed in before sessions were tracked: start tracking now
            self::startTracked($req, $admin, isset($_SESSION['sso_provider']) ? 'sso' : 'password');
            return true;
        }
        $row = Db::one('SELECT last_seen_at, ended_at, admin_id FROM admin_sessions WHERE id = :id', ['id' => hash('sha256', $token)]);
        if ($row === null || $row['ended_at'] !== null || (int) $row['admin_id'] !== (int) $admin['id']) {
            return false;
        }
        if (strtotime($row['last_seen_at'] . ' UTC') < time() - 60) {
            Db::run('UPDATE admin_sessions SET last_seen_at = NOW(), ip = :ip WHERE id = :id',
                ['ip' => $req->ip(), 'id' => hash('sha256', $token)]);
        }
        return true;
    }

    /** The current session's id (hash), or null. */
    public static function currentSessionId(): ?string
    {
        $t = $_SESSION['sid'] ?? null;
        return is_string($t) ? hash('sha256', $t) : null;
    }

    /** Ends sessions of a user: all of them, or all but $keep. Returns how many. */
    public static function endSessions(int $adminId, ?string $keep = null): int
    {
        if (!self::sessionsTracked()) {
            return 0;
        }
        return Db::run('UPDATE admin_sessions SET ended_at = NOW() WHERE admin_id = :a AND ended_at IS NULL'
            . ($keep !== null ? ' AND id <> :k' : ''), ['a' => $adminId] + ($keep !== null ? ['k' => $keep] : []))->rowCount();
    }

    /**
     * Device tags an admins row is limited to, or null for every device. Admins are never
     * limited. Works before the 1.4.0 migration adds the column (no column: no limit).
     *
     * @return list<string>|null
     */
    public static function scopeOf(array $admin): ?array
    {
        if (($admin['role'] ?? null) === 'admin') {
            return null;
        }
        $tags = Db::decode(is_string($admin['scope_tags'] ?? null) ? $admin['scope_tags'] : null, null);
        if (!is_array($tags)) {
            return null;
        }
        $tags = array_values(array_filter($tags, [\Shepherd\Devices::class, 'isTag']));
        return $tags === [] ? null : $tags;
    }

    private static function fromToken(Request $req, string $token): Principal
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            throw HttpError::unauthorized('Invalid API token.');
        }
        $row = Db::one(
            'SELECT t.id, t.name, t.role, t.admin_id, t.expires_at, a.disabled, a.role AS admin_role
               FROM api_tokens t JOIN admins a ON a.id = t.admin_id
              WHERE t.token_hash = :h',
            ['h' => hash('sha256', $token)]
        );
        if ($row === null || (int) $row['disabled'] === 1
            || ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') < time())) {
            throw HttpError::unauthorized('Invalid or expired API token.');
        }
        Db::run('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)',
            ['id' => (int) $row['id']]);

        // A token never has more power than the admin who owns it
        $role = (string) $row['role'];
        if ((Principal::ROLES[$role] ?? 0) > (Principal::ROLES[$row['admin_role']] ?? 0)) {
            $role = (string) $row['admin_role'];
        }
        // …and is limited to the same devices
        $owner = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => (int) $row['admin_id']]) ?? [];
        return new Principal('token', (int) $row['id'], 'token:' . $row['name'], $role, $req->ip(),
            (int) $row['admin_id'], self::scopeOf($owner));
    }

    /**
     * Checks a username and password and starts a session (no second factor).
     *
     * @return array{0: Principal, 1: string} principal and CSRF token
     */
    public static function login(Request $req, string $username, string $password): array
    {
        return self::establish($req, self::verifyPassword($username, $password));
    }

    /**
     * Returns the admins row for a correct username and password, or throws 401.
     * Uses SELECT * so it keeps working before the 1.3.0 migration adds the TOTP columns.
     *
     * @return array<string, mixed>
     */
    public static function verifyPassword(string $username, string $password): array
    {
        $admin = Db::one('SELECT * FROM admins WHERE username = :u', ['u' => $username]);

        // Verify against a dummy hash when the user doesn't exist (or signs in with single
        // sign-on and has no password), so timing doesn't leak usernames
        $hash = $admin['password_hash'] ?? null;
        $ok = password_verify($password, $hash ?? '$2y$10$abcdefghijklmnopqrstuuJ0f2Cq3tVZXKJ3Qb8rWm6cE6Yd0r9sW');
        if ($admin === null || $hash === null || !$ok || (int) $admin['disabled'] === 1) {
            throw new HttpError(401, 'login_failed', 'Wrong username or password.');
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::update('admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $admin['id']]);
        }
        return $admin;
    }

    /** Whether this admins row has two-factor sign-in turned on. */
    public static function hasTotp(array $admin): bool
    {
        return is_string($admin['totp_secret'] ?? null) && $admin['totp_secret'] !== '';
    }

    /**
     * The password was right but a TOTP or recovery code is still needed. The session
     * remembers who for five minutes; it is not signed in until the code is checked.
     */
    public static function beginSecondFactor(Request $req, array $admin): void
    {
        self::startSession($req);
        session_regenerate_id(true);
        $_SESSION = ['seen' => time(), 'pending_2fa' => ['id' => (int) $admin['id'], 'at' => time()]];
    }

    /** Admin id waiting for its second factor in this session, or null. */
    public static function pendingSecondFactor(Request $req): ?int
    {
        self::startSession($req);
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($p) || !is_int($p['id'] ?? null) || time() - (int) ($p['at'] ?? 0) > 300) {
            return null;
        }
        return $p['id'];
    }

    /**
     * Starts a fresh console session for an admins row (password or single sign-on).
     *
     * @return array{0: Principal, 1: string} principal and CSRF token
     */
    public static function establish(Request $req, array $admin, ?string $ssoProvider = null, ?string $method = null): array
    {
        Db::run('UPDATE admins SET last_login_at = NOW() WHERE id = :id', ['id' => (int) $admin['id']]);

        self::startSession($req);
        session_regenerate_id(true);
        $_SESSION = ['seen' => time()];
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if ($ssoProvider !== null) {
            $_SESSION['sso_provider'] = $ssoProvider;
        }
        self::startTracked($req, $admin, $method ?? ($ssoProvider !== null ? 'sso:' . $ssoProvider : 'password'));

        $p = new Principal('admin', (int) $admin['id'], (string) $admin['username'], (string) $admin['role'],
            $req->ip(), (int) $admin['id'], self::scopeOf($admin));
        return [$p, $_SESSION['csrf']];
    }

    /** Provider id the current session signed in with, or null for a password sign-in. */
    public static function sessionSsoProvider(): ?string
    {
        $v = $_SESSION['sso_provider'] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function logout(Request $req): void
    {
        self::startSession($req);
        $sid = self::currentSessionId();
        if ($sid !== null && self::sessionsTracked()) {
            Db::run('UPDATE admin_sessions SET ended_at = NOW() WHERE id = :id AND ended_at IS NULL', ['id' => $sid]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }

    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < 10) {
            return 'Use at least 10 characters.';
        }
        if (strlen($password) > 200) {
            return 'Use at most 200 characters.';
        }
        return null;
    }
}
