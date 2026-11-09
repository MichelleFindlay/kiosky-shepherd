<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Outgoing event webhooks ('events.webhooks' in Server → Settings): POSTs JSON such as
 * {"event": "device.enrolled", "at": …, "data": {…}} to each subscribed URL, signed
 * with HMAC-SHA256 when the webhook has a secret (X-Shepherd-Signature: sha256=…).
 *
 * Events are queued in event_outbox and sent straight after the response that caused
 * them (so a slow receiver never delays a tablet's check-in), and retried by cron
 * with backoff for up to a day.
 */
final class Events
{
    public const TYPES = [
        'device.enrolled' => 'A device enrolled (or re-enrolled)',
        'device.retired' => 'A device was retired',
        'device.offline' => 'A device went offline',
        'device.online' => 'A device came back online',
        'device.problem' => 'A device problem started (page error, low battery…)',
        'device.problem_resolved' => 'A device problem cleared',
        'device.profile_changed' => 'A device moved to another profile',
        'command.failed' => 'A command failed on a device',
        'profile.updated' => 'A profile was changed',
        'release.halted' => 'A release\'s rollout was paused because devices reported it crashing',
    ];

    private const MAX_ATTEMPTS = 8;
    private static bool $flushScheduled = false;

    /** @return list<array{url: string, events: list<string>, secret: string}> */
    public static function webhooks(): array
    {
        $out = [];
        foreach ((array) Config::get('events.webhooks', []) as $w) {
            if (!is_array($w) || !is_string($w['url'] ?? null) || !preg_match('#^https?://#i', $w['url'])) {
                continue;
            }
            $events = array_values(array_intersect((array) ($w['events'] ?? []), array_keys(self::TYPES)));
            $out[] = ['url' => $w['url'], 'events' => $events === [] ? array_keys(self::TYPES) : $events,
                'secret' => is_string($w['secret'] ?? null) ? $w['secret'] : ''];
        }
        return $out;
    }

    /** The fields of a device that go into event payloads. */
    public static function device(array $d): array
    {
        return [
            'id' => (int) $d['id'],
            'uid' => $d['uid'] ?? null,
            'name' => $d['name'] ?? null,
            'profile' => $d['profile_name'] ?? null,
            'tags' => Devices::tagList($d['tags'] ?? null),
            'last_seen_at' => Util::iso($d['last_seen_at'] ?? null),
        ];
    }

    /** Queues an event for every webhook that wants it. Never throws. */
    public static function emit(string $event, array $data): void
    {
        try {
            $hooks = array_filter(self::webhooks(), static fn ($w) => in_array($event, $w['events'], true));
            if ($hooks === [] || !Migrator::has('event_outbox')) {
                return;
            }
            $payload = Db::json(['event' => $event, 'at' => gmdate('c'), 'server' => Util::baseUrl(), 'data' => $data]);
            foreach ($hooks as $w) {
                Db::insert('event_outbox', ['event' => $event, 'payload' => $payload, 'url' => $w['url']]);
            }
            self::scheduleFlush();
        } catch (\Throwable $e) {
            error_log('[kiosky-shepherd] event ' . $event . ': ' . $e->getMessage());
        }
    }

    /** Sends queued events once the response has gone (PHP-FPM), else at the end of the request. */
    private static function scheduleFlush(): void
    {
        if (self::$flushScheduled || PHP_SAPI === 'cli') {
            return;
        }
        self::$flushScheduled = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                self::flush(5);
            } catch (\Throwable $e) {
                error_log('[kiosky-shepherd] event delivery: ' . $e->getMessage());
            }
        });
    }

    /** Delivers due events (cron calls this too). Returns how many were delivered. */
    public static function flush(int $limit = 50): int
    {
        if (!Migrator::has('event_outbox')) {
            return 0;
        }
        $secrets = array_column(self::webhooks(), 'secret', 'url');
        $due = Db::all('SELECT * FROM event_outbox WHERE delivered_at IS NULL AND next_attempt_at <= NOW() ORDER BY id LIMIT :l', ['l' => $limit]);
        $ok = 0;
        foreach ($due as $row) {
            $headers = ['Content-Type' => 'application/json', 'X-Shepherd-Event' => $row['event'],
                'X-Shepherd-Delivery' => (string) $row['id']];
            $secret = $secrets[$row['url']] ?? '';
            if ($secret !== '') {
                $headers['X-Shepherd-Signature'] = 'sha256=' . hash_hmac('sha256', $row['payload'], $secret);
            }
            try {
                $r = HttpClient::request('POST', $row['url'], $headers, $row['payload']);
                if ($r['status'] < 200 || $r['status'] >= 300) {
                    throw new \RuntimeException("HTTP {$r['status']}");
                }
                Db::update('event_outbox', ['delivered_at' => Util::now(), 'last_error' => null], ['id' => (int) $row['id']]);
                $ok++;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                // 1, 2, 4, 8 … minutes; given up after MAX_ATTEMPTS (about four hours)
                Db::run('UPDATE event_outbox SET attempts = :a, last_error = :e,
                         next_attempt_at = ' . ($attempts >= self::MAX_ATTEMPTS ? "'2999-01-01'" : 'NOW() + INTERVAL :m MINUTE') . '
                         WHERE id = :id', ['a' => $attempts, 'e' => Util::clip($e->getMessage(), 255), 'id' => (int) $row['id']]
                    + ($attempts >= self::MAX_ATTEMPTS ? [] : ['m' => 2 ** ($attempts - 1)]));
            }
        }
        return $ok;
    }

    /** Recent deliveries for the console. */
    public static function recent(int $limit = 20): array
    {
        if (!Migrator::has('event_outbox')) {
            return [];
        }
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'event' => $r['event'],
            'url' => $r['url'],
            'status' => $r['delivered_at'] !== null ? 'delivered' : ((int) $r['attempts'] >= self::MAX_ATTEMPTS ? 'gave up' : 'pending'),
            'attempts' => (int) $r['attempts'],
            'last_error' => $r['last_error'],
            'created_at' => Util::iso($r['created_at']),
        ], Db::all('SELECT * FROM event_outbox ORDER BY id DESC LIMIT :l', ['l' => $limit]));
    }

    /** Old deliveries are pruned by cron. */
    public static function prune(): void
    {
        if (Migrator::has('event_outbox')) {
            Db::run('DELETE FROM event_outbox WHERE created_at < NOW() - INTERVAL 14 DAY');
        }
    }
}
