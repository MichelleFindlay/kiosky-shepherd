<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Commands;
use Shepherd\Db;
use Shepherd\Devices;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Policy;
use Shepherd\Releases;
use Shepherd\Util;

final class DevicesApi
{
    /** GET /api/v1/admin/devices?q=&profile_id=&status=active|retired|all&online=1|0&limit=&offset= */
    public static function list(Request $req): Response
    {
        AdminAuth::require($req);
        $where = [];
        $params = [];

        $status = $req->queryString('status') ?? 'active';
        if ($status !== 'all') {
            if (!in_array($status, ['active', 'retired'], true)) {
                throw HttpError::validation(['status' => 'Use active, retired or all.']);
            }
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }
        if (($q = $req->queryString('q')) !== null) {
            $like = '%' . Devices::likeEscape($q) . '%';
            $where[] = '(d.name LIKE :q1 OR d.model LIKE :q2 OR d.uid LIKE :q3 OR d.last_ip LIKE :q4 OR d.notes LIKE :q5)';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }
        if (($pid = $req->queryString('profile_id')) !== null && ctype_digit($pid)) {
            $where[] = 'd.profile_id = :pid';
            $params['pid'] = (int) $pid;
        }
        $sql = Devices::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.name, d.id';
        $rows = Db::all($sql, $params);

        $items = array_map(static fn ($d) => Devices::present($d), $rows);
        $online = $req->queryString('online');
        if ($online !== null) {
            $want = $online === '1' || $online === 'true';
            $items = array_values(array_filter($items, static fn ($d) => $d['online'] === $want));
        }
        $total = count($items);
        $limit = $req->queryInt('limit', 500, 1, 5000);
        $offset = $req->queryInt('offset', 0, 0, PHP_INT_MAX);
        return Response::json(['items' => array_slice($items, $offset, $limit), 'total' => $total]);
    }

    /** GET /api/v1/admin/devices/{id} */
    public static function get(Request $req): Response
    {
        AdminAuth::require($req);
        $d = Devices::find($req->intParam('id')) ?? throw HttpError::notFound('Device');
        return Response::json(Devices::present($d, true));
    }

    /** PATCH /api/v1/admin/devices/{id}  {name?, profile_id?, notes?, overrides?: {key: value|null}, clear_overrides?: bool} */
    public static function update(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $d = Devices::find($id) ?? throw HttpError::notFound('Device');
        $body = $req->json();
        $changes = [];
        $errors = [];

        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : '';
            if ($name === '' || mb_strlen($name) > 100) {
                $errors['name'] = 'Enter a name of up to 100 characters.';
            } else {
                $changes['name'] = $name;
            }
        }
        if (array_key_exists('notes', $body)) {
            if ($body['notes'] !== null && (!is_string($body['notes']) || mb_strlen($body['notes']) > 5000)) {
                $errors['notes'] = 'Notes must be text of up to 5000 characters.';
            } else {
                $changes['notes'] = $body['notes'];
            }
        }
        if (array_key_exists('profile_id', $body)) {
            $pid = $body['profile_id'];
            if (!is_int($pid) || Db::value('SELECT id FROM profiles WHERE id = :id', ['id' => $pid]) === null) {
                $errors['profile_id'] = 'Choose an existing profile.';
            } else {
                $changes['profile_id'] = $pid;
            }
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        $overrides = Db::decode($d['overrides']);
        $overridesChanged = false;
        if (!empty($body['clear_overrides'])) {
            $overrides = [];
            $overridesChanged = true;
        }
        if (array_key_exists('overrides', $body)) {
            if (!is_array($body['overrides']) || ($body['overrides'] !== [] && array_is_list($body['overrides']))) {
                throw HttpError::validation(['overrides' => 'Must be an object of setting keys.']);
            }
            $overrides = Policy::apply($overrides, Policy::validate($body['overrides']));
            $overridesChanged = true;
        }
        if ($overridesChanged) {
            $changes['overrides'] = Db::json((object) $overrides);
        }

        if ($changes !== []) {
            Db::update('devices', $changes, ['id' => $id]);
            $logged = $changes;
            if (isset($logged['overrides'])) {
                $logged['overrides'] = array_keys($overrides);
            }
            Audit::log($p, 'device.updated', 'device', $id, $logged);
        }
        return Response::json(Devices::present(Devices::find($id), true));
    }

