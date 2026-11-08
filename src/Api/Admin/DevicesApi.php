<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Auth\Principal;
use Shepherd\Commands;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Devices;
use Shepherd\Health;
use Shepherd\Http\HttpError;
use Shepherd\Migrator;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Policy;
use Shepherd\Releases;
use Shepherd\Scope;
use Shepherd\Util;

final class DevicesApi
{
    /** A device the user may see, or 404. */
    private static function findFor(Principal $p, int $id): array
    {
        $d = Devices::find($id) ?? throw HttpError::notFound('Device');
        Scope::requireDevice($p, $id);
        return $d;
    }

    /** GET /api/v1/admin/devices?q=&profile_id=&tag=&status=active|retired|all&online=1|0&limit=&offset= */
    public static function list(Request $req): Response
    {
        $p = AdminAuth::require($req);
        [$scopeCond, $params] = Scope::condition($p);
        $where = [$scopeCond];

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
        if (($tag = $req->queryString('tag')) !== null && Migrator::has('device_tags')) {
            $where[] = 'EXISTS (SELECT 1 FROM device_tags t WHERE t.device_id = d.id AND t.tag = :tag)';
            $params['tag'] = strtolower($tag);
        }
        $sql = Devices::select() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.name, d.id';
        $rows = Db::all($sql, $params);

        $uptime = Health::uptime($rows, 7);
        $items = array_map(static fn ($d) => Devices::present($d) + ['uptime_7d' => $uptime[(int) $d['id']] ?? null], $rows);
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
        $p = AdminAuth::require($req);
        return Response::json(Devices::present(self::findFor($p, $req->intParam('id')), true));
    }

