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
    public static function queue(array $deviceIds, string $type, array $params, Principal $by, ?int $ttlHours = null): array
    {
        $ttl = $ttlHours ?? (int) Config::get('command_ttl_hours', 24);
        $ttl = max(1, min(24 * 30, $ttl));
        $ids = [];
        Db::transaction(static function () use ($deviceIds, $type, $params, $by, $ttl, &$ids): void {
            foreach ($deviceIds as $deviceId) {
                Db::run(
                    'INSERT INTO commands (device_id, type, params, created_by, expires_at)
                     VALUES (:d, :t, :p, :by, NOW() + INTERVAL :ttl HOUR)',
                    ['d' => $deviceId, 't' => $type, 'p' => Db::json((object) $params),
                     'by' => $by->adminId, 'ttl' => $ttl]
                );
                $ids[] = (int) Db::pdo()->lastInsertId();
            }
        });
        return $ids;
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
            'created_by' => $row['created_by_name'] ?? null,
            'created_at' => Util::iso($row['created_at']),
            'delivered_at' => Util::iso($row['delivered_at']),
            'completed_at' => Util::iso($row['completed_at']),
            'expires_at' => Util::iso($row['expires_at']),
        ];
    }
}
