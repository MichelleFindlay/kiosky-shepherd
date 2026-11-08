<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Commands;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;

final class CommandsApi
{
    public const SELECT = 'SELECT c.*, d.name AS device_name, a.username AS created_by_name
                             FROM commands c
                             JOIN devices d ON d.id = c.device_id
                             LEFT JOIN admins a ON a.id = c.created_by';

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

    /** GET /api/v1/admin/commands?device_id=&status=&limit=&offset= */
    public static function list(Request $req): Response
    {
        AdminAuth::require($req);
        $where = [];
        $params = [];
        if (($did = $req->queryString('device_id')) !== null && ctype_digit($did)) {
            $where[] = 'c.device_id = :d';
            $params['d'] = (int) $did;
        }
        if (($st = $req->queryString('status')) !== null) {
            $where[] = 'c.status = :s';
            $params['s'] = $st;
        }
        $limit = $req->queryInt('limit', 100, 1, 1000);
        $offset = $req->queryInt('offset', 0, 0, PHP_INT_MAX);
        $rows = Db::all(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY c.id DESC LIMIT :l OFFSET :o', $params + ['l' => $limit, 'o' => $offset]);
        return Response::json(['items' => array_map([Commands::class, 'present'], $rows)]);
    }

    /**
     * POST /api/v1/admin/commands
     * {type, params?, ttl_hours?, device_ids?: [..] | profile_id?: n | all?: true}
     */
    public static function create(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $type = (string) $req->input('type', '');
        $params = Commands::validate($type, $req->input('params'));

        $ids = $req->input('device_ids');
        $profileId = $req->input('profile_id');
        if (is_array($ids) && $ids !== []) {
            $ids = array_values(array_unique(array_filter($ids, 'is_int')));
            if ($ids === []) {
                throw HttpError::validation(['device_ids' => 'Must be a list of device ids.']);
            }
            $in = implode(',', $ids); // integers only, filtered above
            $targets = Db::all("SELECT id FROM devices WHERE status = 'active' AND id IN ($in)");
        } elseif (is_int($profileId)) {
            $targets = Db::all("SELECT id FROM devices WHERE status = 'active' AND profile_id = :p", ['p' => $profileId]);
        } elseif ($req->input('all') === true) {
            $targets = Db::all("SELECT id FROM devices WHERE status = 'active'");
        } else {
            throw HttpError::validation(['device_ids' => 'Choose devices, a profile_id, or all: true.']);
        }
        $targetIds = array_map(static fn ($r) => (int) $r['id'], $targets);
        if ($targetIds === []) {
            throw HttpError::validation(['device_ids' => 'No active devices match.']);
        }
        $ttl = $req->input('ttl_hours');
        $cmdIds = Commands::queue($targetIds, $type, $params, $p, is_int($ttl) ? $ttl : null);
        Audit::log($p, 'command.queued_bulk', 'command', null,
            ['type' => $type, 'devices' => count($targetIds), 'profile_id' => is_int($profileId) ? $profileId : null]);
        return Response::json(['queued' => count($cmdIds), 'command_ids' => $cmdIds], 201);
    }

    /** DELETE /api/v1/admin/commands/{id}  cancels a command that hasn't finished */
    public static function cancel(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $n = Db::run("UPDATE commands SET status = 'cancelled', completed_at = NOW()
                       WHERE id = :id AND status IN ('pending', 'delivered')", ['id' => $id])->rowCount();
        if ($n === 0) {
            throw HttpError::conflict('Only pending or delivered commands can be cancelled.');
        }
        Audit::log($p, 'command.cancelled', 'command', $id);
        return Response::json(Commands::present(Db::one(self::SELECT . ' WHERE c.id = :id', ['id' => $id])));
    }

    /** GET /api/v1/admin/commands/{id}/screenshot */
    public static function screenshot(Request $req): Response
    {
        AdminAuth::require($req);
        $row = Db::one('SELECT attachment FROM commands WHERE id = :id', ['id' => $req->intParam('id')]);
        if ($row === null || $row['attachment'] === null) {
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
