<?php

declare(strict_types=1);

namespace Shepherd;

final class Releases
{
    public static function dir(): string
    {
        return Config::storage('apk');
    }

    // ---- Release rings: a release chosen per device tag, for staged rollouts ----

    /** @var array<string, string>|null tag => 'latest' or version name, per request */
    private static ?array $rings = null;

    /** @return array<string, string> */
    public static function rings(): array
    {
        if (self::$rings === null) {
            self::$rings = Migrator::has('tag_releases')
                ? array_column(Db::all('SELECT tag, release_version FROM tag_releases'), 'release_version', 'tag')
                : [];
        }
        return self::$rings;
    }

    public static function forgetRings(): void
    {
        self::$rings = null;
    }

    /**
     * The ring that applies to a device with these tags. When several of its tags have
     * rings, the newest release wins ("latest" beats any pinned version), so a device
     * in both "canary" and "lobby" follows the canary.
     *
     * @param list<string> $tags
     * @return array{tag: string, release: string}|null
     */
    public static function ringFor(array $tags): ?array
    {
        $rings = self::rings();
        $best = null;
        $bestCode = -1;
        foreach ($tags as $tag) {
            if (!isset($rings[$tag])) {
                continue;
            }
            $code = self::versionRank($rings[$tag]);
            if ($code > $bestCode) {
                $best = ['tag' => $tag, 'release' => $rings[$tag]];
                $bestCode = $code;
            }
        }
        return $best;
    }

    /** Sort key for a release choice: "latest" highest, unknown versions lowest. */
    private static function versionRank(string $release): int
    {
        static $codes = null;
        $codes ??= array_map('intval', array_column(Db::all('SELECT version_name, version_code FROM releases'), 'version_code', 'version_name'));
        return $release === 'latest' ? PHP_INT_MAX : ($codes[$release] ?? 0);
    }

    public const CHANNELS = ['stable', 'dev'];

    /** True once the 1.5.0 migration has added releases.channel. */
    public static function hasChannels(): bool
    {
        return Migrator::hasColumn('releases', 'channel');
    }

    /**
     * The newest offered release a channel gets: stable sees only stable builds, dev
     * sees both (so a stable release newer than the last dev build still wins).
     */
    public static function latest(string $channel = 'stable'): ?array
    {
        $stableOnly = $channel !== 'dev' && self::hasChannels();
        return Db::one('SELECT * FROM releases WHERE active = 1' . ($stableOnly ? " AND channel = 'stable'" : '')
            . ' ORDER BY version_code DESC LIMIT 1');
    }

    /** True once the 1.8.0 migration has added staged rollouts and health counts. */
    public static function hasRollouts(): bool
    {
        return Migrator::hasColumn('releases', 'rollout_hours');
    }

    /**
     * The release a device should be on under its policy, or null.
     *
     * With staged rollouts, a release whose rollout hasn't reached this device yet, or
     * that is paused, isn't offered: "latest" then falls back to the newest release
     * that is (devices only ever move up, so one already newer simply stays put). A
     * pinned version that isn't offered yet gives no update.
     */
    public static function targetFor(array $policy, ?array $device = null): ?array
    {
        $updates = $policy['updates'] ?? [];
        if (($updates['source'] ?? 'off') !== 'server') {
            return null;
        }
        $want = (string) ($updates['release'] ?? 'latest');
        if ($want === 'latest') {
            $channel = (string) ($updates['channel'] ?? 'stable');
            $stableOnly = $channel !== 'dev' && self::hasChannels();
            $candidates = self::hasRollouts()
                ? Db::all('SELECT * FROM releases WHERE active = 1' . ($stableOnly ? " AND channel = 'stable'" : '')
                    . ' ORDER BY version_code DESC LIMIT 20')
                : array_filter([self::latest($channel)]);
        } else {
            $candidates = array_filter([Db::one('SELECT * FROM releases WHERE version_name = :v AND active = 1', ['v' => $want])]);
        }
        $rel = null;
        foreach ($candidates as $c) {
            if (self::offeredTo($c, $device)) {
                $rel = $c;
                break;
            }
        }
        if ($rel === null) {
            return null;
        }
        return [
            'version' => $rel['version_name'],
            'version_code' => (int) $rel['version_code'],
            'size' => (int) $rel['size'],
            'sha256' => $rel['sha256'],
            'url' => Util::baseUrl() . '/api/v1/device/releases/' . (int) $rel['id'] . '/download',
            'channel' => $rel['channel'] ?? 'stable',
        ];
    }

