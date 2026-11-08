<?php

declare(strict_types=1);

namespace Shepherd;

final class Devices
{
    /** Devices with their profile name and tags. Tags are left out until the 1.3.0 migration has run. */
    public static function select(): string
    {
        $tags = Migrator::has('device_tags')
            ? "(SELECT GROUP_CONCAT(t.tag ORDER BY t.tag SEPARATOR ',') FROM device_tags t WHERE t.device_id = d.id)"
            : 'NULL';
        return "SELECT d.*, p.name AS profile_name, $tags AS tags FROM devices d JOIN profiles p ON p.id = d.profile_id";
    }

    public const MAX_TAGS = 20;

    public static function find(int $id): ?array
    {
        return Db::one(self::select() . ' WHERE d.id = :id', ['id' => $id]);
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
            'tags' => self::tagList($d['tags'] ?? null),
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

    /**
     * A device row's tags: from the tags column of select() when present, otherwise
     * looked up (rows from SELECT * FROM devices, e.g. at check-in).
     *
     * @return list<string>
     */
    public static function tagsOf(array $device): array
    {
        if (array_key_exists('tags', $device)) {
            return self::tagList($device['tags']);
        }
        if (!Migrator::has('device_tags')) {
            return [];
        }
        return array_column(Db::all('SELECT tag FROM device_tags WHERE device_id = :d ORDER BY tag', ['d' => (int) $device['id']]), 'tag');
    }

    /** @return list<string> */
    public static function tagList(?string $csv): array
    {
        return $csv === null || $csv === '' ? [] : explode(',', $csv);
    }

    /** True for a valid tag: lower-case letters, digits, - _ . and up to 32 characters. */
    public static function isTag(mixed $tag): bool
    {
        return is_string($tag) && preg_match('/^[a-z0-9][a-z0-9_.\-]{0,31}$/', $tag) === 1;
    }

    /**
     * Normalises a list of tags (or a comma/space separated string).
     *
     * @return list<string>
     * @throws Http\HttpError
     */
    public static function validateTags(mixed $tags, string $field = 'tags'): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[\s,]+/', $tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($tags) || !array_is_list($tags)) {
            throw Http\HttpError::validation([$field => 'Must be a list of tags.']);
        }
        $out = [];
        foreach ($tags as $t) {
            $t = is_string($t) ? strtolower(trim($t)) : $t;
            if (!self::isTag($t)) {
                throw Http\HttpError::validation([$field => 'Tags use lower-case letters, digits, - _ and ., up to 32 characters.']);
            }
            $out[$t] = true;
        }
        if (count($out) > self::MAX_TAGS) {
            throw Http\HttpError::validation([$field => 'At most ' . self::MAX_TAGS . ' tags per device.']);
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /** @param list<string> $tags replaces the device's tags */
    public static function setTags(int $deviceId, array $tags): void
    {
        Migrator::requireTable('device_tags');
        Db::run('DELETE FROM device_tags WHERE device_id = :d', ['d' => $deviceId]);
        foreach ($tags as $t) {
            Db::run('INSERT IGNORE INTO device_tags (device_id, tag) VALUES (:d, :t)', ['d' => $deviceId, 't' => $t]);
        }
    }

    /**
     * Records a profile move in each device's event log.
     *
     * @param list<int> $ids
     */
    public static function logProfileChange(array $ids, ?int $from, int $to, string $by): void
    {
        $names = array_column(Db::all('SELECT id, name FROM profiles'), 'name', 'id');
        foreach ($ids as $id) {
            Db::insert('device_events', ['device_id' => $id, 'type' => 'profile_changed', 'data' => Db::json([
                'from' => $from === null ? null : ($names[$from] ?? "#$from"),
                'to' => $names[$to] ?? "#$to",
                'by' => $by,
            ])]);
        }
    }

    // ---- Live view frames (storage/live/<device id>.<ext>, overwritten by each upload)

    private const LIVE_MAX_AGE = 60;

    /** @param string $ext jpg, png or webp */
    public static function saveLiveFrame(int $deviceId, string $bin, string $ext): void
    {
        $dir = Config::storage('live');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        foreach (['jpg', 'png', 'webp'] as $e) {
            if ($e !== $ext) {
                @unlink("$dir/$deviceId.$e");
            }
        }
        // Write then rename, so the console never reads a half-written frame
        $tmp = "$dir/$deviceId.$ext.tmp";
        if (file_put_contents($tmp, $bin, LOCK_EX) === false || !rename($tmp, "$dir/$deviceId.$ext")) {
            throw new \RuntimeException("Cannot write $dir/$deviceId.$ext");
        }
    }

    /** @return array{path: string, type: string, mtime: int}|null the frame, if one arrived in the last minute */
    public static function liveFrame(int $deviceId): ?array
    {
        foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] as $ext => $type) {
            $path = Config::storage("live/$deviceId.$ext");
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime >= time() - self::LIVE_MAX_AGE) {
                return ['path' => $path, 'type' => $type, 'mtime' => $mtime];
            }
        }
        return null;
    }

    /** Deletes live frames nobody has refreshed for an hour. Called from bin/cron.php. */
    public static function pruneLiveFrames(): int
    {
        $n = 0;
        foreach (glob(Config::storage('live') . '/*') ?: [] as $f) {
            if (is_file($f) && (int) filemtime($f) < time() - 3600 && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** Escapes a user string for use inside LIKE '%…%'. */
    public static function likeEscape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
