<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Commands;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Migrator;
use Shepherd\Scope;
use Shepherd\Http\Request;
use Shepherd\Http\Response;

final class CommandsApi
{
    /** Commands with device, sender and schedule names. Schedules are left out until the 1.3.0 migration has run. */
    public static function select(): string
    {
        $sched = Migrator::has('scheduled_commands');
        return 'SELECT c.*, d.name AS device_name, a.username AS created_by_name, '
            . ($sched ? 's.name' : 'NULL') . ' AS schedule_name
                  FROM commands c
                  JOIN devices d ON d.id = c.device_id
                  LEFT JOIN admins a ON a.id = c.created_by'
            . ($sched ? ' LEFT JOIN scheduled_commands s ON s.id = c.schedule_id' : '');
    }

    /** GET /api/v1/admin/commands/types */
    public static function types(Request $req): Response
    {
        AdminAuth::require($req);
        $out = [];
        foreach (Commands::TYPES as $type => $def) {
            $out[] = ['type' => $type, 'label' => $def['label'], 'params' => (object) $def['params']];
        }
        return Response::json($out);
    }

    /** GET /api/v1/admin/commands?device_id=&status=&type=&has_attachment=1&limit=&offset= */
    public static function list(Request $req): Response
    {
        $p = AdminAuth::require($req);
        [$scopeCond, $params] = Scope::condition($p);
        $where = [$scopeCond];
        if (($did = $req->queryString('device_id')) !== null && ctype_digit($did)) {
            $where[] = 'c.device_id = :d';
            $params['d'] = (int) $did;
        }
        if (($st = $req->queryString('status')) !== null) {
            $where[] = 'c.status = :s';
            $params['s'] = $st;
        }
        if (($type = $req->queryString('type')) !== null) {
            $where[] = 'c.type = :t';
            $params['t'] = $type;
        }
        if ($req->queryString('has_attachment') === '1') {
            $where[] = 'c.attachment IS NOT NULL';
        }
        $limit = $req->queryInt('limit', 100, 1, 1000);
        $offset = $req->queryInt('offset', 0, 0, PHP_INT_MAX);
        $rows = Db::all(self::select() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.id DESC LIMIT :l OFFSET :o', $params + ['l' => $limit, 'o' => $offset]);
        return Response::json(['items' => array_map([Commands::class, 'present'], $rows)]);
    }

    /**
     * POST /api/v1/admin/commands
     * {type, params?, ttl_hours?, device_ids?: [..] | profile_id?: n | tag?: "x" | all?: true}
     */
    public static function create(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $type = (string) $req->input('type', '');
        $params = Commands::validate($type, $req->input('params'));

        $target = Commands::normaliseTarget($req->json());
        $targetIds = Scope::filterIds($p, Commands::resolveTargets($target));
        if ($targetIds === []) {
            throw HttpError::validation(['device_ids' => 'No active devices match.']);
        }
        $ttl = $req->input('ttl_hours');
        $cmdIds = Commands::queue($targetIds, $type, $params, $p, is_int($ttl) ? $ttl : null);
        Audit::log($p, 'command.queued_bulk', 'command', null,
            ['type' => $type, 'devices' => count($targetIds), 'target' => Commands::describeTarget($target)]);
        return Response::json(['queued' => count($cmdIds), 'command_ids' => $cmdIds], 201);
    }

    /** DELETE /api/v1/admin/commands/{id}  cancels a command that hasn't finished */
    public static function cancel(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $deviceId = Db::value('SELECT device_id FROM commands WHERE id = :id', ['id' => $id]);
        if ($deviceId === null || !Scope::canSee($p, (int) $deviceId)) {
            throw HttpError::notFound('Command');
        }
        $n = Db::run("UPDATE commands SET status = 'cancelled', completed_at = NOW()
                       WHERE id = :id AND status IN ('pending', 'delivered')", ['id' => $id])->rowCount();
        if ($n === 0) {
            throw HttpError::conflict('Only pending or delivered commands can be cancelled.');
        }
        Audit::log($p, 'command.cancelled', 'command', $id);
        return Response::json(Commands::present(Db::one(self::select() . ' WHERE c.id = :id', ['id' => $id])));
    }

    /** GET /api/v1/admin/commands/{id}/screenshot */
    public static function screenshot(Request $req): Response
    {
        $p = AdminAuth::require($req);
        $row = Db::one('SELECT device_id, attachment FROM commands WHERE id = :id', ['id' => $req->intParam('id')]);
        if ($row === null || $row['attachment'] === null || !Scope::canSee($p, (int) $row['device_id'])) {
            throw HttpError::notFound('Screenshot');
        }
        $name = basename((string) $row['attachment']);
        $type = match (pathinfo($name, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        return Response::file(Config::storage('screenshots') . '/' . $name, $type, null,
            ['Cache-Control' => 'private, max-age=86400']);
    }
}
