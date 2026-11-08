<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;
use Shepherd\Http\HttpError;

/**
 * Scoped users (viewers and operators with scope_tags) only see and act on devices
 * carrying at least one of their tags. Everything device-related goes through here.
 * Out-of-scope devices answer 404, as if they didn't exist.
 */
final class Scope
{
    /**
     * SQL condition limiting a devices alias to the user's scope, and its parameters.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function condition(Principal $p, string $alias = 'd'): array
    {
        if (!$p->isScoped()) {
            return ['1 = 1', []];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values((array) $p->scopeTags) as $i => $tag) {
            $placeholders[] = ":scope_$i";
            $params["scope_$i"] = $tag;
        }
        return ["EXISTS (SELECT 1 FROM device_tags scope_t WHERE scope_t.device_id = $alias.id AND scope_t.tag IN ("
            . implode(', ', $placeholders) . '))', $params];
    }

    public static function canSee(Principal $p, int $deviceId): bool
    {
        if (!$p->isScoped()) {
            return true;
        }
        [$cond, $params] = self::condition($p);
        return Db::value("SELECT COUNT(*) FROM devices d WHERE d.id = :id AND $cond", ['id' => $deviceId] + $params) > 0;
    }

    /** Throws 404 for a device outside the user's scope. */
    public static function requireDevice(Principal $p, int $deviceId): void
    {
        if (!self::canSee($p, $deviceId)) {
            throw HttpError::notFound('Device');
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int> the ids inside the user's scope
     */
    public static function filterIds(Principal $p, array $ids): array
    {
        if (!$p->isScoped() || $ids === []) {
            return $ids;
        }
        [$cond, $params] = self::condition($p);
        $in = implode(',', array_map('intval', $ids));
        return array_map(static fn ($r) => (int) $r['id'],
            Db::all("SELECT d.id FROM devices d WHERE d.id IN ($in) AND $cond", $params));
    }

    /** For fleet-wide features (enrollment codes, schedules) that scoped users can't use. */
    public static function forbid(Principal $p, string $what): void
    {
        if ($p->isScoped()) {
            throw HttpError::forbidden("Users limited to some devices can't manage $what.");
        }
    }

    /**
     * Stops a scoped user removing every one of their tags from a device, which would
     * hide it from them.
     *
     * @param list<string> $newTags
     */
    public static function checkKeepsAccess(Principal $p, array $newTags): void
    {
        if ($p->isScoped() && array_intersect($newTags, (array) $p->scopeTags) === []) {
            throw HttpError::validation(['tags' => 'Keep at least one of your tags (' . implode(', ', (array) $p->scopeTags)
                . ') on the device, or you will lose access to it.']);
        }
    }
}
