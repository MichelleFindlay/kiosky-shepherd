<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * A small daily summary per device (device_daily), updated at each check-in: how long
 * it was online, its lowest and highest battery, Wi-Fi signal and page errors. Days
 * run midnight to midnight in the server's 'timezone'.
 */
final class Health
{
    public static function timezone(): string
    {
        return Schedules::defaultTimezone();
    }

    private static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::timezone());
    }

    /**
     * Adds one check-in to today's row. $device is the row as it was before this
     * check-in, so last_seen_at gives the gap since the previous one: the gap counts as
     * online time when it is no longer than the device's online threshold.
     */
    public static function record(array $device, mixed $status, int $pageErrors): void
    {
        if (!Migrator::has('device_daily')) {
            return;
        }
        $online = 0;
        if ($device['last_seen_at'] !== null) {
            $gap = time() - (int) strtotime($device['last_seen_at'] . ' UTC');
            if ($gap >= 0 && $gap <= max(120, 3 * (int) $device['checkin_seconds'])) {
                $online = $gap;
            }
        }
        $status = is_array($status) ? $status : [];
        $battery = is_int($status['battery_level'] ?? null) && $status['battery_level'] >= 0 && $status['battery_level'] <= 100
            ? $status['battery_level'] : null;
        $wifi = is_int($status['wifi_rssi'] ?? null) && $status['wifi_rssi'] >= -127 && $status['wifi_rssi'] < 0
            ? $status['wifi_rssi'] : null;

        // Each placeholder used once: native prepared statements can't repeat a name
        Db::run(
            'INSERT INTO device_daily (device_id, stat_date, checkins, online_seconds, battery_min, battery_max,
                                       wifi_min, wifi_sum, wifi_count, page_errors)
             VALUES (:d, :day, 1, :on1, :b1, :b2, :w1, :w2, :wc1, :pe1)
             ON DUPLICATE KEY UPDATE
                checkins = checkins + 1,
                online_seconds = LEAST(90000, online_seconds + :on2),
                battery_min = CASE WHEN :b3 IS NULL THEN battery_min
                                   WHEN battery_min IS NULL OR :b4 < battery_min THEN :b5 ELSE battery_min END,
                battery_max = CASE WHEN :b6 IS NULL THEN battery_max
                                   WHEN battery_max IS NULL OR :b7 > battery_max THEN :b8 ELSE battery_max END,
                wifi_min = CASE WHEN :w3 IS NULL THEN wifi_min
                                WHEN wifi_min IS NULL OR :w4 < wifi_min THEN :w5 ELSE wifi_min END,
                wifi_sum = wifi_sum + :w6,
                wifi_count = wifi_count + :wc2,
                page_errors = page_errors + :pe2',
            [
                'd' => (int) $device['id'], 'day' => (new \DateTimeImmutable('now', self::zone()))->format('Y-m-d'),
                'on1' => $online, 'on2' => $online,
                'b1' => $battery, 'b2' => $battery, 'b3' => $battery, 'b4' => $battery, 'b5' => $battery,
                'b6' => $battery, 'b7' => $battery, 'b8' => $battery,
                'w1' => $wifi, 'w2' => $wifi ?? 0, 'w3' => $wifi, 'w4' => $wifi, 'w5' => $wifi, 'w6' => $wifi ?? 0,
                'wc1' => $wifi === null ? 0 : 1, 'wc2' => $wifi === null ? 0 : 1,
                'pe1' => $pageErrors, 'pe2' => $pageErrors,
            ]
        );
    }

    /**
     * Seconds of the local day $date that a device could have been online: zero before
     * it enrolled, the part up to now for today, and 23 or 25 hours on clock-change days.
     */
    private static function possibleSeconds(\DateTimeImmutable $dayStart, int $enrolledAt, int $now): int
    {
        $start = max($dayStart->getTimestamp(), $enrolledAt);
        $end = min($dayStart->modify('+1 day')->getTimestamp(), $now);
        return max(0, $end - $start);
    }

    /**
     * The last $days days for one device, oldest first. Days before it enrolled have
     * null uptime; days with no check-ins at all have 0.
     */
    public static function history(array $device, int $days): array
    {
        $zone = self::zone();
        $today = new \DateTimeImmutable('today', $zone);
        $first = $today->modify('-' . ($days - 1) . ' days');
        $rows = Migrator::has('device_daily') ? Db::all(
            'SELECT * FROM device_daily WHERE device_id = :d AND stat_date >= :f',
            ['d' => (int) $device['id'], 'f' => $first->format('Y-m-d')]
        ) : [];
        $byDay = array_column($rows, null, 'stat_date');
        $enrolled = (int) strtotime($device['enrolled_at'] . ' UTC');
        $now = time();

        $out = [];
        for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $r = $byDay[$key] ?? null;
            $possible = self::possibleSeconds($day, $enrolled, $now);
            $out[] = [
                'date' => $key,
                'uptime' => $possible === 0 ? null : round(min(1.0, ((int) ($r['online_seconds'] ?? 0)) / $possible) * 100, 1),
                'checkins' => (int) ($r['checkins'] ?? 0),
                'battery_min' => isset($r['battery_min']) ? (int) $r['battery_min'] : null,
                'battery_max' => isset($r['battery_max']) ? (int) $r['battery_max'] : null,
                'wifi_min' => isset($r['wifi_min']) ? (int) $r['wifi_min'] : null,
                'wifi_avg' => !empty($r['wifi_count']) ? (int) round((int) $r['wifi_sum'] / (int) $r['wifi_count']) : null,
                'page_errors' => (int) ($r['page_errors'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Percent of the last $days days each device was online, for device rows (needs
     * id and enrolled_at). Null for devices enrolled less than an hour ago, retired
     * devices, and before the 1.4.0 migration.
     *
     * @param list<array<string, mixed>> $devices
     * @return array<int, float|null> device id => percent
     */
    public static function uptime(array $devices, int $days): array
    {
        $active = array_values(array_filter($devices, static fn ($d) => ($d['status'] ?? 'active') === 'active'));
        if ($active === [] || !Migrator::has('device_daily')) {
            return [];
        }
        $zone = self::zone();
        $first = (new \DateTimeImmutable('today', $zone))->modify('-' . ($days - 1) . ' days');
        $in = implode(',', array_map(static fn ($d) => (int) $d['id'], $active));
        $sums = array_column(Db::all(
            "SELECT device_id, SUM(online_seconds) AS s FROM device_daily
              WHERE device_id IN ($in) AND stat_date >= :f GROUP BY device_id",
            ['f' => $first->format('Y-m-d')]
        ), 's', 'device_id');
        $now = time();
        $out = [];
        foreach ($active as $d) {
            $start = max($first->getTimestamp(), (int) strtotime($d['enrolled_at'] . ' UTC'));
            $possible = $now - $start;
            $out[(int) $d['id']] = $possible < 3600 ? null
                : round(min(1.0, (int) ($sums[$d['id']] ?? 0) / $possible) * 100, 1);
        }
        return $out;
    }
}
