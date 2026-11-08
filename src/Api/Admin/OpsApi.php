<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Auth\Principal;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\DbBackup;
use Shepherd\Devices;
use Shepherd\Events;
use Shepherd\Health;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Migrator;
use Shepherd\Report;
use Shepherd\Scope;
use Shepherd\Util;
use Shepherd\WebPush;

/** Alert silences, CSV exports, database backups, reports, metrics, push and event webhooks. */
final class OpsApi
{
    // ---- Alert silences (maintenance) ---------------------------------------------

    /** GET /api/v1/admin/alerts/silences */
    public static function silences(Request $req): Response
    {
        AdminAuth::require($req);
        return Response::json(['items' => array_map(static fn ($s) => [
            'id' => (int) $s['id'], 'target_type' => $s['target_type'], 'target' => $s['target'],
            'target_label' => self::silenceLabel($s), 'until' => Util::iso($s['until_at']), 'note' => $s['note'],
            'created_by' => $s['username'] ?? null,
        ], \Shepherd\Alerts::silences())]);
    }

    private static function silenceLabel(array $s): string
    {
        return match ($s['target_type']) {
            'all' => 'all devices',
            'tag' => 'tag ' . $s['target'],
            default => (string) (Db::value('SELECT name FROM devices WHERE id = :id', ['id' => (int) $s['target']]) ?? 'device #' . $s['target']),
        };
    }

    /** POST /api/v1/admin/alerts/silences  {device_id | tag | all: true, minutes, note?} */
    public static function silence(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        Migrator::requireTable('alert_silences');
        $minutes = $req->input('minutes');
        if (!is_int($minutes) || $minutes < 5 || $minutes > 60 * 24 * 30) {
            throw HttpError::validation(['minutes' => 'Use 5 minutes to 30 days.']);
        }
        $b = $req->json();
        if (is_int($b['device_id'] ?? null)) {
            Scope::requireDevice($p, $b['device_id']);
            [$type, $target] = ['device', (string) $b['device_id']];
        } elseif (is_string($b['tag'] ?? null) && Devices::isTag(strtolower($b['tag']))) {
            Scope::forbid($p, 'silences for a whole tag');
            [$type, $target] = ['tag', strtolower($b['tag'])];
        } elseif (($b['all'] ?? null) === true) {
            Scope::forbid($p, 'silences for all devices');
            [$type, $target] = ['all', ''];
        } else {
            throw HttpError::validation(['target' => 'Choose a device, a tag, or all devices.']);
        }
        $id = Db::insert('alert_silences', [
            'target_type' => $type, 'target' => $target,
            'until_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60),
            'note' => Util::clip(trim((string) ($b['note'] ?? '')), 255) ?? '', 'created_by' => $p->adminId,
        ]);
        Audit::log($p, 'alerts.silenced', $type === 'device' ? 'device' : null, $type === 'device' ? $target : null,
            ['target' => "$type $target", 'minutes' => $minutes]);
        return Response::json(['id' => $id], 201);
    }

    /** DELETE /api/v1/admin/alerts/silences/{id} */
    public static function unsilence(Request $req): Response
    {
        $p = AdminAuth::require($req, 'operator');
        $id = $req->intParam('id');
        $s = Db::one('SELECT * FROM alert_silences WHERE id = :id', ['id' => $id]) ?? throw HttpError::notFound('Silence');
        if ($s['target_type'] === 'device') {
            Scope::requireDevice($p, (int) $s['target']);
        } else {
            Scope::forbid($p, 'silences for tags or all devices');
        }
        Db::run('DELETE FROM alert_silences WHERE id = :id', ['id' => $id]);
        Audit::log($p, 'alerts.unsilenced', null, null, ['target' => $s['target_type'] . ' ' . $s['target']]);
        return Response::noContent();
    }

    // ---- CSV exports ----------------------------------------------------------------