    /** Whether a release is offered to this device now: not paused, and its rollout has reached it. */
    public static function offeredTo(array $rel, ?array $device): bool
    {
        if (!empty($rel['halted_at'])) {
            return false;
        }
        $hours = (int) ($rel['rollout_hours'] ?? 0);
        if ($hours <= 0) {
            return true;
        }
        if ($device === null) {
            return false; // no place in the queue: wait for the rollout to finish
        }
        return self::rolloutFraction($rel) >= self::devicePlace((string) ($device['uid'] ?? $device['id'] ?? ''));
    }

    /** How far a rollout has got: 0 at its start, 1 when every device is included. */
    public static function rolloutFraction(array $rel): float
    {
        $hours = (int) ($rel['rollout_hours'] ?? 0);
        if ($hours <= 0) {
            return 1.0;
        }
        $start = strtotime(((string) ($rel['rollout_start'] ?? $rel['created_at'] ?? '')) . ' UTC');
        if ($start === false) {
            return 1.0;
        }
        return max(0.0, min(1.0, (time() - $start) / ($hours * 3600)));
    }

    /** A device's fixed place in every rollout queue, from 0 to 1, by its id. */
    public static function devicePlace(string $uid): float
    {
        return hexdec(substr(hash('sha256', 'rollout:' . $uid), 0, 7)) / 0xFFFFFFF;
    }

    /**
     * A Kiosky 1.8.0+ device reported how a newly installed version is doing
     * (update_healthy / update_unhealthy). Counts it, and pauses the release's rollout
     * once rollouts.halt_after devices (default 1) report it unhealthy.
     */
    public static function recordHealth(array $device, string $type, mixed $data): void
    {
        if (!self::hasRollouts() || !is_array($data) || !is_int($data['version_code'] ?? null)) {
            return;
        }
        $rel = Db::one('SELECT * FROM releases WHERE version_code = :c', ['c' => $data['version_code']]);
        if ($rel === null) {
            return;
        }
        $healthy = $type === 'update_healthy';
        Db::run('UPDATE releases SET ' . ($healthy ? 'healthy_count = healthy_count + 1' : 'unhealthy_count = unhealthy_count + 1')
            . ' WHERE id = :id', ['id' => (int) $rel['id']]);
        if ($healthy || !empty($rel['halted_at'])) {
            return;
        }
        $limit = max(1, (int) Config::get('rollouts.halt_after', 1));
        if ((int) $rel['unhealthy_count'] + 1 < $limit) {
            return;
        }
        $crash = is_string($data['last_crash'] ?? null) ? Util::clip($data['last_crash'], 150) : null;
        $reason = 'Crashing after the update on ' . $device['name'] . ($crash ? ": $crash" : '');
        $paused = Db::run('UPDATE releases SET halted_at = NOW(), halt_reason = :r WHERE id = :id AND halted_at IS NULL',
            ['r' => Util::clip($reason, 255), 'id' => (int) $rel['id']])->rowCount() > 0;
        if (!$paused) {
            return;
        }
        Db::insert('device_events', ['device_id' => (int) $device['id'], 'type' => 'release_halted',
            'data' => Db::json(['version' => $rel['version_name'], 'reason' => $reason])]);
        Events::emit('release.halted', ['release' => $rel['version_name'], 'version_code' => (int) $rel['version_code'],
            'reason' => $reason, 'device' => Events::device($device)]);
        if (Alerts::configured()) {
            Alerts::send('release_halted', "Kiosky {$rel['version_name']} rollout paused",
                "$reason.\n\nNo more devices will be offered {$rel['version_name']} until you resume it under Releases. "
                . 'Android can\'t downgrade apps, so devices that already have it need a newer release.');
        }
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
            'channel' => $r['channel'] ?? 'stable',
            'uploaded_by' => $r['uploaded_by_name'] ?? null,
            'created_at' => Util::iso($r['created_at']),
            'devices_on_version' => isset($r['devices_on_version']) ? (int) $r['devices_on_version'] : null,
            'rollout_hours' => (int) ($r['rollout_hours'] ?? 0),
            'rollout_start' => Util::iso($r['rollout_start'] ?? null),
            'rollout_percent' => (int) floor(self::rolloutFraction($r) * 100),
            'halted_at' => Util::iso($r['halted_at'] ?? null),
            'halt_reason' => $r['halt_reason'] ?? null,
            'healthy_count' => (int) ($r['healthy_count'] ?? 0),
            'unhealthy_count' => (int) ($r['unhealthy_count'] ?? 0),
        ];
    }
}
