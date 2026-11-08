<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Auth\AdminAuth;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Http\TrustedProxies;
use Shepherd\Audit;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Mailer;
use Shepherd\SelfUpdate;
use Shepherd\Settings;

/** Server settings and updating Kiosky Shepherd itself, from the console. Admins only. */
final class SystemApi
{
    /** GET /api/v1/admin/system/settings  the settings the console may change, with config.php's values */
    public static function settings(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return Response::json(Settings::describe());
    }

    /** PATCH /api/v1/admin/system/settings  {key: value} to change, {key: null} to go back to config.php */
    public static function saveSettings(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        if ($p->type !== 'admin') {
            throw HttpError::forbidden('Change server settings from the web console, not with an API token.');
        }
        $changed = Settings::save($req->json(), $p);
        return Response::json(['changed' => $changed] + Settings::describe());
    }

    /** GET /api/v1/admin/system/mail  how email is sent (never the password) */
    public static function mail(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $me = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $p->adminId]) ?? [];
        $alertTo = Config::get('alerts.email', []);
        return Response::json(Mailer::summary() + [
            'alert_recipients' => is_array($alertTo) ? array_values($alertTo) : (is_string($alertTo) ? [$alertTo] : []),
            'your_email' => is_string($me['email'] ?? null) ? $me['email'] : null,
        ]);
    }

    /** POST /api/v1/admin/system/mail/test  {to}: sends a test email and reports the server's answer */
    public static function testMail(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        \Shepherd\RateLimiter::hit('mail-test:' . $p->adminId, 10, 600);
        $to = trim((string) $req->input('to', ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpError::validation(['to' => 'Enter the address to send the test to.']);
        }
        try {
            Mailer::send([$to], 'Test email', "This is a test from Kiosky Shepherd, sent by {$p->name}.\n\n"
                . 'If it arrived, offline alerts by email will arrive the same way.');
        } catch (\Throwable $e) {
            Audit::log($p, 'mail.test_failed', null, null, ['to' => $to, 'error' => $e->getMessage()]);
            throw new HttpError(502, 'mail_failed', $e->getMessage());
        }
        Audit::log($p, 'mail.tested', null, null, ['to' => $to, 'transport' => Mailer::transport()]);
        return Response::json(['ok' => true, 'transport' => Mailer::transport()]);
    }

    /** GET /api/v1/admin/system/network  how this request reached the server (reverse proxy / CDN diagnostics) */
    public static function network(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return Response::json(TrustedProxies::diagnose($req));
    }

    /** GET /api/v1/admin/system/update  installed version, last check, what would stop an install, backups */
    public static function status(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return Response::json(SelfUpdate::status());
    }

    /** POST /api/v1/admin/system/update/check  asks GitHub now */
    public static function check(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        SelfUpdate::check(true);
        return Response::json(SelfUpdate::status());
    }

    /** POST /api/v1/admin/system/update/install  {sha}: the newest commit on the channel's branch, from the last check */
    public static function install(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $sha = $req->input('sha');
        if (!is_string($sha) || !preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw HttpError::validation(['sha' => 'Say which commit to install.']);
        }
        try {
            $result = SelfUpdate::install($sha, $p);
        } catch (HttpError $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[kiosky-shepherd] self-update: ' . $e->getMessage());
            throw new HttpError(500, 'update_failed', $e->getMessage());
        }
        return Response::json($result);
    }

    /** POST /api/v1/admin/system/update/rollback  {backup}: a name from the status's backups */
    public static function rollback(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        try {
            $result = SelfUpdate::rollback((string) $req->input('backup', ''), $p);
        } catch (HttpError $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[kiosky-shepherd] self-update rollback: ' . $e->getMessage());
            throw new HttpError(500, 'rollback_failed', $e->getMessage());
        }
        return Response::json($result);
    }
}