    /** Cells starting with = + - @ are prefixed so spreadsheets don't run them as formulas. */
    private static function csv(array $header, iterable $rows, string $filename): Response
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel shows accents properly
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static function ($v) {
                $v = is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '');
                return $v !== '' && str_contains('=+-@', $v[0]) && !is_numeric($v) ? "'" . $v : $v;
            }, $r));
        }
        rewind($fh);
        $body = (string) stream_get_contents($fh);
        fclose($fh);
        return Response::raw($body, 'text/csv; charset=utf-8', ['Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    /** GET /api/v1/admin/devices/export  every device you can see, as CSV (same filters as the list) */
    public static function exportDevices(Request $req): Response
    {
        $p = AdminAuth::require($req);
        [$cond, $params] = Scope::condition($p);
        $where = [$cond];
        $status = $req->queryString('status') ?? 'active';
        if (in_array($status, ['active', 'retired'], true)) {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }
        if (($tag = $req->queryString('tag')) !== null && Migrator::has('device_tags')) {
            $where[] = 'EXISTS (SELECT 1 FROM device_tags t WHERE t.device_id = d.id AND t.tag = :tag)';
            $params['tag'] = strtolower($tag);
        }
        if (($pid = $req->queryString('profile_id')) !== null && ctype_digit($pid)) {
            $where[] = 'd.profile_id = :pid';
            $params['pid'] = (int) $pid;
        }
        $rows = Db::all(Devices::select() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.name, d.id', $params);
        $uptime = Health::uptime($rows, 7);
        $out = [];
        foreach ($rows as $r) {
            $d = Devices::present($r);
            $st = is_array($d['state']) ? $d['state'] : [];
            $out[] = [$d['name'], $d['uid'], $d['status'], $d['profile_name'], implode(' ', $d['tags']),
                $d['manufacturer'], $d['model'], $d['android_version'], $d['app_version'], $d['last_ip'],
                $d['online'], $uptime[$d['id']] ?? '', $st['battery_level'] ?? '', $st['wifi_rssi'] ?? '',
                $st['current_url'] ?? '', $d['last_seen_at'], $d['enrolled_at'], (string) ($r['notes'] ?? '')];
        }
        return self::csv(['Name', 'Device ID', 'Status', 'Profile', 'Tags', 'Manufacturer', 'Model', 'Android', 'Kiosky',
            'IP address', 'Online', 'Online last 7 days (%)', 'Battery (%)', 'Wi-Fi (dBm)', 'Current page', 'Last seen (UTC)',
            'Enrolled (UTC)', 'Notes'], $out, 'kiosky-devices-' . gmdate('Y-m-d') . '.csv');
    }

    /**
     * Audit log filters shared by the list and the CSV export.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public static function auditFilters(Request $req): array
    {
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
        if (($actor = $req->queryString('actor')) !== null) {
            $where[] = 'actor_name LIKE :actor';
            $params['actor'] = '%' . Devices::likeEscape($actor) . '%';
        }
        if (($action = $req->queryString('action')) !== null) {
            $where[] = 'action LIKE :action';
            $params['action'] = Devices::likeEscape($action) . '%';
        }
        if (($q = $req->queryString('q')) !== null) {
            $where[] = '(action LIKE :q1 OR actor_name LIKE :q2 OR target_id LIKE :q3 OR CAST(details AS CHAR) LIKE :q4 OR ip LIKE :q5)';
            $like = '%' . Devices::likeEscape($q) . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            $v = $req->queryString($k);
            if ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $where[] = "created_at $op :$k";
                $params[$k] = $k === 'to' ? date('Y-m-d', strtotime("$v +1 day")) : $v;
            }
        }
        return [$where, $params];
    }

    /** GET /api/v1/admin/audit/export  the audit log (same filters as the list) as CSV, up to 20 000 rows */
    public static function exportAudit(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        [$where, $params] = self::auditFilters($req);
        $rows = Db::all('SELECT * FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 20000', $params);
        Audit::log($p, 'audit.exported', null, null, ['rows' => count($rows)]);
        return self::csv(['When (UTC)', 'Who', 'Kind', 'Action', 'Target type', 'Target', 'Details', 'IP'],
            array_map(static fn ($r) => [$r['created_at'], $r['actor_name'], $r['actor_type'], $r['action'], $r['target_type'],
                $r['target_id'], $r['details'], $r['ip']], $rows), 'kiosky-audit-' . gmdate('Y-m-d') . '.csv');
    }

    // ---- Database backups -----------------------------------------------------------

    /** GET /api/v1/admin/system/backups */
    public static function backups(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return Response::json(['items' => DbBackup::list(), 'nightly' => (bool) Config::get('backup.nightly', false),
            'keep' => DbBackup::keep()]);
    }

    /** POST /api/v1/admin/system/backups  makes one now */
    public static function createBackup(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        \Shepherd\RateLimiter::hit('db-backup:' . $p->adminId, 6, 3600);
        try {
            $name = DbBackup::create();
        } catch (\Throwable $e) {
            throw new HttpError(500, 'backup_failed', $e->getMessage());
        }
        DbBackup::prune();
        Audit::log($p, 'server.db_backup', null, null, ['file' => $name]);
        return Response::json(['name' => $name, 'items' => DbBackup::list()], 201);
    }

    /** GET /api/v1/admin/system/backups/{name} */
    public static function downloadBackup(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $path = DbBackup::path($req->param('name')) ?? throw HttpError::notFound('Backup');
        Audit::log($p, 'server.db_backup_downloaded', null, null, ['file' => basename($path)]);
        return Response::file($path, 'application/gzip', basename($path));
    }

    /** DELETE /api/v1/admin/system/backups/{name} */
    public static function deleteBackup(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        if (!DbBackup::delete($req->param('name'))) {
            throw HttpError::notFound('Backup');
        }
        Audit::log($p, 'server.db_backup_deleted', null, null, ['file' => $req->param('name')]);
        return Response::noContent();
    }

    // ---- Weekly report ---------------------------------------------------------------

    /** GET /api/v1/admin/system/report  preview */
    public static function report(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return Response::json(Report::build() + ['recipients' => Report::recipients(), 'weekly' => (bool) Config::get('report.weekly', false)]);
    }

    /** POST /api/v1/admin/system/report  sends it now */
    public static function sendReport(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        \Shepherd\RateLimiter::hit('report:' . $p->adminId, 5, 3600);
        try {
            $n = Report::send();
        } catch (\Throwable $e) {
            throw new HttpError(502, 'report_failed', $e->getMessage());
        }
        Audit::log($p, 'server.report_sent', null, null, ['recipients' => $n]);
        return Response::json(['sent_to' => $n]);
    }

    // ---- Prometheus ------------------------------------------------------------------

    /**
     * GET /metrics (and /api/v1/admin/metrics)  Prometheus text format. Authenticate with an
     * API token: bearer_token in the scrape config. Users limited to some devices see only those.
     */
    public static function metrics(Request $req): Response
    {
        $p = AdminAuth::require($req);
        [$cond, $params] = Scope::condition($p);
        $rows = Db::all(Devices::select() . " WHERE d.status = 'active' AND $cond", $params);
        $uptime = Health::uptime($rows, 7);
        $esc = static fn ($v) => str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v);
        $lines = [
            '# HELP kiosky_shepherd_info Server version.', '# TYPE kiosky_shepherd_info gauge',
            'kiosky_shepherd_info{version="' . $esc(SHEPHERD_VERSION) . '"} 1',
            '# HELP kiosky_devices Active managed devices.', '# TYPE kiosky_devices gauge', 'kiosky_devices ' . count($rows),
        ];
        $series = [
            'kiosky_device_up' => ['gauge', 'Whether the device is online (1) or not (0).'],
            'kiosky_device_last_checkin_age_seconds' => ['gauge', 'Seconds since the device last checked in.'],
            'kiosky_device_battery_percent' => ['gauge', 'Battery level the device last reported.'],
            'kiosky_device_charging' => ['gauge', 'Whether the device was charging.'],
            'kiosky_device_wifi_rssi_dbm' => ['gauge', 'Wi-Fi signal strength the device last reported.'],
            'kiosky_device_uptime_7d_ratio' => ['gauge', 'Share of the last 7 days the device was online.'],
            'kiosky_device_policy_applied' => ['gauge', 'Whether the device runs the current policy.'],
            'kiosky_device_info' => ['gauge', 'Device details as labels (always 1).'],
        ];
        $values = array_fill_keys(array_keys($series), []);
        $online = 0;
        foreach ($rows as $r) {
            $d = Devices::present($r);
            $st = is_array($d['state']) ? $d['state'] : [];
            $l = 'uid="' . $esc($d['uid']) . '",name="' . $esc($d['name']) . '"';
            $online += (int) $d['online'];
            $values['kiosky_device_up'][] = "{$l}} " . (int) $d['online'];
            if ($d['last_seen_at'] !== null) {
                $values['kiosky_device_last_checkin_age_seconds'][] = "{$l}} " . max(0, time() - (int) strtotime($d['last_seen_at']));
            }
            if (is_int($st['battery_level'] ?? null)) {
                $values['kiosky_device_battery_percent'][] = "{$l}} " . $st['battery_level'];
            }
            if (is_bool($st['charging'] ?? null)) {
                $values['kiosky_device_charging'][] = "{$l}} " . (int) $st['charging'];
            }
            if (is_int($st['wifi_rssi'] ?? null)) {
                $values['kiosky_device_wifi_rssi_dbm'][] = "{$l}} " . $st['wifi_rssi'];
            }
            if (($uptime[$d['id']] ?? null) !== null) {
                $values['kiosky_device_uptime_7d_ratio'][] = "{$l}} " . round($uptime[$d['id']] / 100, 4);
            }
            $values['kiosky_device_policy_applied'][] = "{$l}} " . (int) $d['policy_current'];
            $values['kiosky_device_info'][] = $l . ',profile="' . $esc($d['profile_name']) . '",app_version="' . $esc($d['app_version'] ?? '')
                . '",model="' . $esc(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) . '",tags="' . $esc(implode(',', $d['tags'])) . '"} 1';
        }
        $lines[] = '# HELP kiosky_devices_online Devices online now.';
        $lines[] = '# TYPE kiosky_devices_online gauge';
        $lines[] = "kiosky_devices_online $online";
        foreach ($series as $name => [$type, $help]) {
            if ($values[$name] === []) {
                continue;
            }
            $lines[] = "# HELP $name $help";
            $lines[] = "# TYPE $name $type";
            foreach ($values[$name] as $v) {
                $lines[] = "$name{" . $v;
            }
        }
        $pending = (int) Db::value("SELECT COUNT(*) FROM commands c JOIN devices d ON d.id = c.device_id
                                    WHERE c.status IN ('pending', 'delivered') AND $cond", $params);
        $lines[] = '# HELP kiosky_commands_pending Commands waiting for devices.';
        $lines[] = '# TYPE kiosky_commands_pending gauge';
        $lines[] = "kiosky_commands_pending $pending";
        $cron = OverviewApi::cronStatus();
        if ($cron['last_run_at'] !== null) {
            $lines[] = '# HELP kiosky_cron_last_run_timestamp_seconds When bin/cron.php last finished.';
            $lines[] = '# TYPE kiosky_cron_last_run_timestamp_seconds gauge';
            $lines[] = 'kiosky_cron_last_run_timestamp_seconds ' . (int) strtotime($cron['last_run_at']);
        }
        return Response::raw(implode("\n", $lines) . "\n", 'text/plain; version=0.0.4; charset=utf-8');
    }

    // ---- Browser push ----------------------------------------------------------------

    private static function consoleUser(Request $req): Principal
    {
        $p = AdminAuth::require($req);
        if ($p->type !== 'admin') {
            throw HttpError::forbidden('Use the web console.');
        }
        Migrator::requireTable('push_subscriptions');
        if (!WebPush::available()) {
            throw HttpError::conflict('Push notifications need PHP\'s openssl extension with ECDH support.');
        }
        return $p;
    }

    /** GET /api/v1/admin/push  the server's public key, and whether this user has subscribed browsers */
    public static function pushInfo(Request $req): Response
    {
        $p = self::consoleUser($req);
        return Response::json(['public_key' => WebPush::publicKey(),
            'subscriptions' => (int) Db::value('SELECT COUNT(*) FROM push_subscriptions WHERE admin_id = :a', ['a' => $p->id]),
            'receives_alerts' => !$p->isScoped()]);
    }

    /** POST /api/v1/admin/push  {endpoint, keys: {p256dh, auth}} from PushManager.subscribe() */
    public static function pushSubscribe(Request $req): Response
    {
        $p = self::consoleUser($req);
        $keys = $req->input('keys');
        WebPush::subscribe($p->id, (string) $req->input('endpoint', ''), (string) ($keys['p256dh'] ?? ''),
            (string) ($keys['auth'] ?? ''), $req->header('user-agent'));
        Audit::log($p, 'admin.push_subscribed', 'admin', $p->id);
        return Response::json(['ok' => true], 201);
    }

    /** DELETE /api/v1/admin/push  {endpoint} */
    public static function pushUnsubscribe(Request $req): Response
    {
        $p = self::consoleUser($req);
        WebPush::unsubscribe($p->id, (string) $req->input('endpoint', ''));
        return Response::noContent();
    }

    /** POST /api/v1/admin/push/test  sends a notification to your browsers */
    public static function pushTest(Request $req): Response
    {
        $p = self::consoleUser($req);
        \Shepherd\RateLimiter::hit('push-test:' . $p->id, 10, 600);
        try {
            $n = WebPush::sendToAdmin($p->id, 'Test notification', 'Kiosky Shepherd alerts will appear like this.', '/admin/');
        } catch (\Throwable $e) {
            throw new HttpError(502, 'push_failed', $e->getMessage());
        }
        if ($n === 0) {
            throw HttpError::conflict('No browser is subscribed. Turn notifications on first.');
        }
        return Response::json(['sent' => $n]);
    }

    // ---- Event webhooks --------------------------------------------------------------

    /** GET /api/v1/admin/events  event types and recent deliveries */
    public static function events(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return Response::json(['types' => Events::TYPES, 'recent' => Events::recent(30), 'webhooks' => count(Events::webhooks())]);
    }

    /** POST /api/v1/admin/events/test  sends a test event to every event webhook */
    public static function testEvent(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        if (Events::webhooks() === []) {
            throw HttpError::conflict('No event webhooks are set up. Add one under Server → Settings.');
        }
        Migrator::requireTable('event_outbox');
        foreach (Events::webhooks() as $w) {
            Db::insert('event_outbox', ['event' => 'test', 'url' => $w['url'], 'payload' => Db::json(
                ['event' => 'test', 'at' => gmdate('c'), 'server' => Util::baseUrl(), 'data' => ['by' => $p->name]])]);
        }
        $delivered = Events::flush(20);
        return Response::json(['delivered' => $delivered, 'recent' => Events::recent(10)]);
    }
}
