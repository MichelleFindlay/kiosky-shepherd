<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Auth\OidcProvider;
use Shepherd\Auth\Sso;
use Shepherd\Auth\SsoError;
use Shepherd\Config;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\RateLimiter;

/**
 * Single sign-on endpoints. These are browser navigations, not API calls, so
 * they answer with redirects: on to the provider, or back to the console with
 * #sso-error=<reason> when something goes wrong.
 */
final class SsoApi
{
    /** GET /api/v1/admin/login-options  what the sign-in page should offer */
    public static function options(Request $req): Response
    {
        return Response::json([
            'password' => (bool) Config::get('password_login', true),
            'providers' => array_values(array_map(static fn (OidcProvider $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'url' => '/sso/' . $p->id . '/login',
            ], OidcProvider::all())),
        ]);
    }

    /** GET /sso/{provider}/login */
    public static function start(Request $req): Response
    {
        $p = OidcProvider::find($req->param('provider'));
        if ($p === null) {
            return self::fail($req, null, new SsoError('sso_unavailable', 'Unknown provider ' . $req->param('provider')));
        }
        RateLimiter::hit('sso-ip:' . $req->ip(), 60, 900);
        try {
            $r = Sso::begin($p);
        } catch (\Throwable $e) {
            return self::fail($req, $p, $e);
        }
        self::cookie($req, $r['browser'], 600);
        return Response::redirect($r['url']);
    }

    /** GET /sso/{provider}/callback  the provider's redirect back */
    public static function callback(Request $req): Response
    {
        $p = OidcProvider::find($req->param('provider'));
        if ($p === null) {
            return self::fail($req, null, new SsoError('sso_unavailable', 'Unknown provider ' . $req->param('provider')));
        }
        RateLimiter::hit('sso-ip:' . $req->ip(), 60, 900);
        try {
            $admin = Sso::complete($p, $req, self::readCookie($req));
        } catch (\Throwable $e) {
            return self::fail($req, $p, $e);
        }
        [$principal] = AdminAuth::establish($req, $admin, $p->id);
        Audit::log($principal, 'admin.login', 'admin', $principal->id, ['via' => $p->id]);
        self::cookie($req, '', -1);
        return Response::redirect('/admin/');
    }

    private static function fail(Request $req, ?OidcProvider $p, \Throwable $e): Response
    {
        $reason = $e instanceof SsoError ? $e->reason : 'sso_failed';
        $detail = $e->getMessage();
        if (!$e instanceof SsoError) {
            error_log('[kiosky-shepherd] sso ' . ($p?->id ?? '?') . ': ' . $e::class . ': ' . $detail);
        }
        try {
            Audit::log(null, 'admin.sso_failed', 'sso', $p?->id, ['reason' => $reason, 'detail' => mb_substr($detail, 0, 500)], $req->ip());
        } catch (\Throwable) {
            // e.g. the database migration hasn't been applied; the error log has it
        }
        self::cookie($req, '', -1);
        return Response::redirect('/admin/#sso-error=' . $reason);
    }

    /** Sets (or with $maxAge < 0 deletes) the cookie that binds a pending sign-in to this browser. */
    private static function cookie(Request $req, string $value, int $maxAge): void
    {
        // setcookie() rather than a Set-Cookie header, which would replace the session cookie
        setcookie(Sso::COOKIE, $value, [
            'expires' => $maxAge < 0 ? 1 : time() + $maxAge,
            'path' => '/sso/',
            'secure' => (bool) Config::get('cookie_secure', true) || $req->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax', // Strict cookies aren't sent on the provider's redirect back
        ]);
    }

    private static function readCookie(Request $req): ?string
    {
        $v = $_COOKIE[Sso::COOKIE] ?? null;
        return is_string($v) ? $v : null;
    }
}
