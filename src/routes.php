<?php

declare(strict_types=1);

use Shepherd\Api\Admin\CommandsApi;
use Shepherd\Api\Admin\DevicesApi;
use Shepherd\Api\Admin\EnrollmentApi;
use Shepherd\Api\Admin\OverviewApi;
use Shepherd\Api\Admin\ProfilesApi;
use Shepherd\Api\Admin\ReleasesApi;
use Shepherd\Api\Admin\SchedulesApi;
use Shepherd\Api\Admin\SessionApi;
use Shepherd\Api\Admin\SsoApi;
use Shepherd\Api\Admin\SystemApi;
use Shepherd\Api\Admin\UsersApi;
use Shepherd\Api\DeviceApi;
use Shepherd\Http\Response;
use Shepherd\Http\Router;

return static function (Router $r): void {
    $r->get('/', static fn () => Response::redirect('/admin/'));
    $r->get('/api/v1/health', static fn () => Response::json(['ok' => true, 'version' => SHEPHERD_VERSION]));

    // ---- Device API (called by Kiosky) ---------------------------------------
    $r->post('/api/v1/device/enroll', [DeviceApi::class, 'enroll']);
    $r->post('/api/v1/device/checkin', [DeviceApi::class, 'checkin']);
    $r->post('/api/v1/device/commands/{id}/result', [DeviceApi::class, 'commandResult']);
    $r->get('/api/v1/device/releases/{id}/download', [DeviceApi::class, 'downloadRelease']);
    $r->post('/api/v1/device/live', [DeviceApi::class, 'liveFrame']);

    // ---- Admin API (web console and integrations) ----------------------------
    $a = '/api/v1/admin';
    $r->post("$a/session", [SessionApi::class, 'login']);
    $r->get("$a/session", [SessionApi::class, 'me']);
    $r->delete("$a/session", [SessionApi::class, 'logout']);
    $r->post("$a/session/password", [SessionApi::class, 'changePassword']);
    $r->post("$a/session/2fa", [SessionApi::class, 'secondFactor']);
    $r->post("$a/session/2fa/setup", [SessionApi::class, 'totpSetup']);
    $r->post("$a/session/2fa/enable", [SessionApi::class, 'totpEnable']);
    $r->post("$a/session/2fa/disable", [SessionApi::class, 'totpDisable']);
    $r->post("$a/session/2fa/recovery", [SessionApi::class, 'totpRecovery']);
    $r->get("$a/setup", [SessionApi::class, 'setupStatus']);
    $r->post("$a/setup", [SessionApi::class, 'setup']);
    $r->post("$a/migrate", [SessionApi::class, 'migrate']);
    $r->get("$a/login-options", [SsoApi::class, 'options']);

    // ---- Single sign-on (browser redirects; register the callback URL with the provider)
    $r->get('/sso/{provider}/login', [SsoApi::class, 'start']);
    $r->get('/sso/{provider}/callback', [SsoApi::class, 'callback']);

    $r->get("$a/overview", [OverviewApi::class, 'overview']);
    $r->get("$a/audit", [OverviewApi::class, 'audit']);
    $r->post("$a/alerts/test", [OverviewApi::class, 'testAlerts']);
    $r->get("$a/system/update", [SystemApi::class, 'status']);
    $r->post("$a/system/update/check", [SystemApi::class, 'check']);
    $r->post("$a/system/update/install", [SystemApi::class, 'install']);
    $r->post("$a/system/update/rollback", [SystemApi::class, 'rollback']);
    $r->get("$a/policy/schema", [ProfilesApi::class, 'schema']);

    $r->get("$a/devices", [DevicesApi::class, 'list']);
    $r->get("$a/devices/{id}", [DevicesApi::class, 'get']);
    $r->patch("$a/devices/{id}", [DevicesApi::class, 'update']);
    $r->delete("$a/devices/{id}", [DevicesApi::class, 'retire']);
    $r->get("$a/devices/{id}/policy", [DevicesApi::class, 'policy']);
    $r->get("$a/devices/{id}/events", [DevicesApi::class, 'events']);
    $r->post("$a/devices/{id}/commands", [DevicesApi::class, 'sendCommand']);
    $r->post("$a/devices/{id}/watch", [DevicesApi::class, 'watch']);
    $r->get("$a/devices/{id}/history", [DevicesApi::class, 'history']);
    $r->get("$a/devices/{id}/live", [DevicesApi::class, 'liveFrame']);
    $r->get("$a/tags", [DevicesApi::class, 'tags']);
    $r->post("$a/device-tags", [DevicesApi::class, 'bulkTags']);

    $r->get("$a/profiles", [ProfilesApi::class, 'list']);
    $r->post("$a/profiles", [ProfilesApi::class, 'create']);
    // Before profiles/{id}, which would otherwise answer 404 for "export"
    $r->get("$a/profiles/export", [ProfilesApi::class, 'exportAll']);
    $r->post("$a/profiles/import", [ProfilesApi::class, 'import']);
    $r->get("$a/profiles/{id}/export", [ProfilesApi::class, 'export']);
    $r->get("$a/profiles/{id}/versions", [ProfilesApi::class, 'versions']);
    $r->post("$a/profiles/{id}/versions/{version}/restore", [ProfilesApi::class, 'restore']);
    $r->get("$a/profiles/{id}", [ProfilesApi::class, 'get']);
    $r->patch("$a/profiles/{id}", [ProfilesApi::class, 'update']);
    $r->delete("$a/profiles/{id}", [ProfilesApi::class, 'delete']);

    $r->get("$a/enrollment-tokens", [EnrollmentApi::class, 'list']);
    $r->post("$a/enrollment-tokens", [EnrollmentApi::class, 'create']);
    $r->delete("$a/enrollment-tokens/{id}", [EnrollmentApi::class, 'revoke']);

    $r->get("$a/commands/types", [CommandsApi::class, 'types']);
    $r->get("$a/commands", [CommandsApi::class, 'list']);
    $r->post("$a/commands", [CommandsApi::class, 'create']);
    $r->delete("$a/commands/{id}", [CommandsApi::class, 'cancel']);
    $r->get("$a/commands/{id}/screenshot", [CommandsApi::class, 'screenshot']);

    $r->get("$a/schedules", [SchedulesApi::class, 'list']);
    $r->post("$a/schedules", [SchedulesApi::class, 'create']);
    $r->patch("$a/schedules/{id}", [SchedulesApi::class, 'update']);
    $r->delete("$a/schedules/{id}", [SchedulesApi::class, 'delete']);
    $r->post("$a/schedules/{id}/run", [SchedulesApi::class, 'run']);

    $r->get("$a/releases", [ReleasesApi::class, 'list']);
    $r->get("$a/releases/rings", [ReleasesApi::class, 'rings']);
    $r->put("$a/releases/rings/{tag}", [ReleasesApi::class, 'setRing']);
    $r->delete("$a/releases/rings/{tag}", [ReleasesApi::class, 'deleteRing']);
    $r->post("$a/releases", [ReleasesApi::class, 'upload']);
    $r->patch("$a/releases/{id}", [ReleasesApi::class, 'update']);
    $r->delete("$a/releases/{id}", [ReleasesApi::class, 'delete']);

    $r->get("$a/users", [UsersApi::class, 'list']);
    $r->post("$a/users", [UsersApi::class, 'create']);
    $r->patch("$a/users/{id}", [UsersApi::class, 'update']);
    $r->delete("$a/users/{id}", [UsersApi::class, 'delete']);

    $r->get("$a/tokens", [UsersApi::class, 'listTokens']);
    $r->post("$a/tokens", [UsersApi::class, 'createToken']);
    $r->delete("$a/tokens/{id}", [UsersApi::class, 'deleteToken']);
};