    /** DELETE /api/v1/admin/devices/{id}  retires; ?purge=1 on a retired device deletes it */
    public static function retire(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $d = Devices::find($id) ?? throw HttpError::notFound('Device');

        if ($d['status'] === 'retired') {
            if (($req->query['purge'] ?? '') !== '1') {
                throw HttpError::conflict('The device is already retired. Add ?purge=1 to delete its record.');
            }
            $p->require('admin');
            Db::run('DELETE FROM devices WHERE id = :id', ['id' => $id]);
            Audit::log($p, 'device.purged', 'device', $id, ['name' => $d['name']]);
            return Response::noContent();
        }

        Db::transaction(static function () use ($id): void {
            Db::run("UPDATE devices SET status = 'retired', retired_at = NOW() WHERE id = :id", ['id' => $id]);
            Db::run("UPDATE commands SET status = 'cancelled', completed_at = NOW()
                      WHERE device_id = :id AND status IN ('pending', 'delivered')", ['id' => $id]);
            Db::insert('device_events', ['device_id' => $id, 'type' => 'retired', 'data' => null]);
        });
        Audit::log($p, 'device.retired', 'device', $id, ['name' => $d['name']]);
        return Response::json(Devices::present(Devices::find($id), true));
    }

    /** GET /api/v1/admin/devices/{id}/policy  effective settings and where each came from */
    public static function policy(Request $req): Response
    {
        AdminAuth::require($req);
        $d = Devices::find($req->intParam('id')) ?? throw HttpError::notFound('Device');
        $profile = Policy::profileSettings((int) $d['profile_id']);
        $overrides = Db::decode($d['overrides']);
        $resolved = Policy::resolve($profile, $overrides);
        $devicePolicy = Policy::toDevice($resolved['values']);
        return Response::json([
            'values' => Policy::redact($resolved['values']),
            'sources' => $resolved['sources'],
            'profile' => (object) Policy::redact($profile),
            'overrides' => (object) Policy::redact($overrides),
            'version' => $devicePolicy['version'],
            'applied_version' => $d['applied_policy_version'],
            'update' => Releases::targetFor($devicePolicy),
        ]);
    }

    /** GET /api/v1/admin/devices/{id}/events?limit=&before= */
    public static function events(Request $req): Response
    {
        AdminAuth::require($req);
        $id = $req->intParam('id');
        $limit = $req->queryInt('limit', 100, 1, 1000);
        $before = $req->queryInt('before', 0, 0, PHP_INT_MAX);
        $rows = Db::all(
            'SELECT id, type, data, created_at FROM device_events WHERE device_id = :d'
            . ($before > 0 ? ' AND id < :b' : '') . ' ORDER BY id DESC LIMIT :l',
            ['d' => $id, 'l' => $limit] + ($before > 0 ? ['b' => $before] : [])
        );
        return Response::json(['items' => array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'data' => Db::decode($r['data'], null),
            'created_at' => Util::iso($r['created_at']),
        ], $rows)]);
    }

    /** POST /api/v1/admin/devices/{id}/commands  {type, params?, ttl_hours?} */
    public static function sendCommand(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $d = Devices::find($id) ?? throw HttpError::notFound('Device');
        if ($d['status'] !== 'active') {
            throw HttpError::conflict('The device is retired.');
        }
        $type = (string) $req->input('type', '');
        $params = Commands::validate($type, $req->input('params'));
        $ttl = $req->input('ttl_hours');
        [$cmdId] = Commands::queue([$id], $type, $params, $p, is_int($ttl) ? $ttl : null);
        Audit::log($p, 'command.queued', 'device', $id, ['type' => $type, 'command_id' => $cmdId]);
        $row = Db::one(CommandsApi::SELECT . ' WHERE c.id = :id', ['id' => $cmdId]);
        return Response::json(Commands::present($row), 201);
    }
}
