<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;

/**
 * Devices authenticate with "Authorization: Bearer <uid>.<secret>", the token
 * returned at enrollment. Only a SHA-256 hash of the secret is stored.
 */
final class DeviceAuth
{
    /** @return array{0: Principal, 1: array<string, mixed>} principal and the device row */
    public static function require(Request $req): array
    {
        $token = $req->bearer();
        if ($token === null || !preg_match('/^([0-9a-z]{16})\.([A-Za-z0-9_\-]{20,128})$/', $token, $m)) {
            throw new HttpError(401, 'device_unauthorized', 'Missing or malformed device token.');
        }
        $device = Db::one('SELECT * FROM devices WHERE uid = :uid', ['uid' => $m[1]]);
        if ($device === null || !hash_equals((string) $device['secret_hash'], hash('sha256', $m[2]))) {
            throw new HttpError(401, 'device_unauthorized', 'Unknown device or wrong secret.');
        }
        if ($device['status'] === 'retired') {
            // Tells the app it has been released from management (see docs/PROTOCOL.md)
            throw new HttpError(410, 'device_retired', 'This device has been removed from management.');
        }
        $p = new Principal('device', (int) $device['id'], (string) $device['name'], 'device', $req->ip());
        return [$p, $device];
    }

    /** @return array{0: string, 1: string} [secret, sha256 hash] */
    public static function newSecret(): array
    {
        $secret = \Shepherd\Util::token(32);
        return [$secret, hash('sha256', $secret)];
    }
}
