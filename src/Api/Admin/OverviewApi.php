<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Alerts;
use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Devices;
use Shepherd\Health;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Scope;
use Shepherd\Util;

final class OverviewApi
{
    /** GET /api/v1/admin/overview */
    public static function overview(Request $req): Response
    {
        $p = AdminAuth::require($req);
        [$scope, $scopeParams] = Scope::condition($p);
        $rows = Db::all(Devices::select() . " WHERE d.status = 'active' AND $scope", $scopeParams);
        $uptime = Health::uptime($rows, 7);
        $devices = array_map(static fn ($d) => Devices::present($d) + ['uptime_7d' => $uptime[(int) $d['id']] ?? null], $rows);

        $versions = [];
        $attention = [];
        foreach ($devices as $d) {
            $v = $d['app_version'] ?? 'unknown';
            $versions[$v] = ($versions[$v] ?? 0) + 1;
            $reasons = [];
            if (!$d['online']) {
                $reasons[] = $d['last_seen_at'] === null ? 'never checked in' : 'offline';
            } elseif (!$d['policy_current']) {
                $reasons[] = 'policy not applied yet';
            }
            $state = is_array($d['state']) ? $d['state'] : [];
            if (($state['start_on_boot_permission'] ?? true) === false) {
                $reasons[] = 'missing "Display over other apps" permission';
            }
            if (($state['lockdown'] ?? false) === true) {
                if (($state['lockdown_mode'] ?? null) === 'partial' && ($state['accessibility_helper'] ?? true) === false) {
                    $reasons[] = 'lockdown can be escaped: accessibility helper is off';
                } elseif (in_array($state['lockdown_mode'] ?? null, ['owner', 'mdm'], true)
                    && ($state['lock_task'] ?? true) === false) {
                    $reasons[] = 'lockdown on but not locked';
                }
            }
            if (!empty($state['page_error'])) {
                $reasons[] = 'page error';
            }
            // Only once a device has a day of history, so a new tablet isn't flagged for its first hours
            if ($d['uptime_7d'] !== null && $d['uptime_7d'] < 90 && strtotime((string) $d['enrolled_at']) < time() - 86400) {
                $reasons[] = "online only {$d['uptime_7d']}% in the last 7 days";
            }
            if ($reasons !== []) {
                $attention[] = ['id' => $d['id'], 'name' => $d['name'], 'reasons' => $reasons,
                    'last_seen_at' => $d['last_seen_at']];
            }
        }
        arsort($versions);

        return Response::json([
            'devices' => [
                'total' => count($devices),
                'online' => count(array_filter($devices, static fn ($d) => $d['online'])),
                'policy_pending' => count(array_filter($devices, static fn ($d) => $d['online'] && !$d['policy_current'])),
            ],
            'app_versions' => $versions,
            'attention' => array_slice($attention, 0, 50),
            'pending_commands' => (int) Db::value("SELECT COUNT(*) FROM commands c JOIN devices d ON d.id = c.device_id
                                                    WHERE c.status IN ('pending', 'delivered') AND $scope", $scopeParams),
            'profiles' => (int) Db::value('SELECT COUNT(*) FROM profiles'),
            'cron' => self::cronStatus(),
            'alerts' => Alerts::summary(),
            'recent_events' => array_map(static fn ($e) => [
                'id' => (int) $e['id'],
                'device_id' => (int) $e['device_id'],
                'device_name' => $e['name'],
                'type' => $e['type'],
                'data' => Db::decode($e['data'], null),
                'created_at' => Util::iso($e['created_at']),
            ], Db::all("SELECT e.*, d.name FROM device_events e JOIN devices d ON d.id = e.device_id
                         WHERE $scope ORDER BY e.id DESC LIMIT 20", $scopeParams)),
        ]);
    }

    /** POST /api/v1/admin/alerts/test  sends a test message to every alert channel */
    public static function testAlerts(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        if (!Alerts::configured()) {
            throw HttpError::conflict('No alert channels are set up. Add email addresses or webhooks under \'alerts\' in config/config.php.');
        }
        $results = Alerts::sendAll('test', 'Test alert', "This is a test from Kiosky Shepherd, sent by {$p->name}. Offline and back-online alerts will arrive like this.");
        Audit::log($p, 'alerts.tested', null, null, ['results' => $results]);
        return Response::json(['results' => $results]);
    }

    /** When bin/cron.php last finished. Stale after 30 minutes (it should run every 10). */
    public static function cronStatus(): array
    {
        $data = json_decode((string) @file_get_contents(Config::storage('cron-last-run.json')), true);
        $at = is_array($data) && is_string($data['at'] ?? null) ? $data['at'] : null;
        $time = $at === null ? false : strtotime($at);
        return [
            'last_run_at' => $time === false ? null : $at,
            'summary' => is_array($data) && is_string($data['summary'] ?? null) ? $data['summary'] : null,
            'stale' => $time === false || $time < time() - 1800,
        ];
    }

    /** GET /api/v1/admin/audit?limit=&before=&target_type=&target_id= */
    public static function audit(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        $where = [];
        $params = [];
        if (($tt = $req->queryString('target_type')) !== null) {
            $where[] = 'target_type = :tt';
            $params['tt'] = $tt;
        }
        if (($ti = $req->queryString('target_id')) !== null) {
            $where[] = 'target_id = :ti';
            $params['ti'] = $ti;
        }
        $before = $req->queryInt('before', 0, 0, PHP_INT_MAX);
        if ($before > 0) {
            $where[] = 'id < :b';
            $params['b'] = $before;
        }
        $params['l'] = $req->queryInt('limit', 100, 1, 1000);
        $rows = Db::all('SELECT * FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT :l', $params);
        return Response::json(['items' => array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'actor_type' => $r['actor_type'],
            'actor' => $r['actor_name'],
            'action' => $r['action'],
            'target_type' => $r['target_type'],
            'target_id' => $r['target_id'],
            'details' => Db::decode($r['details'], null),
            'ip' => $r['ip'],
            'created_at' => Util::iso($r['created_at']),
        ], $rows)]);
    }
}
