<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;

/** Every saved state of each profile (profile_versions), for diffs and one-click restore. */
final class ProfileHistory
{
    private const KEEP = 200;

    /** Snapshots the profile as it is now. Call after each change. */
    public static function record(int $profileId, string $action, ?Principal $by, ?string $note = null): void
    {
        if (!Migrator::has('profile_versions')) {
            return;
        }
        $p = Db::one('SELECT name, description, settings FROM profiles WHERE id = :id', ['id' => $profileId]);
        if ($p === null) {
            return;
        }
        Db::insert('profile_versions', [
            'profile_id' => $profileId,
            'name' => $p['name'],
            'description' => $p['description'],
            'settings' => $p['settings'],
            'action' => $action,
            'note' => Util::clip($note, 255),
            'changed_by' => $by?->adminId,
            'changed_by_name' => Util::clip($by?->name ?? 'system', 100),
        ]);
        $cut = Db::value('SELECT id FROM profile_versions WHERE profile_id = :p ORDER BY id DESC LIMIT 1 OFFSET :o',
            ['p' => $profileId, 'o' => self::KEEP]);
        if ($cut !== null) {
            Db::run('DELETE FROM profile_versions WHERE profile_id = :p AND id <= :c', ['p' => $profileId, 'c' => (int) $cut]);
        }
    }

    /**
     * What changed from one snapshot to the next. Technician PINs show only as "set".
     *
     * @return list<array{key: string, change: string, from: mixed, to: mixed}>
     */
    public static function diff(?array $old, array $new): array
    {
        $out = [];
        foreach (['name', 'description'] as $meta) {
            if ($old !== null && $old[$meta] !== $new[$meta]) {
                $out[] = ['key' => $meta, 'change' => 'changed', 'from' => $old[$meta], 'to' => $new[$meta]];
            }
        }
        $a = $old === null ? [] : Db::decode($old['settings']);
        $b = Db::decode($new['settings']);
        $ra = Policy::redact($a);
        $rb = Policy::redact($b);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            $inA = array_key_exists($key, $a);
            $inB = array_key_exists($key, $b);
            if ($inA && $inB && Db::json($a[$key]) === Db::json($b[$key])) {
                continue;
            }
            $out[] = [
                'key' => (string) $key,
                'change' => !$inA ? 'set' : (!$inB ? 'unset' : 'changed'),
                'from' => $inA ? $ra[$key] : null,
                'to' => $inB ? $rb[$key] : null,
            ];
        }
        return $out;
    }

    /** Versions newest first, each with its changes from the version before. */
    public static function list(int $profileId, int $limit = 100): array
    {
        if (!Migrator::has('profile_versions')) {
            return [];
        }
        // One extra, so the oldest shown can be diffed against its predecessor
        $rows = Db::all('SELECT * FROM profile_versions WHERE profile_id = :p ORDER BY id DESC LIMIT :l',
            ['p' => $profileId, 'l' => $limit + 1]);
        $out = [];
        foreach (array_slice($rows, 0, $limit) as $i => $r) {
            $prev = $rows[$i + 1] ?? null;
            $out[] = [
                'id' => (int) $r['id'],
                'action' => $r['action'],
                'note' => $r['note'],
                'changed_by' => $r['changed_by_name'] !== '' ? $r['changed_by_name'] : null,
                'created_at' => Util::iso($r['created_at']),
                'name' => $r['name'],
                'changes' => self::diff($prev, $r),
                'is_current' => $i === 0,
            ];
        }
        return $out;
    }
}
