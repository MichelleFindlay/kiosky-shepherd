<?php

declare(strict_types=1);

namespace Shepherd;

final class Devices
{
    public const SELECT = 'SELECT d.*, p.name AS profile_name FROM devices d JOIN profiles p ON p.id = d.profile_id';

    public static function find(int $id): ?array
    {
        return Db::one(self::SELECT . ' WHERE d.id = :id', ['id' => $id]);
    }

    public static function isOnline(array $d): bool
    {
        if ($d['last_seen_at'] === null || $d['status'] !== 'active') {
            return false;
        }
        $age = time() - (int) strtotime($d['last_seen_at'] . ' UTC');
        return $age <= max(120, 3 * (int) $d['checkin_seconds']);
    }

    public static function present(array $d, bool $full = false): array
    {
        $policy = $d['status'] === 'active' ? Policy::forDevice($d) : null;
        $out = [
            'id' => (int) $d['id'],
            'uid' => $d['uid'],
            'name' => $d['name'],
            'profile_id' => (int) $d['profile_id'],
            'profile_name' => $d['profile_name'] ?? null,
            'status' => $d['status'],
            'online' => self::isOnline($d),
            'policy_version' => $policy['version'] ?? null,
            'applied_policy_version' => $d['applied_policy_version'],
            'policy_current' => $policy !== null && $d['applied_policy_version'] === $policy['version'],
            'manufacturer' => $d['manufacturer'],
            'model' => $d['model'],
            'android_version' => $d['android_version'],
            'sdk_int' => $d['sdk_int'] === null ? null : (int) $d['sdk_int'],
            'app_version' => $d['app_version'],
            'app_version_code' => $d['app_version_code'] === null ? null : (int) $d['app_version_code'],
            'state' => Db::decode($d['state'], null),
            'has_overrides' => Db::decode($d['overrides']) !== [],
            'last_seen_at' => Util::iso($d['last_seen_at']),
            'last_ip' => $d['last_ip'],
            'enrolled_at' => Util::iso($d['enrolled_at']),
            'retired_at' => Util::iso($d['retired_at']),
        ];
        if ($full) {
            $out['notes'] = $d['notes'];
            $out['android_id'] = $d['android_id'];
            $out['checkin_seconds'] = (int) $d['checkin_seconds'];
            $out['overrides'] = (object) Policy::redact(Db::decode($d['overrides']));
        }
        return $out;
    }

    /** Escapes a user string for use inside LIKE '%…%'. */
    public static function likeEscape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
