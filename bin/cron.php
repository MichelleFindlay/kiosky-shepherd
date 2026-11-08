<?php

declare(strict_types=1);

// Housekeeping. Run every few minutes from cron, e.g.
//   */10 * * * *  php /srv/kiosky-shepherd/bin/cron.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Shepherd\Config;
use Shepherd\Db;

$days = max(1, (int) Config::get('retention_days', 90));

$expired = Db::run("UPDATE commands SET status = 'expired', completed_at = NOW()
                     WHERE status IN ('pending', 'delivered') AND expires_at < NOW()")->rowCount();

// Old finished commands, and their screenshots
$old = Db::all("SELECT id, attachment FROM commands
                 WHERE status IN ('done', 'failed', 'expired', 'cancelled')
                   AND COALESCE(completed_at, created_at) < NOW() - INTERVAL :d DAY", ['d' => $days]);
foreach ($old as $c) {
    if ($c['attachment'] !== null) {
        @unlink(Config::storage('screenshots') . '/' . basename((string) $c['attachment']));
    }
}
$commands = Db::run("DELETE FROM commands WHERE status IN ('done', 'failed', 'expired', 'cancelled')
                       AND COALESCE(completed_at, created_at) < NOW() - INTERVAL :d DAY", ['d' => $days])->rowCount();

$events = Db::run('DELETE FROM device_events WHERE created_at < NOW() - INTERVAL :d DAY', ['d' => $days])->rowCount();
$audit = Db::run('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL :d DAY', ['d' => max($days, 365)])->rowCount();
$limits = Db::run('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY')->rowCount();

printf("expired %d commands; pruned %d commands, %d events, %d audit rows, %d rate-limit rows\n",
    $expired, $commands, $events, $audit, $limits);