    /** PATCH /api/v1/admin/devices/{id}  {name?, profile_id?, notes?, tags?: [..], overrides?: {key: value|null}, clear_overrides?: bool} */
    public static function update(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $d = self::findFor($p, $id);
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
        $tags = array_key_exists('tags', $body) ? Devices::validateTags($body['tags']) : null;
        if ($tags !== null) {
            Scope::checkKeepsAccess($p, $tags);
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

        $tagsChanged = $tags !== null && $tags !== Devices::tagList($d['tags'] ?? null);
        if ($changes !== [] || $tagsChanged) {
            Db::transaction(static function () use ($id, $d, $changes, $tags, $tagsChanged, $p): void {
                Db::update('devices', $changes, ['id' => $id]);
                if ($tagsChanged) {
                    Devices::setTags($id, $tags);
                }
                if (isset($changes['profile_id']) && $changes['profile_id'] !== (int) $d['profile_id']) {
                    Devices::logProfileChange([$id], (int) $d['profile_id'], $changes['profile_id'], $p->name);
                }
            });
            $logged = $changes;
            if (isset($logged['overrides'])) {
                $logged['overrides'] = array_keys($overrides);
            }
            if ($tagsChanged) {
                $logged['tags'] = $tags;
            }
            Audit::log($p, 'device.updated', 'device', $id, $logged);
        }
        return Response::json(Devices::present(Devices::find($id), true));
    }

    /** GET /api/v1/admin/tags  every tag in use, with how many active devices have it */
    public static function tags(Request $req): Response
    {
        $p = AdminAuth::require($req);
        if (!Migrator::has('device_tags')) {
            return Response::json([]);
        }
        [$cond, $params] = Scope::condition($p);
        $rows = Db::all("SELECT t.tag, SUM(d.status = 'active') AS devices
                           FROM device_tags t JOIN devices d ON d.id = t.device_id
                          WHERE $cond
                          GROUP BY t.tag ORDER BY t.tag", $params);
        return Response::json(array_map(static fn ($r) => ['tag' => $r['tag'], 'devices' => (int) $r['devices']], $rows));
    }

    /** POST /api/v1/admin/device-tags  {device_ids: [..], add?: [..], remove?: [..]} */
    public static function bulkTags(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Migrator::requireTable('device_tags');
        $ids = $req->input('device_ids');
        if (!is_array($ids) || $ids === [] || count($ids) > 5000) {
            throw HttpError::validation(['device_ids' => 'Choose some devices.']);
        }
        $ids = array_values(array_unique(array_filter($ids, 'is_int')));
        $add = Devices::validateTags($req->input('add', []), 'add');
        $remove = Devices::validateTags($req->input('remove', []), 'remove');
        if ($ids === [] || ($add === [] && $remove === [])) {
            throw HttpError::validation(['add' => 'Choose devices and tags to add or remove.']);
        }
        if ($p->isScoped() && array_intersect($remove, (array) $p->scopeTags) !== []) {
            throw HttpError::validation(['remove' => 'You can\'t remove your own tags (' . implode(', ', (array) $p->scopeTags)
                . '); the devices would disappear from your view.']);
        }
        $found = Scope::filterIds($p, $ids);
        $tooMany = [];
        Db::transaction(static function () use ($found, $add, $remove, &$tooMany): void {
            foreach ($found as $id) { // ids are scope-checked; unknown ids simply match nothing
                $current = array_column(Db::all('SELECT tag FROM device_tags WHERE device_id = :d', ['d' => $id]), 'tag');
                $next = array_values(array_diff(array_unique(array_merge($current, $add)), $remove));
                if (count($next) > Devices::MAX_TAGS) {
                    $tooMany[] = $id;
                    continue;
                }
                sort($next);
                Devices::setTags($id, $next);
            }
        });
        Audit::log($p, 'device.tags_bulk', 'device', null,
            ['devices' => count($found) - count($tooMany), 'add' => $add, 'remove' => $remove]);
        return Response::json(['updated' => count($found) - count($tooMany), 'skipped_too_many_tags' => $tooMany]);
    }

    /**
     * POST /api/v1/admin/devices/{id}/watch  called by the device page while it is open.
     * For two minutes the device's check-in responses ask it to check in every
     * fast_checkin_seconds, so commands and status changes show up quickly.
     */
    public static function watch(Request $req): Response
    {
        $p = AdminAuth::require($req);
        Scope::requireDevice($p, $req->intParam('id'));
        if (!Migrator::has('device_tags')) { // same migration added fast_checkin_until
            return Response::json(['fast_checkin_seconds' => null]);
        }
        Db::run("UPDATE devices SET fast_checkin_until = NOW() + INTERVAL 2 MINUTE WHERE id = :id AND status = 'active'",
            ['id' => $req->intParam('id')]);
        return Response::json(['fast_checkin_seconds' => self::fastSeconds()]);
    }

    public static function fastSeconds(): int
    {
        // At least 15 s: Kiosky won't check in more often, and it keeps a watched device
        // well inside the check-in rate limit
        return max(15, min(60, (int) Config::get('fast_checkin_seconds', 15)));
    }

    /** DELETE /api/v1/admin/devices/{id}  retires; ?purge=1 on a retired device deletes it */
    public static function retire(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $d = self::findFor($p, $id);

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
        $p = AdminAuth::require($req);
        $d = self::findFor($p, $req->intParam('id'));
        $profile = Policy::profileSettings((int) $d['profile_id']);
        $overrides = Db::decode($d['overrides']);
        $resolved = Policy::resolveForDevice($d);
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
        $p = AdminAuth::require($req);
        $id = $req->intParam('id');
        Scope::requireDevice($p, $id);
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
        $d = self::findFor($p, $id);
        if ($d['status'] !== 'active') {
            throw HttpError::conflict('The device is retired.');
        }
        $type = (string) $req->input('type', '');
        $params = Commands::validate($type, $req->input('params'));
        $ttl = $req->input('ttl_hours');
        [$cmdId] = Commands::queue([$id], $type, $params, $p, is_int($ttl) ? $ttl : null);
        Audit::log($p, 'command.queued', 'device', $id, ['type' => $type, 'command_id' => $cmdId]);
        $row = Db::one(CommandsApi::select() . ' WHERE c.id = :id', ['id' => $cmdId]);
        return Response::json(Commands::present($row), 201);
    }

    /** GET /api/v1/admin/devices/{id}/history?days=30  one row per day: uptime, battery, Wi-Fi, page errors */
    public static function history(Request $req): Response
    {
        $p = AdminAuth::require($req);
        $d = self::findFor($p, $req->intParam('id'));
        return Response::json([
            'timezone' => Health::timezone(),
            'days' => Health::history($d, $req->queryInt('days', 30, 7, 90)),
        ]);
    }

    /** GET /api/v1/admin/devices/{id}/live  the latest live-view frame (see the live_view command) */
    public static function liveFrame(Request $req): Response
    {
        $p = AdminAuth::require($req);
        $d = self::findFor($p, $req->intParam('id'));
        $frame = Devices::liveFrame((int) $d['id']);
        if ($frame === null) {
            throw HttpError::notFound('Live frame');
        }
        return Response::file($frame['path'], $frame['type'], null, [
            'Cache-Control' => 'no-store',
            'X-Captured-At' => gmdate('Y-m-d\TH:i:s\Z', $frame['mtime']),
        ]);
    }
}
