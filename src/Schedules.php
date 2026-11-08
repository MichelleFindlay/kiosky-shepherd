<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;
use Shepherd\Http\HttpError;

/**
 * Commands queued automatically on chosen weekdays at a local time, e.g. "reload
 * every tablet tagged lobby at 05:00 Europe/London". bin/cron.php runs them, so they
 * fire up to one cron interval (10 minutes) late.
 */
final class Schedules
{
    /** Bit 0 is Monday, matching PHP's date('N') - 1. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Not a device command: moves the target devices to params.profile_id. Tablets
     * apply the new profile's policy at their next check-in, so this gives e.g. a
     * night-time start page with no app support needed.
     */
    public const SET_PROFILE = 'set_profile';

    /** A run missed by more than this (cron wasn't running) is skipped, not run late. */
    private const MAX_LATE_SECONDS = 3600;

    public static function defaultTimezone(): string
    {
        $tz = (string) Config::get('timezone', 'UTC');
        return self::isTimezone($tz) ? $tz : 'UTC';
    }

    public static function isTimezone(mixed $tz): bool
    {
        return is_string($tz) && in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    /** @param list<string> $days e.g. ['mon', 'fri'] */
    public static function daysToMask(mixed $days): int
    {
        if (!is_array($days) || !array_is_list($days) || $days === []) {
            throw HttpError::validation(['days' => 'Choose at least one day.']);
        }
        $mask = 0;
        foreach ($days as $d) {
            $i = array_search($d, self::DAYS, true);
            if ($i === false) {
                throw HttpError::validation(['days' => 'Use mon, tue, wed, thu, fri, sat, sun.']);
            }
            $mask |= 1 << (int) $i;
        }
        return $mask;
    }

    /** @return list<string> */
    public static function maskToDays(int $mask): array
    {
        $out = [];
        foreach (self::DAYS as $i => $d) {
            if ($mask & (1 << $i)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * The next run strictly after $after, as a UTC DATETIME string, or null if no day is chosen.
     * On days when the clocks change, a time that doesn't exist moves forward by an hour.
     */
    public static function nextRun(int $days, string $time, string $timezone, ?\DateTimeImmutable $after = null): ?string
    {
        if (($days & 127) === 0 || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return null;
        }
        $zone = new \DateTimeZone($timezone);
        $after = ($after ?? new \DateTimeImmutable('now'))->setTimezone($zone);
        for ($i = 0; $i <= 7; $i++) {
            $candidate = $after->setTime(0, 0)->modify("+$i day")->setTime((int) $m[1], (int) $m[2]);
            if (!($days & (1 << ((int) $candidate->format('N') - 1))) || $candidate <= $after) {
                continue;
            }
            return $candidate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return null;
    }

    /**
     * Queues the schedule's command now. Returns a short result for last_result.
     */
    public static function runNow(array $s, ?Principal $by = null): string
    {
        $target = Db::decode($s['target']);
        $ids = Commands::resolveTargets(is_array($target) ? $target : []);
        if ($ids === []) {
            return 'No active devices matched ' . Commands::describeTarget($target) . '.';
        }
        $by ??= new Principal('system', 0, 'schedule: ' . Util::clip($s['name'], 80), 'admin', '',
            $s['created_by'] === null ? null : (int) $s['created_by']);
        if ($s['type'] === self::SET_PROFILE) {
            return self::switchProfile($s, $ids, $by);
        }
        $ttl = $s['ttl_hours'] === null ? null : (int) $s['ttl_hours'];
        Commands::queue($ids, (string) $s['type'], Db::decode($s['params']), $by, $ttl, (int) $s['id']);
        Audit::log($by, 'schedule.ran', 'schedule', (int) $s['id'],
            ['name' => $s['name'], 'type' => $s['type'], 'devices' => count($ids)]);
        return 'Queued ' . $s['type'] . ' for ' . count($ids) . ' device' . (count($ids) === 1 ? '' : 's') . '.';
    }

    /** @param list<int> $ids */
    private static function switchProfile(array $s, array $ids, Principal $by): string
    {
        $to = (int) (Db::decode($s['params'])['profile_id'] ?? 0);
        $name = Db::value('SELECT name FROM profiles WHERE id = :id', ['id' => $to]);
        if ($name === null) {
            return 'Failed: the profile it switches to has been deleted.';
        }
        $in = implode(',', $ids);
        $moving = Db::all("SELECT id, profile_id FROM devices WHERE id IN ($in) AND profile_id <> :p", ['p' => $to]);
        Db::transaction(static function () use ($moving, $to, $by): void {
            foreach ($moving as $d) {
                Db::update('devices', ['profile_id' => $to], ['id' => (int) $d['id']]);
                Devices::logProfileChange([(int) $d['id']], (int) $d['profile_id'], $to, $by->name);
            }
        });
        Audit::log($by, 'schedule.ran', 'schedule', (int) $s['id'],
            ['name' => $s['name'], 'type' => self::SET_PROFILE, 'profile' => $name, 'devices' => count($moving)]);
        $already = count($ids) - count($moving);
        return 'Moved ' . count($moving) . ' device' . (count($moving) === 1 ? '' : 's') . " to $name"
            . ($already > 0 ? " ($already already there)" : '') . '.';
    }

    /**
     * Runs every enabled schedule that is due. Called from bin/cron.php.
     *
     * @return array{ran: int, skipped: int}
     */
    public static function runDue(): array
    {
        $ran = 0;
        $skipped = 0;
        $due = Db::all('SELECT * FROM scheduled_commands WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= NOW()');
        foreach ($due as $s) {
            $late = time() - (int) strtotime($s['next_run_at'] . ' UTC');
            try {
                if ($late > self::MAX_LATE_SECONDS) {
                    $result = 'Skipped the run due ' . $s['next_run_at'] . ' UTC: cron wasn\'t running then.';
                    $skipped++;
                } else {
                    $result = self::runNow($s);
                    $ran++;
                }
            } catch (\Throwable $e) {
                $result = 'Failed: ' . $e->getMessage();
                error_log('[kiosky-shepherd] schedule ' . $s['id'] . ': ' . $e->getMessage());
            }
            Db::update('scheduled_commands', [
                'last_run_at' => Util::now(),
                'last_result' => Util::clip($result, 255),
                'next_run_at' => self::nextRun((int) $s['days'], (string) $s['at_time'], (string) $s['timezone']),
            ], ['id' => (int) $s['id']]);
        }
        return ['ran' => $ran, 'skipped' => $skipped];
    }

    public static function present(array $s): array
    {
        $target = Db::decode($s['target']);
        return [
            'id' => (int) $s['id'],
            'name' => $s['name'],
            'type' => $s['type'],
            'params' => (object) Db::decode($s['params']),
            'target' => (object) $target,
            'target_label' => Commands::describeTarget(is_array($target) ? $target : []),
            'days' => self::maskToDays((int) $s['days']),
            'time' => $s['at_time'],
            'timezone' => $s['timezone'],
            'ttl_hours' => $s['ttl_hours'] === null ? null : (int) $s['ttl_hours'],
            'enabled' => (bool) $s['enabled'],
            'next_run_at' => Util::iso($s['next_run_at']),
            'last_run_at' => Util::iso($s['last_run_at']),
            'last_result' => $s['last_result'],
            'created_by' => $s['created_by_name'] ?? null,
            'created_at' => Util::iso($s['created_at']),
        ];
    }
}
