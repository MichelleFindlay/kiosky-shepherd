<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Commands;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Migrator;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Schedules;
use Shepherd\Scope;
use Shepherd\Util;

/** Scheduled commands, run by bin/cron.php. */
final class SchedulesApi
{
    private const SELECT = 'SELECT s.*, a.username AS created_by_name
                              FROM scheduled_commands s LEFT JOIN admins a ON a.id = s.created_by';

    private static function find(int $id): array
    {
        return Db::one(self::SELECT . ' WHERE s.id = :id', ['id' => $id]) ?? throw HttpError::notFound('Schedule');
    }

    /** GET /api/v1/admin/schedules */
    public static function list(Request $req): Response
    {
        $p = AdminAuth::require($req);
        // Schedules act on the whole fleet, so users limited to some devices don't see them
        $rows = Migrator::has('scheduled_commands') && !$p->isScoped() ? Db::all(self::SELECT . ' ORDER BY s.name, s.id') : [];
        return Response::json([
            'items' => array_map([Schedules::class, 'present'], $rows),
            'timezone' => Schedules::defaultTimezone(),
            'cron' => OverviewApi::cronStatus(),
        ]);
    }

    /**
     * POST /api/v1/admin/schedules
     * {name, type, params?, target: {all|profile_id|tag|device_ids}, days: ["mon",..], time: "HH:MM", timezone?, ttl_hours?, enabled?}
     */
    public static function create(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'schedules');
        Migrator::requireTable('scheduled_commands');
        $row = self::validate($req->json(), null);
        $row['created_by'] = $p->adminId;
        $id = Db::insert('scheduled_commands', $row);
        Audit::log($p, 'schedule.created', 'schedule', $id, ['name' => $row['name'], 'type' => $row['type']]);
        return Response::json(Schedules::present(self::find($id)), 201);
    }

    /** PATCH /api/v1/admin/schedules/{id}  any of the fields above */
    public static function update(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'schedules');
        $id = $req->intParam('id');
        $current = self::find($id);
        $row = self::validate($req->json(), $current);
        Db::update('scheduled_commands', $row, ['id' => $id]);
        Audit::log($p, 'schedule.updated', 'schedule', $id, ['fields' => array_keys($req->json())]);
        return Response::json(Schedules::present(self::find($id)));
    }

    /** DELETE /api/v1/admin/schedules/{id} */
    public static function delete(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'schedules');
        $id = $req->intParam('id');
        $s = self::find($id);
        Db::run('DELETE FROM scheduled_commands WHERE id = :id', ['id' => $id]);
        Audit::log($p, 'schedule.deleted', 'schedule', $id, ['name' => $s['name']]);
        return Response::noContent();
    }

    /** POST /api/v1/admin/schedules/{id}/run  queues it now, without changing the next run */
    public static function run(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Scope::forbid($p, 'schedules');
        $id = $req->intParam('id');
        $result = Schedules::runNow(self::find($id), $p);
        Db::update('scheduled_commands', ['last_run_at' => Util::now(), 'last_result' => Util::clip($result, 255)], ['id' => $id]);
        return Response::json(['result' => $result] + Schedules::present(self::find($id)));
    }

    /** params for a set_profile schedule: {profile_id} */
    private static function validateSwitch(mixed $params): array
    {
        $pid = is_array($params) ? ($params['profile_id'] ?? null) : null;
        if (is_string($pid) && ctype_digit($pid)) {
            $pid = (int) $pid;
        }
        if (!is_int($pid) || Db::value('SELECT id FROM profiles WHERE id = :id', ['id' => $pid]) === null) {
            throw HttpError::validation(['params.profile_id' => 'Choose the profile to switch to.']);
        }
        return ['profile_id' => $pid];
    }

    /**
     * Validates a full (create) or partial (update) body into column values.
     *
     * @return array<string, mixed>
     */
    private static function validate(array $b, ?array $current): array
    {
        $creating = $current === null;
        $row = [];
        $errors = [];

        if ($creating || array_key_exists('name', $b)) {
            $name = is_string($b['name'] ?? null) ? trim($b['name']) : '';
            if ($name === '' || mb_strlen($name) > 100) {
                $errors['name'] = 'Enter a name of up to 100 characters.';
            }
            $row['name'] = $name;
        }
        if ($creating || array_key_exists('type', $b) || array_key_exists('params', $b)) {
            $type = (string) ($b['type'] ?? $current['type'] ?? '');
            $params = array_key_exists('params', $b) ? $b['params'] : ($current === null ? null : Db::decode($current['params']));
            $row['type'] = $type;
            $row['params'] = Db::json((object) ($type === Schedules::SET_PROFILE
                ? self::validateSwitch($params)
                : Commands::validate($type, $params)));
        }
        if ($creating || array_key_exists('target', $b)) {
            $row['target'] = Db::json(Commands::normaliseTarget($b['target'] ?? null));
        }
        if ($creating || array_key_exists('days', $b)) {
            $row['days'] = Schedules::daysToMask($b['days'] ?? null);
        }
        if ($creating || array_key_exists('time', $b)) {
            $time = is_string($b['time'] ?? null) ? trim($b['time']) : '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors['time'] = 'Use a 24-hour time such as 05:30.';
            }
            $row['at_time'] = $time;
        }
        if ($creating || array_key_exists('timezone', $b)) {
            $tz = $b['timezone'] ?? null;
            if ($tz === null || $tz === '') {
                $tz = Schedules::defaultTimezone();
            }
            if (!Schedules::isTimezone($tz)) {
                $errors['timezone'] = 'Use a time zone name such as Europe/London or UTC.';
            }
            $row['timezone'] = $tz;
        }
        if (array_key_exists('ttl_hours', $b)) {
            $ttl = $b['ttl_hours'];
            if ($ttl !== null && (!is_int($ttl) || $ttl < 1 || $ttl > 720)) {
                $errors['ttl_hours'] = 'Use 1 to 720 hours, or null for the server default.';
            }
            $row['ttl_hours'] = $ttl;
        }
        if (array_key_exists('enabled', $b)) {
            if (!is_bool($b['enabled'])) {
                $errors['enabled'] = 'Must be true or false.';
            }
            $row['enabled'] = (bool) $b['enabled'];
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        // Recompute the next run whenever anything that affects it changes
        $days = $row['days'] ?? (int) ($current['days'] ?? 0);
        $time = $row['at_time'] ?? (string) ($current['at_time'] ?? '');
        $tz = $row['timezone'] ?? (string) ($current['timezone'] ?? 'UTC');
        $enabled = $row['enabled'] ?? (bool) ($current['enabled'] ?? true);
        $row['next_run_at'] = $enabled ? Schedules::nextRun($days, $time, $tz) : null;
        return $row;
    }
}
