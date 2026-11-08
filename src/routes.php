<?php

declare(strict_types=1);

use Shepherd\Api\Admin\CommandsApi;
use Shepherd\Api\Admin\DevicesApi;
use Shepherd\Api\Admin\EnrollmentApi;
use Shepherd\Api\Admin\OverviewApi;
use Shepherd\Api\Admin\ProfilesApi;
use Shepherd\Api\Admin\ReleasesApi;
use Shepherd\Api\Admin\SessionApi;
use Shepherd\Api\Admin\SsoApi;
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

    // ---- Admin API (web console and integrations) ----------------------------
    $a = '/api/v1/admin';
    $r->post("$a/session", [SessionApi::class, 'login']);
    $r->get("$a/session", [SessionApi::class, 'me']);
    $r->delete("$a/session", [SessionApi::class, 'logout']);
    $r->post("$a/session/password", [SessionApi::class, 'changePassword']);
    $r->get("$a/setup", [SessionApi::class, 'setupStatus']);
    $r->post("$a/setup", [SessionApi::class, 'setup']);
    $r->post("$a/migrate", [SessionApi::class, 'migrate']);
    $r->get("$a/login-options", [SsoApi::class, 'options']);

    // ---- Single sign-on (browser redirects; register the callback URL with the provider)
    $r->get('/sso/{provider}/login', [SsoApi::class, 'start']);
    $r->get('/sso/{provider}/callback', [SsoApi::class, 'callback']);

    $r->get("$a/overview", [OverviewApi::class, 'overview']);
    $r->get("$a/audit", [OverviewApi::class, 'audit']);
    $r->get("$a/policy/schema", [ProfilesApi::class, 'schema']);

    $r->get("$a/devices", [DevicesApi::class, 'list']);
    $r->get("$a/devices/{id}", [DevicesApi::class, 'get']);
    $r->patch("$a/devices/{id}", [DevicesApi::class, 'update']);
    $r->delete("$a/devices/{id}", [DevicesApi::class, 'retire']);
    $r->get("$a/devices/{id}/policy", [DevicesApi::class, 'policy']);
    $r->get("$a/devices/{id}/events", [DevicesApi::class, 'events']);
    $r->post("$a/devices/{id}/commands", [DevicesApi::class, 'sendCommand']);

    $r->get("$a/profiles", [ProfilesApi::class, 'list']);
    $r->post("$a/profiles", [ProfilesApi::class, 'create']);
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

    $r->get("$a/releases", [ReleasesApi::class, 'list']);
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
