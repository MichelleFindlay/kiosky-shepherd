<?php

declare(strict_types=1);

namespace Shepherd\Api;

use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\RateLimiter;
use Shepherd\Releases;
use Shepherd\Util;

/**
 * QR provisioning (Kiosky 1.7.0+): a factory-reset tablet scans a code during setup
 * (tap the welcome screen six times), joins Wi-Fi, downloads Kiosky, makes it the
 * device owner and enrolls with the code inside, with no computer or adb.
 * See docs/PROTOCOL.md §3.3.
 */
final class ProvisioningApi
{
    /**
     * SHA-256 of the Kiosky release signing certificate (1.7.0+, after the key rotation),
     * base64url without padding. Only used with provisioning.apk_url: when this server
     * serves the APK, the QR carries the APK's own checksum instead, which also works
     * on Android 6–8, where Kiosky is still signed with the original key.
     */
    public const KIOSKY_SIGNATURE = '5D43Qjavxg_OZaPbJfD5a9QWhnhi6drJw8uro0Zl9I8';

    /**
     * The provisioning payload for an enrollment code (without Wi-Fi, which the console
     * adds in the browser so the password never reaches the server), or null when there
     * is no APK to provision with.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(string $code): ?array
    {
        $apkUrl = (string) Config::get('provisioning.apk_url', '');
        $checksum = [];
        if ($apkUrl === '') {
            $rel = Releases::latest('stable');
            $hex = $rel === null ? '' : strtolower((string) $rel['sha256']);
            if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
                return null;
            }
            $apkUrl = Util::baseUrl() . '/api/v1/provisioning/' . rawurlencode($code) . '/kiosky.apk';
            // The exact file the download serves: right whichever signing key the tablet checks
            $checksum['android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM']
                = rtrim(strtr(base64_encode((string) hex2bin($hex)), '+/', '-_'), '=');
        } else {
            $checksum['android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM']
                = (string) Config::get('provisioning.signature_checksum', self::KIOSKY_SIGNATURE);
        }
        $package = (string) Config::get('provisioning.package', 'uk.lp0.ha');
        return $checksum + [
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => $package . '/kiosky.AdminReceiver',
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION' => $apkUrl,
            'android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED' => true,
            'android.app.extra.PROVISIONING_SKIP_ENCRYPTION' => true,
            'android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE' => [
                'server' => Util::baseUrl(),
                'token' => $code,
            ],
        ];
    }

    /**
     * GET /api/v1/provisioning/{code}/kiosky.apk
     * The newest stable release, for a tablet being provisioned. There's no device yet,
     * so a valid enrollment code is the credential.
     */
    public static function download(Request $req): Response
    {
        RateLimiter::hit('provision:' . $req->ip(), 30, 3600);
        $code = Util::normaliseCode($req->param('code'));
        $tok = $code === '' ? null : Db::one('SELECT * FROM enrollment_tokens WHERE code = :c', ['c' => $code]);
        if ($tok === null || (int) $tok['revoked'] === 1
            || ($tok['expires_at'] !== null && strtotime($tok['expires_at'] . ' UTC') < time())
            || ($tok['max_uses'] !== null && (int) $tok['uses'] >= (int) $tok['max_uses'])) {
            throw new HttpError(403, 'enrollment_invalid', 'This enrollment code is wrong, used up or expired.');
        }
        $rel = Releases::latest('stable');
        if ($rel === null) {
            throw HttpError::notFound('Release');
        }
        return Response::file(
            Releases::dir() . '/' . basename((string) $rel['file_name']),
            'application/vnd.android.package-archive',
            'kiosky-' . $rel['version_name'] . '.apk',
            ['X-Checksum-SHA256' => (string) $rel['sha256'], 'Cache-Control' => 'no-store']
        );
    }
}
