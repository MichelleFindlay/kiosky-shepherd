<?php

declare(strict_types=1);

// Housekeeping, scheduled commands and offline alerts. Run every 10 minutes from cron, e.g.
//   */10 * * * *  php /srv/kiosky-shepherd/bin/cron.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Shepherd\Config;
use Shepherd\Db;

\Shepherd\Migrator::autoRun();

$days = max(1, (int) Config::get('retention_days', 90));

$expired = Db::run("UPDATE commands SET status = 'expired', completed_at = NOW()
                     WHERE status IN ('pending', 'delivered') AND expires_at < NOW()")->rowCount();

// Scheduled commands and offline alerts need the 1.3.0 database update
$extra = [];
if (\Shepherd\Migrator::tableExists('scheduled_commands')) {
    try {
        $s = \Shepherd\Schedules::runDue();
        if ($s['ran'] || $s['skipped']) {
            $extra[] = "ran {$s['ran']} schedules" . ($s['skipped'] ? ", skipped {$s['skipped']} missed" : '');
        }
    } catch (\Throwable $e) {
        error_log('[kiosky-shepherd] cron schedules: ' . $e->getMessage());
        $extra[] = 'schedules failed: ' . $e->getMessage();
    }
    try {
        $extra[] = \Shepherd\Alerts::check();
    } catch (\Throwable $e) {
        error_log('[kiosky-shepherd] cron alerts: ' . $e->getMessage());
        $extra[] = 'alerts failed: ' . $e->getMessage();
    }
} else {
    $extra[] = 'database update pending: schedules and alerts are off';
}

// Event webhooks that couldn't be delivered straight away, nightly database backup, weekly report
foreach ([
    'events' => static fn () => ($n = \Shepherd\Events::flush()) ? "delivered $n events" : null,
    'backup' => static fn () => \Shepherd\DbBackup::cron(),
    'report' => static fn () => \Shepherd\Report::cron(),
] as $job => $fn) {
    try {
        $r = $fn();
        if ($r !== null) {
            $extra[] = $r;
        }
    } catch (\Throwable $e) {
        error_log("[kiosky-shepherd] cron $job: " . $e->getMessage());
        $extra[] = "$job failed: " . $e->getMessage();
    }
}
\Shepherd\Events::prune();
if (\Shepherd\Migrator::tableExists('admin_sessions')) {
    Db::run('DELETE FROM admin_sessions WHERE (ended_at IS NOT NULL AND ended_at < NOW() - INTERVAL 90 DAY)
                                           OR last_seen_at < NOW() - INTERVAL 90 DAY');
}

// Keeps Cloudflare's address ranges current when trusted_proxies includes 'cloudflare'
try {
    $cf = \Shepherd\Http\TrustedProxies::refreshCloudflare();
    if (str_contains($cf, 'updated')) {
        $extra[] = $cf;
    }
} catch (\Throwable $e) {
    error_log('[kiosky-shepherd] cron cloudflare ranges: ' . $e->getMessage());
}

// Checks GitHub for a newer Kiosky Shepherd every 6 hours (and installs it when self_update.auto is on)
try {
    $extra[] = \Shepherd\SelfUpdate::cron();
} catch (\Throwable $e) {
    error_log('[kiosky-shepherd] cron self-update: ' . $e->getMessage());
    $extra[] = 'self-update failed: ' . $e->getMessage();
}

// Old finished commands, and their screenshots
$old = Db::all("SELECT id, attachment FROM commands
                 WHERE status IN ('done', 'failed', 'expired', 'cancelled')
                   AND COALESCE(completed_at, created_at) < NOW() - INTERVAL :d DAY", ['d' => $days]);
foreach ($old as $c) {
    if ($c['attachment'] !== null) {
        @unlink(Config::storage('screenshots') . '/' . basename((string) $c['attachment']));
        @unlink(Config::storage('diagnostics') . '/' . basename((string) $c['attachment']));
    }
}
$commands = Db::run("DELETE FROM commands WHERE status IN ('done', 'failed', 'expired', 'cancelled')
                       AND COALESCE(completed_at, created_at) < NOW() - INTERVAL :d DAY", ['d' => $days])->rowCount();

$events = Db::run('DELETE FROM device_events WHERE created_at < NOW() - INTERVAL :d DAY', ['d' => $days])->rowCount();
$audit = Db::run('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL :d DAY', ['d' => max($days, 365)])->rowCount();
$limits = Db::run('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY')->rowCount();
// Daily health summaries are small; keep at least a year for the history charts
if (\Shepherd\Migrator::tableExists('device_daily')) {
    Db::run('DELETE FROM device_daily WHERE stat_date < CURDATE() - INTERVAL :d DAY', ['d' => max($days, 365)]);
}
\Shepherd\Devices::pruneLiveFrames();
if (\Shepherd\Migrator::tableExists('sso_logins')) {
    Db::run('DELETE FROM sso_logins WHERE created_at < NOW() - INTERVAL 1 HOUR');
}

$summary = sprintf('expired %d commands; pruned %d commands, %d events, %d audit rows, %d rate-limit rows',
    $expired, $commands, $events, $audit, $limits) . ($extra ? '; ' . implode('; ', $extra) : '');

// Shown on the console's Overview page, so admins can tell cron is running
@file_put_contents(Config::storage('cron-last-run.json'),
    json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z'), 'summary' => $summary]), LOCK_EX);

echo $summary, "\n";
