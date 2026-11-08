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
        $admin = Db::one('SELECT id, username, role, disabled FROM admins WHERE id = :id', ['id' => $adminId]);
        if ($admin === null || (int) $admin['disabled'] === 1) {
            $_SESSION = [];
            return null;
        }
        if (!$req->isSafeMethod()) {
            $sent = (string) $req->header('x-csrf-token');
            if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
                throw new HttpError(403, 'csrf_failed', 'The page is out of date. Reload it and try again.');
            }
        }
        return new Principal('admin', (int) $admin['id'], (string) $admin['username'], (string) $admin['role'],
            $req->ip(), (int) $admin['id']);
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
        return new Principal('token', (int) $row['id'], 'token:' . $row['name'], $role, $req->ip(),
            (int) $row['admin_id']);
    }

    /** @return array{0: Principal, 1: string} principal and CSRF token */
    public static function login(Request $req, string $username, string $password): array
    {
        $admin = Db::one('SELECT id, username, password_hash, role, disabled FROM admins WHERE username = :u',
            ['u' => $username]);

        // Verify against a dummy hash when the user doesn't exist, so timing doesn't leak usernames
        $hash = $admin['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuJ0f2Cq3tVZXKJ3Qb8rWm6cE6Yd0r9sW';
        $ok = password_verify($password, $hash);
        if ($admin === null || !$ok || (int) $admin['disabled'] === 1) {
            throw new HttpError(401, 'login_failed', 'Wrong username or password.');
        }
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $admin['id']]);
        }
        Db::run('UPDATE admins SET last_login_at = NOW() WHERE id = :id', ['id' => (int) $admin['id']]);

        self::startSession($req);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        $p = new Principal('admin', (int) $admin['id'], (string) $admin['username'], (string) $admin['role'],
            $req->ip(), (int) $admin['id']);
        return [$p, $_SESSION['csrf']];
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
