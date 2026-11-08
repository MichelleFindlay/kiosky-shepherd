<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;

final class Audit
{
    /** @param array<string, mixed>|null $details */
    public static function log(
        ?Principal $actor,
        string $action,
        ?string $targetType = null,
        string|int|null $targetId = null,
        ?array $details = null,
        ?string $ip = null,
    ): void {
        Db::insert('audit_log', [
            'actor_type' => $actor?->type ?? 'system',
            'actor_id' => $actor?->id,
            'actor_name' => Util::clip($actor?->name ?? 'system', 100),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'details' => $details === null ? null : Db::json($details),
            'ip' => $ip ?? $actor?->ip,
        ]);
    }
}
