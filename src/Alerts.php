<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Tells people when devices go offline and come back, by email and/or webhooks
 * (configured under 'alerts' in config/config.php). bin/cron.php calls check()
 * every run; each change is reported once, batched into one message per run.
 */
final class Alerts
{
    private const WEBHOOK_TYPES = ['json', 'slack', 'discord', 'teams', 'ntfy'];
    private const MAX_NAMES = 25;

    /** @return list<string> */
    private static function emails(): array
    {
        $e = Config::get('alerts.email', []);
        $list = is_string($e) ? [$e] : (is_array($e) ? $e : []);
        return array_values(array_filter($list, static fn ($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL)));
    }

    /** @return list<array{type: string, url: string, headers: array<string, string>}> */
    private static function webhooks(): array
    {
        $out = [];
        foreach ((array) Config::get('alerts.webhooks', []) as $w) {
            if (!is_array($w) || !is_string($w['url'] ?? null) || !preg_match('#^https?://#i', $w['url'])) {
                continue;
            }
            $type = in_array($w['type'] ?? 'json', self::WEBHOOK_TYPES, true) ? (string) ($w['type'] ?? 'json') : 'json';
            $headers = [];
            foreach ((array) ($w['headers'] ?? []) as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $headers[$k] = $v;
                }
            }
            $out[] = ['type' => $type, 'url' => $w['url'], 'headers' => $headers];
        }
        return $out;
    }

    /** What the console shows. Never includes addresses or webhook URLs. */
    public static function summary(): array
    {
        return [
            'email' => count(self::emails()),
            'webhooks' => array_map(static fn ($w) => $w['type'], self::webhooks()),
            'push' => WebPush::subscriberCount(),
            'offline_minutes' => self::offlineMinutes(),
            'ignore_tags' => self::ignoreTags(),
            'problems' => self::enabledProblems(),
            'quiet_hours' => self::inQuietHours() ? 'now' : (Config::get('alerts.quiet_start', '') !== '' ? Config::get('alerts.quiet_start') . '–' . Config::get('alerts.quiet_end') : null),
            'silences' => count(self::silences()),
        ];
    }

    public static function configured(): bool
    {
        return self::emails() !== [] || self::webhooks() !== [] || WebPush::subscriberCount() > 0;
    }

    private static function offlineMinutes(): int
    {
        return max(5, (int) Config::get('alerts.offline_minutes', 15));
    }

    /** @return list<string> */
    private static function ignoreTags(): array
    {
        return array_values(array_filter((array) Config::get('alerts.ignore_tags', ['no-alerts']), [Devices::class, 'isTag']));
    }

    /** Problem alerts switched on (offline is always on while alerts are configured). @return list<string> */
    public static function enabledProblems(): array
    {
        $v = Config::get('alerts.problems', ['page_error', 'lockdown', 'low_battery']);
        return array_values(array_intersect(is_array($v) ? $v : [], array_keys(Devices::PROBLEMS)));
    }

    /** True during the quiet hours set in alerts.quiet_start / quiet_end (server time zone). */
    public static function inQuietHours(?int $now = null): bool
    {
        $start = (string) Config::get('alerts.quiet_start', '');
        $end = (string) Config::get('alerts.quiet_end', '');
        if (!preg_match('/^\d\d:\d\d$/', $start) || !preg_match('/^\d\d:\d\d$/', $end) || $start === $end) {
            return false;
        }
        $t = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone(Health::timezone()))->format('H:i');
        return $start < $end ? ($t >= $start && $t < $end) : ($t >= $start || $t < $end); // wraps past midnight
    }

    /** @return list<array{id: int, target_type: string, target: string, until_at: string, note: string}> active silences */
    public static function silences(): array
    {
        if (!Migrator::has('alert_silences')) {
            return [];
        }
        Db::run('DELETE FROM alert_silences WHERE until_at < NOW()');
        return Db::all('SELECT s.*, a.username FROM alert_silences s LEFT JOIN admins a ON a.id = s.created_by ORDER BY s.until_at');
    }

    /** @param list<array<string, mixed>> $silences */
    private static function isSilenced(int $deviceId, array $tags, array $silences): bool
    {
        foreach ($silences as $s) {
            if ($s['target_type'] === 'all'
                || ($s['target_type'] === 'device' && (int) $s['target'] === $deviceId)
                || ($s['target_type'] === 'tag' && in_array($s['target'], $tags, true))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reports devices that went offline or came back, and problems that started or
     * cleared, since the last run. Nothing is sent during quiet hours or for silenced
     * devices; whatever is still wrong afterwards is reported then.
     */
    public static function check(): string
    {
        if (!self::configured()) {
            return 'alerts off';
        }
        if (self::inQuietHours()) {
            return 'quiet hours';
        }
        $threshold = self::offlineMinutes() * 60;
        $ignore = self::ignoreTags();
        $silences = self::silences();
        $problemKeys = array_values(array_diff(self::enabledProblems(), ['offline']));
        $tracking = $problemKeys !== [] && Migrator::has('alert_state');
        $battery = (int) Config::get('alerts.low_battery', 20);

        $rows = Db::all(Devices::select() . " WHERE d.status = 'active' AND d.last_seen_at IS NOT NULL");
        $uptime = $tracking ? Health::uptime($rows, 7) : [];
        $state = $tracking ? Db::all('SELECT * FROM alert_state') : [];
        $known = [];
        foreach ($state as $r) {
            $known[(int) $r['device_id']][$r['reason']] = $r;
        }

        $down = $up = $started = $cleared = [];
        $seenDevices = [];
        foreach ($rows as $d) {
            $id = (int) $d['id'];
            $tags = Devices::tagList($d['tags'] ?? null);
            if (array_intersect($tags, $ignore) !== [] || self::isSilenced($id, $tags, $silences)) {
                $seenDevices[$id] = true; // leave its state alone until the silence ends
                continue;
            }
            $seenDevices[$id] = true;
            $age = time() - (int) strtotime($d['last_seen_at'] . ' UTC');
            $offline = $age > max($threshold, 3 * (int) $d['checkin_seconds']);
            if ($offline && $d['alerted_offline_at'] === null) {
                $down[] = $d;
            } elseif (!$offline && $d['alerted_offline_at'] !== null) {
                $up[] = $d;
            }
            if (!$tracking) {
                continue;
            }
            // Problems are judged only while the device is online (offline has its own alert)
            $now = $offline ? [] : array_intersect_key(
                Devices::problems(Devices::present($d) + ['uptime_7d' => $uptime[$id] ?? null], $battery),
                array_flip($problemKeys)
            );
            foreach ($now as $key => $text) {
                $row = $known[$id][$key] ?? null;
                if ($row === null) {
                    // First seen: wait one more run, so flickers don't alert
                    Db::run('INSERT IGNORE INTO alert_state (device_id, reason) VALUES (:d, :r)', ['d' => $id, 'r' => $key]);
                } elseif ($row['alerted_at'] === null && strtotime($row['since'] . ' UTC') <= time() - 300) {
                    $started[] = ['device' => $d, 'key' => $key, 'text' => $text];
                }
            }
            if (!$offline) {
                foreach ($known[$id] ?? [] as $key => $row) {
                    if (!isset($now[$key])) {
                        if ($row['alerted_at'] !== null) {
                            $cleared[] = ['device' => $d, 'key' => $key, 'text' => Devices::PROBLEMS[$key] ?? $key];
                        }
                        Db::run('DELETE FROM alert_state WHERE device_id = :d AND reason = :r', ['d' => $id, 'r' => $key]);
                    }
                }
            }
        }

        $parts = [];
        if ($down !== []) {
            $n = count($down);
            $title = $n === 1 ? "Kiosk offline: {$down[0]['name']}" : "$n kiosks offline";
            $lines = array_map(static fn ($d) => '• ' . $d['name'] . ' (last seen ' . self::ago($d['last_seen_at']) . ')', $down);
            $parts[] = self::deliver('devices_offline', $title, $lines, $down, static fn () => self::mark($down, true), "$n offline");
            foreach ($down as $d) {
                Events::emit('device.offline', ['device' => Events::device($d)]);
            }
        }
        if ($up !== []) {
            $n = count($up);
            $title = $n === 1 ? "Kiosk back online: {$up[0]['name']}" : "$n kiosks back online";
            $lines = array_map(static fn ($d) => '• ' . $d['name'], $up);
            $parts[] = self::deliver('devices_online', $title, $lines, $up, static fn () => self::mark($up, false), "$n back online");
            foreach ($up as $d) {
                Events::emit('device.online', ['device' => Events::device($d)]);
            }
        }
        if ($started !== []) {
            $n = count($started);
            $title = $n === 1 ? "Kiosk problem: {$started[0]['device']['name']}" : "$n kiosk problems";
            $lines = array_map(static fn ($p) => '• ' . $p['device']['name'] . ': ' . $p['text'], $started);
            $parts[] = self::deliver('device_problems', $title, $lines, array_column($started, 'device'), static function () use ($started): void {
                foreach ($started as $p) {
                    Db::run('UPDATE alert_state SET alerted_at = NOW() WHERE device_id = :d AND reason = :r',
                        ['d' => (int) $p['device']['id'], 'r' => $p['key']]);
                }
            }, "$n problems");
            foreach ($started as $p) {
                Events::emit('device.problem', ['device' => Events::device($p['device']), 'problem' => $p['key'], 'text' => $p['text']]);
            }
        }
        if ($cleared !== []) {
            $n = count($cleared);
            $title = $n === 1 ? "Kiosk problem resolved: {$cleared[0]['device']['name']}" : "$n kiosk problems resolved";
            $lines = array_map(static fn ($p) => '• ' . $p['device']['name'] . ': ' . strtolower($p['text']), $cleared);
            $parts[] = self::deliver('device_problems_resolved', $title, $lines, array_column($cleared, 'device'), static fn () => null, "$n resolved");
            foreach ($cleared as $p) {
                Events::emit('device.problem_resolved', ['device' => Events::device($p['device']), 'problem' => $p['key']]);
            }
        }
        // Forget problems of devices that were retired or deleted
        if ($tracking && $seenDevices !== []) {
            Db::run('DELETE FROM alert_state WHERE device_id NOT IN (' . implode(',', array_keys($seenDevices)) . ')');
        }
        return $parts === [] ? 'no alerts' : 'alerted ' . implode(', ', $parts);
    }

    /**
     * Sends one batched message; on success runs $onSent (which marks what was reported).
     *
     * @param list<string> $lines
     * @param list<array<string, mixed>> $devices
     */
    private static function deliver(string $event, string $title, array $lines, array $devices, callable $onSent, string $label): string
    {
        if (self::send($event, $title, self::listText($lines), $devices)) {
            $onSent();
            return $label;
        }
        return "$label (sending failed)";
    }

    /** @param list<string> $lines */
    private static function listText(array $lines): string
    {
        $more = count($lines) - self::MAX_NAMES;
        $lines = array_slice($lines, 0, self::MAX_NAMES);
        if ($more > 0) {
            $lines[] = "…and $more more";
        }
        return implode("\n", $lines);
    }

    private static function mark(array $devices, bool $offline): void
    {
        $in = implode(',', array_map(static fn ($d) => (int) $d['id'], $devices));
        Db::run('UPDATE devices SET alerted_offline_at = ' . ($offline ? 'NOW()' : 'NULL') . " WHERE id IN ($in)");
    }

    private static function ago(string $dt): string
    {
        $m = intdiv(time() - (int) strtotime($dt . ' UTC'), 60);
        if ($m < 60) {
            return "$m min ago";
        }
        if ($m < 2880) {
            return intdiv($m, 60) . ' h ago';
        }
        return intdiv($m, 1440) . ' days ago';
    }

    /**
     * Sends one message to every channel.
     *
     * @param list<array<string, mixed>> $devices
     * @return bool true when at least one channel accepted it
     */
    public static function send(string $event, string $title, string $text, array $devices = []): bool
    {
        $results = self::sendAll($event, $title, $text, $devices);
        foreach ($results as $r) {
            if (!$r['ok']) {
                error_log('[kiosky-shepherd] alert via ' . $r['channel'] . ' failed: ' . $r['error']);
            }
        }
        return in_array(true, array_column($results, 'ok'), true);
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return list<array{channel: string, ok: bool, error: ?string}>
     */
    public static function sendAll(string $event, string $title, string $text, array $devices = []): array
    {
        $console = Util::baseUrl() . '/admin/';
        $results = [];
        $emails = self::emails();
        if ($emails !== []) {
            $results[] = self::attempt(Mailer::transport() === 'smtp' ? 'email (SMTP)' : 'email', static fn () => self::sendEmail($emails, $title, $text . "\n\n" . $console));
        }
        if (WebPush::subscriberCount() > 0) {
            $results[] = self::attempt('browser push', static fn () => WebPush::broadcast($title, $text, '/admin/'));
        }
        foreach (self::webhooks() as $w) {
            $results[] = self::attempt($w['type'] . ' webhook',
                static fn () => self::sendWebhook($w, $event, $title, $text, $devices, $console));
        }
        return $results;
    }

    /** @return array{channel: string, ok: bool, error: ?string} */
    private static function attempt(string $channel, callable $fn): array
    {
        try {
            $fn();
            return ['channel' => $channel, 'ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['channel' => $channel, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param list<string> $to */
    private static function sendEmail(array $to, string $subject, string $body): void
    {
        Mailer::send($to, $subject, $body); // SMTP or PHP mail(), see 'mail' in config.php
    }

    /** @param array{type: string, url: string, headers: array<string, string>} $w */
    private static function sendWebhook(array $w, string $event, string $title, string $text, array $devices, string $console): void
    {
        $headers = $w['headers'] + ['Content-Type' => 'application/json'];
        switch ($w['type']) {
            case 'slack': // also Mattermost and Rocket.Chat incoming webhooks
                $body = Db::json(['text' => "*$title*\n$text\n<$console|Open Kiosky Shepherd>"]);
                break;
            case 'discord':
                $body = Db::json(['content' => "**$title**\n$text\n$console"]);
                break;
            case 'teams': // a Teams "Workflows" webhook (Post to a channel when a webhook request is received)
                $body = Db::json(['type' => 'message', 'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [
                            ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                            ['type' => 'TextBlock', 'text' => str_replace("\n", "\n\n", $text), 'wrap' => true],
                        ],
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Open Kiosky Shepherd', 'url' => $console]],
                    ],
                ]]]);
                break;
            case 'ntfy':
                $headers = $w['headers'] + [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Title' => self::asciiHeader($title),
                    'Tags' => $event === 'devices_online' ? 'white_check_mark' : ($event === 'test' ? 'bell' : 'warning'),
                    'Click' => $console,
                ];
                $body = $text;
                break;
            default:
                $body = Db::json([
                    'event' => $event,
                    'title' => $title,
                    'text' => $text,
                    'devices' => array_map(static fn ($d) => [
                        'id' => (int) $d['id'],
                        'name' => $d['name'],
                        'last_seen_at' => Util::iso($d['last_seen_at'] ?? null),
                    ], $devices),
                    'console' => $console,
                    'server' => Util::baseUrl(),
                    'sent_at' => gmdate('c'),
                ]);
        }
        $r = HttpClient::request('POST', $w['url'], $headers, $body);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException("HTTP {$r['status']}: " . mb_substr(trim($r['body']), 0, 200));
        }
    }

    /** HTTP header values must be plain ASCII; ntfy shows the body in full anyway. */
    private static function asciiHeader(string $s): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return preg_replace('/[^\x20-\x7E]/', '', $ascii === false ? $s : $ascii) ?? '';
    }
}
