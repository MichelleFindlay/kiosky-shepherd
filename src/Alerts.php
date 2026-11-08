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
            'offline_minutes' => self::offlineMinutes(),
            'ignore_tags' => self::ignoreTags(),
        ];
    }

    public static function configured(): bool
    {
        return self::emails() !== [] || self::webhooks() !== [];
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

    /**
     * Finds devices that went offline or came back since the last run and reports them.
     * Returns a short summary for the cron log.
     */
    public static function check(): string
    {
        if (!self::configured()) {
            return 'alerts off';
        }
        $threshold = self::offlineMinutes() * 60;
        $ignore = self::ignoreTags();
        $rows = Db::all("SELECT d.id, d.name, d.last_seen_at, d.checkin_seconds, d.alerted_offline_at,
                                (SELECT GROUP_CONCAT(t.tag SEPARATOR ',') FROM device_tags t WHERE t.device_id = d.id) AS tags
                           FROM devices d
                          WHERE d.status = 'active' AND d.last_seen_at IS NOT NULL");
        $down = [];
        $up = [];
        foreach ($rows as $d) {
            if (array_intersect(Devices::tagList($d['tags']), $ignore) !== []) {
                continue;
            }
            $age = time() - (int) strtotime($d['last_seen_at'] . ' UTC');
            $offline = $age > max($threshold, 3 * (int) $d['checkin_seconds']);
            if ($offline && $d['alerted_offline_at'] === null) {
                $down[] = $d;
            } elseif (!$offline && $d['alerted_offline_at'] !== null) {
                $up[] = $d;
            }
        }

        $parts = [];
        if ($down !== []) {
            $n = count($down);
            $title = $n === 1 ? "Kiosk offline: {$down[0]['name']}" : "$n kiosks offline";
            $lines = array_map(static fn ($d) => '• ' . $d['name'] . ' (last seen ' . self::ago($d['last_seen_at']) . ')', $down);
            if (self::send('devices_offline', $title, self::listText($lines), $down)) {
                self::mark($down, true);
                $parts[] = "$n offline";
            } else {
                $parts[] = "$n offline (sending failed)";
            }
        }
        if ($up !== []) {
            $n = count($up);
            $title = $n === 1 ? "Kiosk back online: {$up[0]['name']}" : "$n kiosks back online";
            $lines = array_map(static fn ($d) => '• ' . $d['name'], $up);
            if (self::send('devices_online', $title, self::listText($lines), $up)) {
                self::mark($up, false);
                $parts[] = "$n back online";
            } else {
                $parts[] = "$n back online (sending failed)";
            }
        }
        return $parts === [] ? 'no alerts' : 'alerted ' . implode(', ', $parts);
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
            $results[] = self::attempt('email', static fn () => self::sendEmail($emails, $title, $text . "\n\n" . $console));
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
        $from = (string) Config::get('alerts.email_from', '');
        if ($from === '') {
            $host = (string) (parse_url(Util::baseUrl(), PHP_URL_HOST) ?: 'localhost');
            $from = 'kiosky-shepherd@' . $host;
        }
        $headers = [
            'From' => 'Kiosky Shepherd <' . $from . '>',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'KioskyShepherd/' . SHEPHERD_VERSION,
        ];
        $subject = mb_encode_mimeheader('[Kiosky Shepherd] ' . $subject, 'UTF-8');
        if (!mail(implode(', ', $to), $subject, $body, $headers)) {
            throw new \RuntimeException('PHP mail() refused the message. Check the host\'s mail settings.');
        }
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
