<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;
use Shepherd\Http\HttpError;

/** Remote commands queued for devices and delivered at check-in. */
final class Commands
{
    public const TYPES = [
        'reload' => ['label' => 'Reload page', 'params' => []],
        'go_home' => ['label' => 'Back to start page', 'params' => []],
        'navigate' => ['label' => 'Open a page (temporary)', 'params' => [
            'url' => ['type' => 'url', 'required' => true],
        ]],
        'restart_app' => ['label' => 'Restart Kiosky', 'params' => []],
        'clear_cache' => ['label' => 'Clear cache', 'params' => []],
        'clear_web_data' => ['label' => 'Clear cookies and site data (signs out)', 'params' => []],
        'check_update' => ['label' => 'Check for update now', 'params' => []],
        'show_message' => ['label' => 'Show a message', 'params' => [
            'text' => ['type' => 'string', 'required' => true, 'max' => 500],
            'seconds' => ['type' => 'int', 'min' => 1, 'max' => 3600, 'default' => 30],
        ]],
        'identify' => ['label' => 'Identify (show name on screen)', 'params' => [
            'seconds' => ['type' => 'int', 'min' => 1, 'max' => 300, 'default' => 15],
        ]],
        'live_view' => ['label' => 'Live view (stream the screen)', 'params' => [
            // 0 stops a live view that is running
            'seconds' => ['type' => 'int', 'min' => 0, 'max' => 600, 'default' => 120],
            'interval' => ['type' => 'int', 'min' => 1, 'max' => 10, 'default' => 2],
            'max_width' => ['type' => 'int', 'min' => 320, 'max' => 1920, 'default' => 960],
            'quality' => ['type' => 'int', 'min' => 10, 'max' => 100, 'default' => 60],
        ]],
        'diagnostics' => ['label' => 'Collect diagnostics (logs)', 'params' => [
            'lines' => ['type' => 'int', 'min' => 100, 'max' => 10000, 'default' => 2000],
        ]],
        'screenshot' => ['label' => 'Take a screenshot', 'params' => [
            'max_width' => ['type' => 'int', 'min' => 320, 'max' => 4096, 'default' => 1280],
            'quality' => ['type' => 'int', 'min' => 10, 'max' => 100, 'default' => 70],
        ]],
    ];

