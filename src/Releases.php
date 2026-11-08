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

    /** The release a device should be on under its policy, or null. */
    public static function targetFor(array $policy): ?array
    {
        $updates = $policy['updates'] ?? [];
        if (($updates['source'] ?? 'off') !== 'server') {
            return null;
        }
        $want = (string) ($updates['release'] ?? 'latest');
        // "latest" follows the device's channel; a pinned version is used whatever its channel
        $rel = $want === 'latest'
            ? self::latest((string) ($updates['channel'] ?? 'stable'))
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
            'channel' => $rel['channel'] ?? 'stable',
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
            'channel' => $r['channel'] ?? 'stable',
            'uploaded_by' => $r['uploaded_by_name'] ?? null,
            'created_at' => Util::iso($r['created_at']),
            'devices_on_version' => isset($r['devices_on_version']) ? (int) $r['devices_on_version'] : null,
        ];
    }
}
