<?php

declare(strict_types=1);

namespace Shepherd;

final class Releases
{
    public static function dir(): string
    {
        return Config::storage('apk');
    }

    /** The release a device should be on under its policy, or null. */
    public static function targetFor(array $policy): ?array
    {
        $updates = $policy['updates'] ?? [];
        if (($updates['source'] ?? 'off') !== 'server') {
            return null;
        }
        $want = (string) ($updates['release'] ?? 'latest');
        $rel = $want === 'latest'
            ? Db::one('SELECT * FROM releases WHERE active = 1 ORDER BY version_code DESC LIMIT 1')
            : Db::one('SELECT * FROM releases WHERE version_name = :v AND active = 1', ['v' => $want]);
        if ($rel === null) {
            return null;
        }
        return [
            'version' => $rel['version_name'],
            'version_code' => (int) $rel['version_code'],
            'size' => (int) $rel['size'],
            'sha256' => $rel['sha256'],
            'url' => Util::baseUrl() . '/api/v1/device/releases/' . (int) $rel['id'] . '/download',
        ];
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'version_name' => $r['version_name'],
            'version_code' => (int) $r['version_code'],
            'sha256' => $r['sha256'],
            'size' => (int) $r['size'],
            'notes' => $r['notes'],
            'active' => (bool) $r['active'],
            'uploaded_by' => $r['uploaded_by_name'] ?? null,
            'created_at' => Util::iso($r['created_at']),
            'devices_on_version' => isset($r['devices_on_version']) ? (int) $r['devices_on_version'] : null,
        ];
    }
}