    /** @return array<string, mixed> */
    public static function validate(string $type, mixed $params): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            throw HttpError::validation(['type' => 'Unknown command. Use one of: ' . implode(', ', array_keys(self::TYPES)) . '.']);
        }
        if ($params === null) {
            $params = [];
        }
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            throw HttpError::validation(['params' => 'Must be an object.']);
        }
        $out = [];
        $errors = [];
        foreach ($def['params'] as $name => $p) {
            $v = $params[$name] ?? null;
            if ($v === null || $v === '') {
                if (!empty($p['required'])) {
                    $errors["params.$name"] = 'Required.';
                } elseif (array_key_exists('default', $p)) {
                    $out[$name] = $p['default'];
                }
                continue;
            }
            switch ($p['type']) {
                case 'int':
                    if (is_string($v) && ctype_digit($v)) {
                        $v = (int) $v;
                    }
                    if (!is_int($v) || $v < $p['min'] || $v > $p['max']) {
                        $errors["params.$name"] = "Must be a whole number from {$p['min']} to {$p['max']}.";
                        continue 2;
                    }
                    break;
                case 'string':
                    if (!is_string($v) || mb_strlen($v) > $p['max']) {
                        $errors["params.$name"] = "Must be text of at most {$p['max']} characters.";
                        continue 2;
                    }
                    break;
                case 'url':
                    $norm = Policy::validate(['url' => $v])['url'] ?? '';
                    if ($norm === '') {
                        $errors["params.$name"] = 'Required.';
                        continue 2;
                    }
                    $v = $norm;
                    break;
            }
            $out[$name] = $v;
        }
        $unknown = array_diff(array_keys($params), array_keys($def['params']));
        foreach ($unknown as $u) {
            $errors["params.$u"] = 'Unknown parameter.';
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        return $out;
    }

    /**
     * Queues one command for each device.
     *
     * @param list<int> $deviceIds
     * @return list<int> command ids
     */
    public static function queue(array $deviceIds, string $type, array $params, Principal $by, ?int $ttlHours = null,
        ?int $scheduleId = null): array
    {
        $ttl = $ttlHours ?? (int) Config::get('command_ttl_hours', 24);
        $ttl = max(1, min(24 * 30, $ttl));
        $ids = [];
        Db::transaction(static function () use ($deviceIds, $type, $params, $by, $ttl, $scheduleId, &$ids): void {
            foreach ($deviceIds as $deviceId) {
                $row = ['d' => $deviceId, 't' => $type, 'p' => Db::json((object) $params), 'by' => $by->adminId, 'ttl' => $ttl];
                // schedule_id only exists after the 1.3.0 migration; only schedules set it
                Db::run(
                    $scheduleId === null
                        ? 'INSERT INTO commands (device_id, type, params, created_by, expires_at)
                           VALUES (:d, :t, :p, :by, NOW() + INTERVAL :ttl HOUR)'
                        : 'INSERT INTO commands (device_id, type, params, created_by, schedule_id, expires_at)
                           VALUES (:d, :t, :p, :by, :s, NOW() + INTERVAL :ttl HOUR)',
                    $scheduleId === null ? $row : $row + ['s' => $scheduleId]
                );
                $ids[] = (int) Db::pdo()->lastInsertId();
            }
        });
        return $ids;
    }

    /**
     * Checks and normalises a command target. Stored as-is on schedules.
     *
     * @return array{all: true}|array{profile_id: int}|array{tag: string}|array{device_ids: list<int>}
     */
    public static function normaliseTarget(mixed $t): array
    {
        if (!is_array($t)) {
            throw HttpError::validation(['target' => 'Choose devices, a profile, a tag, or all devices.']);
        }
        if (!empty($t['device_ids'])) {
            $ids = is_array($t['device_ids']) ? array_values(array_unique(array_filter($t['device_ids'], 'is_int'))) : [];
            if ($ids === [] || count($ids) > 5000) {
                throw HttpError::validation(['device_ids' => 'Must be a list of device ids.']);
            }
            return ['device_ids' => $ids];
        }
        if (array_key_exists('profile_id', $t)) {
            if (!is_int($t['profile_id']) || Db::value('SELECT id FROM profiles WHERE id = :id', ['id' => $t['profile_id']]) === null) {
                throw HttpError::validation(['profile_id' => 'Choose an existing profile.']);
            }
            return ['profile_id' => $t['profile_id']];
        }
        if (array_key_exists('tag', $t)) {
            $tag = is_string($t['tag']) ? strtolower(trim($t['tag'])) : null;
            if (!Devices::isTag($tag)) {
                throw HttpError::validation(['tag' => 'Not a valid tag.']);
            }
            return ['tag' => $tag];
        }
        if (($t['all'] ?? null) === true) {
            return ['all' => true];
        }
        throw HttpError::validation(['device_ids' => 'Choose devices, a profile_id, a tag, or all: true.']);
    }

    /**
     * Active device ids a normalised target currently covers.
     *
     * @return list<int>
     */
    public static function resolveTargets(array $target): array
    {
        if (isset($target['device_ids'])) {
            $in = implode(',', array_map('intval', $target['device_ids']));
            $rows = Db::all("SELECT id FROM devices WHERE status = 'active' AND id IN ($in)");
        } elseif (isset($target['profile_id'])) {
            $rows = Db::all("SELECT id FROM devices WHERE status = 'active' AND profile_id = :p", ['p' => (int) $target['profile_id']]);
        } elseif (isset($target['tag'])) {
            Migrator::requireTable('device_tags');
            $rows = Db::all("SELECT d.id FROM devices d JOIN device_tags t ON t.device_id = d.id
                              WHERE d.status = 'active' AND t.tag = :t", ['t' => (string) $target['tag']]);
        } else {
            $rows = Db::all("SELECT id FROM devices WHERE status = 'active'");
        }
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /** Human-readable description of a target, for lists and alerts. */
    public static function describeTarget(array $target): string
    {
        if (isset($target['device_ids'])) {
            $n = count($target['device_ids']);
            return $n === 1 ? '1 device' : "$n devices";
        }
        if (isset($target['profile_id'])) {
            $name = Db::value('SELECT name FROM profiles WHERE id = :id', ['id' => (int) $target['profile_id']]);
            return 'profile ' . ($name ?? '#' . $target['profile_id'] . ' (deleted)');
        }
        if (isset($target['tag'])) {
            return 'tag ' . $target['tag'];
        }
        return 'all devices';
    }

    /**
     * Commands to hand a device at check-in. Delivered-but-unacknowledged commands
     * are sent again after a while, so devices must ignore ids they've already run.
     *
     * @return list<array<string, mixed>>
     */
    public static function deliver(int $deviceId): array
    {
        Db::run(
            "UPDATE commands SET status = 'expired', completed_at = NOW()
              WHERE device_id = :d AND status IN ('pending', 'delivered') AND expires_at < NOW()",
            ['d' => $deviceId]
        );
        $rows = Db::all(
            "SELECT id, type, params, created_at, expires_at FROM commands
              WHERE device_id = :d
                AND (status = 'pending' OR (status = 'delivered' AND delivered_at < NOW() - INTERVAL :re SECOND))
              ORDER BY id LIMIT 20",
            ['d' => $deviceId, 're' => (int) Config::get('command_redeliver_seconds', 300)]
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        Db::run(
            "UPDATE commands SET status = 'delivered', delivered_at = NOW()
              WHERE id IN (" . implode(',', $ids) . ')'
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'params' => (object) Db::decode($r['params']),
            'created_at' => Util::iso($r['created_at']),
            'expires_at' => Util::iso($r['expires_at']),
        ], $rows);
    }

    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'device_id' => (int) $row['device_id'],
            'device_name' => $row['device_name'] ?? null,
            'type' => $row['type'],
            'params' => (object) Db::decode($row['params']),
            'status' => $row['status'],
            'result' => $row['result'] === null ? null : Db::decode($row['result'], null),
            'has_attachment' => $row['attachment'] !== null,
            'attachment_kind' => $row['attachment'] === null ? null : ($row['type'] === 'diagnostics' ? 'download' : 'image'),
            'created_by' => $row['created_by_name'] ?? null,
            'schedule' => isset($row['schedule_name']) ? ['id' => (int) $row['schedule_id'], 'name' => $row['schedule_name']] : null,
            'created_at' => Util::iso($row['created_at']),
            'delivered_at' => Util::iso($row['delivered_at']),
            'completed_at' => Util::iso($row['completed_at']),
            'expires_at' => Util::iso($row['expires_at']),
        ];
    }
}
