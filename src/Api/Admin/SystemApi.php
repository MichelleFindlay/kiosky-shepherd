<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Auth\AdminAuth;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\SelfUpdate;

/** Updating Kiosky Shepherd itself from the console. Admins only. */
final class SystemApi
{
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

    /** POST /api/v1/admin/system/update/install  {version}: must be the newest release from the last check */
    public static function install(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $version = $req->input('version');
        if (!is_string($version) || $version === '') {
            throw HttpError::validation(['version' => 'Say which version to install.']);
        }
        try {
            $result = SelfUpdate::install($version, $p);
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
