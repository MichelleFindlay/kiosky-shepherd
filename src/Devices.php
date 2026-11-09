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
            Events::emit('device.profile_changed', ['device' => Events::device(self::find($id) ?? ['id' => $id]),
                'from' => $from === null ? null : ($names[$from] ?? null), 'to' => $names[$to] ?? null, 'by' => $by]);
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

    /** Problem keys, in the order they're shown, with the setting that switches each alert on. */
    public const PROBLEMS = [
        'offline' => 'Offline',
        'policy' => 'Policy not applied',
        'permission' => 'Missing "Display over other apps" permission',
        'lockdown' => 'Lockdown can be escaped',
        'page_error' => 'Page error',
        'low_uptime' => 'Low uptime',
        'low_battery' => 'Low battery',
        'battery_hot' => 'Battery hot',
        'battery_health' => 'Battery failing',
    ];

    /**
     * What's wrong with a device, as key => plain-language reason. $d is the output of
     * present() plus uptime_7d. Used by the Overview and by alerts.
     *
     * @return array<string, string>
     */
    public static function problems(array $d, int $batteryBelow = 20): array
    {
        $out = [];
        $state = is_array($d['state'] ?? null) ? $d['state'] : [];
        if (!$d['online']) {
            $out['offline'] = $d['last_seen_at'] === null ? 'never checked in' : 'offline';
        } elseif (!$d['policy_current']) {
            $out['policy'] = 'policy not applied yet';
        }
        if (($state['start_on_boot_permission'] ?? true) === false) {
            $out['permission'] = 'missing "Display over other apps" permission';
        }
        if (($state['lockdown'] ?? false) === true) {
            if (($state['lockdown_mode'] ?? null) === 'partial' && ($state['accessibility_helper'] ?? true) === false) {
                $out['lockdown'] = 'lockdown can be escaped: accessibility helper is off';
            } elseif (in_array($state['lockdown_mode'] ?? null, ['owner', 'mdm'], true) && ($state['lock_task'] ?? true) === false) {
                $out['lockdown'] = 'lockdown on but not locked';
            }
        }
        if (!empty($state['page_error'])) {
            $out['page_error'] = 'page error';
        }
        // Only once a device has a day of history, so a new tablet isn't flagged for its first hours
        $uptime = $d['uptime_7d'] ?? null;
        if ($uptime !== null && $uptime < 90 && strtotime((string) $d['enrolled_at']) < time() - 86400) {
            $out['low_uptime'] = "online only {$uptime}% in the last 7 days";
        }
        $battery = $state['battery_level'] ?? null;
        if ($batteryBelow > 0 && is_int($battery) && $battery < $batteryBelow && ($state['charging'] ?? false) !== true && $d['online']) {
            $out['low_battery'] = "battery at {$battery}% and not charging";
        }
        // Kiosky 1.7.0+: tablets charging around the clock can overheat and swell
        $hotAt = (int) Config::get('alerts.battery_hot', 45);
        $temp = $state['battery_temperature'] ?? null;
        if ($hotAt > 0 && (is_int($temp) || is_float($temp)) && $temp >= $hotAt && $d['online']) {
            $out['battery_hot'] = 'battery at ' . round((float) $temp) . ' °C';
        }
        $health = $state['battery_health'] ?? null;
        if (in_array($health, ['overheat', 'dead', 'over_voltage', 'failure'], true) && $d['online']) {
            $out['battery_health'] = 'battery reports ' . str_replace('_', ' ', (string) $health);
        }
        return $out;
    }

    /** Escapes a user string for use inside LIKE '%…%'. */
    public static function likeEscape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
