<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * The weekly fleet report email: what's offline, the least reliable tablets, versions,
 * failed commands and what admins changed. Sent on Monday mornings (server time zone)
 * when 'report.weekly' is on, or on demand from Server → Email.
 */
final class Report
{
    private const LAST = 'report-last.json';

    /** @return list<string> */
    public static function recipients(): array
    {
        $r = Config::get('report.recipients', []);
        $list = is_array($r) && $r !== [] ? $r : (array) Config::get('alerts.email', []);
        return array_values(array_filter($list, static fn ($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL)));
    }

    /** @return array{subject: string, text: string} */
    public static function build(int $days = 7): array
    {
        $rows = Db::all(Devices::select() . " WHERE d.status = 'active' ORDER BY d.name");
        $uptime = Health::uptime($rows, $days);
        $devices = array_map(static fn ($d) => Devices::present($d) + ['uptime' => $uptime[(int) $d['id']] ?? null], $rows);
        $online = array_filter($devices, static fn ($d) => $d['online']);
        $offline = array_filter($devices, static fn ($d) => !$d['online']);
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $out = [];
        $out[] = sprintf('%d active kiosks, %d online now, %d offline.', count($devices), count($online), count($offline));
        $enrolled = (int) Db::value('SELECT COUNT(*) FROM devices WHERE enrolled_at >= :s', ['s' => $since]);
        $retired = (int) Db::value('SELECT COUNT(*) FROM devices WHERE retired_at >= :s', ['s' => $since]);
        if ($enrolled || $retired) {
            $out[] = "This week: $enrolled enrolled, $retired retired.";
        }

        if ($offline !== []) {
            $out[] = "\nOffline now";
            foreach (array_slice($offline, 0, 15) as $d) {
                $out[] = '• ' . $d['name'] . ' (last seen ' . ($d['last_seen_at'] ? date('D j M H:i', (int) strtotime($d['last_seen_at'])) . ' UTC' : 'never') . ')';
            }
            if (count($offline) > 15) {
                $out[] = '…and ' . (count($offline) - 15) . ' more';
            }
        }

        $ranked = array_filter($devices, static fn ($d) => $d['uptime'] !== null);
        usort($ranked, static fn ($a, $b) => $a['uptime'] <=> $b['uptime']);
        $worst = array_filter(array_slice($ranked, 0, 5), static fn ($d) => $d['uptime'] < 99.5);
        if ($worst !== []) {
            $out[] = "\nLeast reliable (online in the last $days days)";
            foreach ($worst as $d) {
                $out[] = "• {$d['name']}: {$d['uptime']}%";
            }
        }

        $versions = [];
        foreach ($devices as $d) {
            $v = $d['app_version'] ?? 'unknown';
            $versions[$v] = ($versions[$v] ?? 0) + 1;
        }
        arsort($versions);
        if ($versions !== []) {
            $out[] = "\nKiosky versions";
            foreach ($versions as $v => $n) {
                $out[] = "• $v: $n";
            }
        }

        $failed = Db::all("SELECT c.type, COUNT(*) AS n FROM commands c WHERE c.status = 'failed' AND c.completed_at >= :s
                            GROUP BY c.type ORDER BY n DESC", ['s' => $since]);
        $expired = (int) Db::value("SELECT COUNT(*) FROM commands WHERE status = 'expired' AND completed_at >= :s", ['s' => $since]);
        if ($failed !== [] || $expired) {
            $out[] = "\nCommands";
            foreach ($failed as $f) {
                $out[] = "• {$f['n']} × {$f['type']} failed";
            }
            if ($expired) {
                $out[] = "• $expired expired before the device picked them up";
            }
        }

        $changes = Db::all("SELECT actor_name, action, created_at FROM audit_log
                             WHERE created_at >= :s AND actor_type IN ('admin', 'token')
                               AND action NOT IN ('admin.login', 'admin.logout', 'admin.login_failed', 'command.queued', 'command.queued_bulk')
                             ORDER BY id DESC LIMIT 25", ['s' => $since]);
        if ($changes !== []) {
            $out[] = "\nChanges by people";
            foreach ($changes as $c) {
                $out[] = '• ' . date('D j M H:i', (int) strtotime($c['created_at'] . ' UTC')) . " {$c['actor_name']}: {$c['action']}";
            }
        }
        $logins = (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.login_failed' AND created_at >= :s", ['s' => $since]);
        if ($logins > 0) {
            $out[] = "\n$logins failed sign-in attempts this week.";
        }

        $out[] = "\n" . Util::baseUrl() . '/admin/';
        return [
            'subject' => sprintf('Weekly report: %d of %d kiosks online', count($online), count($devices)),
            'text' => implode("\n", $out),
        ];
    }

    public static function send(): int
    {
        $to = self::recipients();
        if ($to === []) {
            throw new \RuntimeException('No recipients: add report recipients or alert email addresses under Server → Settings.');
        }
        $r = self::build();
        Mailer::send($to, $r['subject'], $r['text']);
        $week = (new \DateTimeImmutable('now', new \DateTimeZone(Health::timezone())))->format('o-W');
        @file_put_contents(Config::storage(self::LAST), json_encode(['at' => gmdate('c'), 'week' => $week]), LOCK_EX);
        return count($to);
    }

    /** For bin/cron.php: Monday from 08:00 local time, once a week. */
    public static function cron(): ?string
    {
        if (!Config::get('report.weekly', false)) {
            return null;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Health::timezone()));
        $last = json_decode((string) @file_get_contents(Config::storage(self::LAST)), true);
        if ($now->format('N') !== '1' || (int) $now->format('G') < 8 || (is_array($last) && ($last['week'] ?? null) === $now->format('o-W'))) {
            return null;
        }
        return 'weekly report sent to ' . self::send() . ' recipient(s)';
    }
}
