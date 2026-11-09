<?php

declare(strict_types=1);

namespace Shepherd\Api;

use Shepherd\Api\Admin\DevicesApi;
use Shepherd\Audit;
use Shepherd\Auth\DeviceAuth;
use Shepherd\Auth\Principal;
use Shepherd\Commands;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Devices;
use Shepherd\Health;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Policy;
use Shepherd\RateLimiter;
use Shepherd\Releases;
use Shepherd\Util;

/** Endpoints called by the Kiosky app. See docs/PROTOCOL.md. */
final class DeviceApi
{
    /** POST /api/v1/device/enroll */
    public static function enroll(Request $req): Response
    {
        RateLimiter::hit('enroll:' . $req->ip(), 20, 600);

        $code = Util::normaliseCode((string) $req->input('token', ''));
        $info = $req->input('device', []);
        if (!is_array($info)) {
            throw HttpError::validation(['device' => 'Must be an object.']);
        }
        if ($code === '' || strlen($code) > 32) {
            throw HttpError::validation(['token' => 'Enter the enrollment code.']);
        }

        $fields = [
            'android_id' => Util::clip($info['android_id'] ?? null, 64),
            'manufacturer' => Util::clip($info['manufacturer'] ?? null, 100),
            'model' => Util::clip($info['model'] ?? null, 100),
            'android_version' => Util::clip($info['android_version'] ?? null, 32),
            'sdk_int' => is_int($info['sdk_int'] ?? null) ? max(0, min(65535, $info['sdk_int'])) : null,
            'app_version' => Util::clip($info['app_version'] ?? null, 32),
            'app_version_code' => is_int($info['app_version_code'] ?? null) ? max(0, $info['app_version_code']) : null,
        ];

        [$device, $secret, $reused, $tokenId] = Db::transaction(static function () use ($code, $info, $fields) {
            $tok = Db::one('SELECT * FROM enrollment_tokens WHERE code = :c FOR UPDATE', ['c' => $code]);
            if ($tok === null || (int) $tok['revoked'] === 1
                || ($tok['expires_at'] !== null && strtotime($tok['expires_at'] . ' UTC') < time())
                || ($tok['max_uses'] !== null && (int) $tok['uses'] >= (int) $tok['max_uses'])) {
                throw new HttpError(403, 'enrollment_invalid', 'This enrollment code is wrong, used up or expired.');
            }

            [$secret, $hash] = DeviceAuth::newSecret();
            $existing = $fields['android_id'] !== null
                ? Db::one("SELECT * FROM devices WHERE android_id = :a AND status = 'active' ORDER BY id DESC LIMIT 1",
                    ['a' => $fields['android_id']])
                : null;

            if ($existing !== null) {
                // Same tablet enrolling again (app reinstalled or data cleared): keep its
                // identity, name and overrides, issue a new secret, apply the token's profile.
                Db::update('devices', $fields + [
                    'secret_hash' => $hash,
                    'profile_id' => (int) $tok['profile_id'],
                    'enrolled_with' => (int) $tok['id'],
                ], ['id' => (int) $existing['id']]);
                $deviceId = (int) $existing['id'];
                // Commands queued for the old installation would confuse the new one
                Db::run("UPDATE commands SET status = 'cancelled', completed_at = NOW()
                          WHERE device_id = :d AND status IN ('pending', 'delivered')", ['d' => $deviceId]);
            } else {
                $n = (int) $tok['uses'] + 1;
                $name = $tok['name_prefix'] !== ''
                    ? $tok['name_prefix'] . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT)
                    : (Util::clip(trim((string) ($info['name'] ?? '')), 100) ?: ($fields['model'] ?? 'Kiosk'));
                $deviceId = Db::insert('devices', $fields + [
                    'uid' => Util::code(16, true),
                    'secret_hash' => $hash,
                    'name' => $name,
                    'profile_id' => (int) $tok['profile_id'],
                    'overrides' => '{}',
                    'enrolled_with' => (int) $tok['id'],
                ]);
            }
            Db::run('UPDATE enrollment_tokens SET uses = uses + 1 WHERE id = :id', ['id' => (int) $tok['id']]);
            // The code's tags are added to the device's, and its notes fill in empty notes
            $codeTags = array_values((array) Db::decode($tok['tags'] ?? null));
            if ($codeTags !== [] && \Shepherd\Migrator::has('device_tags')) {
                $current = array_column(Db::all('SELECT tag FROM device_tags WHERE device_id = :d', ['d' => $deviceId]), 'tag');
                $merged = array_slice(array_values(array_unique(array_merge($current, $codeTags))), 0, \Shepherd\Devices::MAX_TAGS);
                sort($merged);
                \Shepherd\Devices::setTags($deviceId, $merged);
            }
            if (is_string($tok['notes'] ?? null) && $tok['notes'] !== '') {
                Db::run("UPDATE devices SET notes = :n WHERE id = :id AND (notes IS NULL OR notes = '')",
                    ['n' => $tok['notes'], 'id' => $deviceId]);
            }
            Db::insert('device_events', [
                'device_id' => $deviceId,
                'type' => $existing !== null ? 're_enrolled' : 'enrolled',
                'data' => Db::json(['token' => $tok['label'] ?: Util::formatCode($tok['code'])]),
            ]);
            $device = Db::one('SELECT * FROM devices WHERE id = :id', ['id' => $deviceId]);
            return [$device, $secret, $existing !== null, (int) $tok['id']];
        });

        $principal = new Principal('device', (int) $device['id'], (string) $device['name'], 'device', $req->ip());
        Audit::log($principal, $reused ? 'device.re_enrolled' : 'device.enrolled', 'device', (int) $device['id'],
            ['token_id' => $tokenId, 'model' => $fields['model']]);

        $policy = Policy::forDevice($device);
        Db::update('devices', ['checkin_seconds' => (int) $policy['checkin_seconds'], 'last_ip' => $req->ip()],
            ['id' => (int) $device['id']]);
        \Shepherd\Events::emit('device.enrolled', ['device' => \Shepherd\Events::device(\Shepherd\Devices::find((int) $device['id']) ?? $device),
            're_enrolled' => $reused, 'model' => $fields['model']]);

        return Response::json([
            'device_id' => $device['uid'],
            'device_token' => $device['uid'] . '.' . $secret,
            'name' => $device['name'],
            'server_time' => gmdate('c'),
            'policy' => $policy,
            'update' => Releases::targetFor($policy, $device),
            'commands' => [],
            'checkin_seconds' => (int) $policy['checkin_seconds'],
        ], 201);
    }

    /** POST /api/v1/device/checkin */
    public static function checkin(Request $req): Response
    {
        [, $device] = DeviceAuth::require($req);
        // Normal use is one check-in per checkin_seconds (≥ 15 s) plus one after each command
        RateLimiter::hit('dev-checkin:' . $device['uid'], 120, 600);
        $deviceId = (int) $device['id'];
        $body = $req->json();

        $status = $body['status'] ?? null;
        $stateJson = null;
        if (is_array($status)) {
            $stateJson = Db::json($status);
            if (strlen($stateJson) > 16384) {
                $stateJson = Db::json(['error' => 'status report too large']);
            }
        }

        $policy = Policy::forDevice($device);
        $appVersion = Util::clip($body['app_version'] ?? null, 32);
        $appCode = is_int($body['app_version_code'] ?? null) ? max(0, $body['app_version_code']) : null;
        $applied = Util::clip($body['policy_version'] ?? null, 16);

        $update = [
            'last_seen_at' => Util::now(),
            'last_ip' => $req->ip(),
            'checkin_seconds' => (int) $policy['checkin_seconds'],
        ];
        if ($stateJson !== null) {
            $update['state'] = $stateJson;
        }
        if ($applied !== null) {
            $update['applied_policy_version'] = $applied;
        }
        if ($appVersion !== null) {
            $update['app_version'] = $appVersion;
        }
        if ($appCode !== null) {
            $update['app_version_code'] = $appCode;
        }
        Db::update('devices', $update, ['id' => $deviceId]);

        if ($appVersion !== null && $device['app_version'] !== null && $appVersion !== $device['app_version']) {
            Db::insert('device_events', ['device_id' => $deviceId, 'type' => 'app_version_changed',
                'data' => Db::json(['from' => $device['app_version'], 'to' => $appVersion])]);
        }

        // Events the app queued since its last check-in (page errors, update results…)
        $events = $body['events'] ?? [];
        $pageErrors = 0;
        if (is_array($events) && array_is_list($events)) {
            foreach (array_slice($events, 0, 50) as $e) {
                if (!is_array($e) || !is_string($e['type'] ?? null)) {
                    continue;
                }
                $type = preg_replace('/[^a-z0-9_.]/', '', strtolower($e['type'])) ?: 'unknown';
                if ($type === 'page_error') {
                    $pageErrors++;
                }
                $data = ['at' => Util::clip($e['at'] ?? null, 40), 'data' => $e['data'] ?? null];
                $json = Db::json($data);
                if (strlen($json) > 4096) {
                    $json = Db::json(['at' => $data['at'], 'data' => 'truncated']);
                }
                Db::insert('device_events', ['device_id' => $deviceId, 'type' => substr($type, 0, 48), 'data' => $json]);
                if ($type === 'update_healthy' || $type === 'update_unhealthy') {
                    try { // staged rollouts: count it, and pause a release that keeps crashing
                        Releases::recordHealth($device, $type, $e['data'] ?? null);
                    } catch (\Throwable $ex) {
                        error_log('[kiosky-shepherd] update health for device ' . $deviceId . ': ' . $ex->getMessage());
                    }
                }
            }
        }

        // Daily health summary. Never let it break a check-in.
        try {
            Health::record($device, $status, $pageErrors);
        } catch (\Throwable $e) {
            error_log('[kiosky-shepherd] health record for device ' . $deviceId . ': ' . $e->getMessage());
        }

        return Response::json([
            'server_time' => gmdate('c'),
            'device' => ['id' => $device['uid'], 'name' => $device['name']],
            'policy' => $policy,
            'update' => Releases::targetFor($policy, $device),
            'commands' => Commands::deliver($deviceId),
            'checkin_seconds' => self::nextCheckin($device, (int) $policy['checkin_seconds']),
        ]);
    }

    /**
     * Seconds until the next check-in. Shorter than the policy's interval while an admin
     * has the device page open (fast_checkin_until), so commands arrive quickly.
     */
    private static function nextCheckin(array $device, int $policySeconds): int
    {
        $fast = $device['fast_checkin_until'] ?? null; // column added by the 1.3.0 migration
        if (is_string($fast) && strtotime($fast . ' UTC') > time()) {
            return min($policySeconds, DevicesApi::fastSeconds());
        }
        return $policySeconds;
    }

    /** POST /api/v1/device/commands/{id}/result */
    public static function commandResult(Request $req): Response
    {
        [, $device] = DeviceAuth::require($req);
        RateLimiter::hit('dev-result:' . $device['uid'], 300, 600);
        $id = $req->intParam('id');
        $cmd = Db::one('SELECT * FROM commands WHERE id = :id AND device_id = :d',
            ['id' => $id, 'd' => (int) $device['id']]);
        if ($cmd === null) {
            throw HttpError::notFound('Command');
        }
        if (!in_array($cmd['status'], ['pending', 'delivered'], true)) {
            // Already finished (a repeated report, or cancelled/expired meanwhile)
            return Response::json(['ok' => true, 'status' => $cmd['status']]);
        }

        $status = $req->input('status');
        if (!in_array($status, ['done', 'failed'], true)) {
            throw HttpError::validation(['status' => 'Use "done" or "failed".']);
        }
        $result = ['message' => Util::clip($req->input('message'), 1000)];
        $data = $req->input('data');
        if (is_array($data)) {
            $json = Db::json($data);
            $result['data'] = strlen($json) <= 8192 ? $data : 'truncated';
        }

        $attachment = null;
        $shot = $req->input('screenshot');
        if ($cmd['type'] === 'screenshot' && $status === 'done' && is_string($shot) && $shot !== '') {
            $attachment = self::saveScreenshot($id, $shot);
        }
        $bundle = $req->input('bundle');
        if ($cmd['type'] === 'diagnostics' && is_string($bundle) && $bundle !== '') {
            $attachment = self::saveBundle($id, $bundle);
        }

        Db::update('commands', [
            'status' => $status,
            'result' => Db::json($result),
            'attachment' => $attachment,
            'completed_at' => Util::now(),
        ], ['id' => $id]);
        if ($status === 'failed') {
            \Shepherd\Events::emit('command.failed', ['command' => ['id' => $id, 'type' => $cmd['type'], 'message' => $result['message']],
                'device' => \Shepherd\Events::device($device)]);
        }

        return Response::json(['ok' => true, 'status' => $status]);
    }

    private static function saveScreenshot(int $commandId, string $b64): string
    {
        [$bin, $ext] = self::decodeImage($b64);
        $dir = Config::storage('screenshots');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $name = $commandId . '.' . $ext;
        if (file_put_contents("$dir/$name", $bin, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write $dir/$name");
        }
        return $name;
    }

    /**
     * Base64 image → [binary, extension]. Only JPEG, PNG and WebP are accepted, checked
     * by their magic bytes, so nothing else can be stored or served back.
     *
     * @return array{0: string, 1: string}
     */
    private static function decodeImage(string $b64): array
    {
        $max = (int) Config::get('max_screenshot_mb', 4) * 1024 * 1024;
        if (strlen($b64) > $max * 4 / 3 + 16) {
            throw new HttpError(413, 'too_large', 'The image is too large.');
        }
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            throw HttpError::validation(['screenshot' => 'Must be base64.']);
        }
        $ext = match (true) {
            str_starts_with($bin, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($bin, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP' => 'webp',
            default => throw HttpError::validation(['screenshot' => 'Send a JPEG, PNG or WebP image.']),
        };
        return [$bin, $ext];
    }

    /**
     * POST /api/v1/device/live  {"screenshot": "<base64>"}
     * A live-view frame, sent every few seconds while a live_view command runs. Each
     * frame replaces the previous one; nothing is kept.
     */
    public static function liveFrame(Request $req): Response
    {
        [, $device] = DeviceAuth::require($req);
        RateLimiter::hit('dev-live:' . $device['uid'], 700, 600); // up to one frame a second
        $shot = $req->input('screenshot');
        if (!is_string($shot) || $shot === '') {
            throw HttpError::validation(['screenshot' => 'Send the frame as base64.']);
        }
        [$bin, $ext] = self::decodeImage($shot);
        Devices::saveLiveFrame((int) $device['id'], $bin, $ext);
        return Response::json(['ok' => true]);
    }

    /**
     * A diagnostics bundle: base64 of a .gz, a .zip or plain text, at most
     * max_diagnostics_mb (10 MB) decoded. Stored as storage/diagnostics/diag-<id>.<ext>.
     */
    private static function saveBundle(int $commandId, string $b64): string
    {
        $max = (int) Config::get('max_diagnostics_mb', 10) * 1024 * 1024;
        if (strlen($b64) > $max * 4 / 3 + 16) {
            throw new HttpError(413, 'too_large', 'The diagnostics bundle is too large.');
        }
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            throw HttpError::validation(['bundle' => 'Must be base64.']);
        }
        $ext = match (true) {
            str_starts_with($bin, "\x1F\x8B") => 'gz',
            str_starts_with($bin, "PK\x03\x04") => 'zip',
            mb_check_encoding($bin, 'UTF-8') => 'txt',
            default => throw HttpError::validation(['bundle' => 'Send a .gz, a .zip or UTF-8 text.']),
        };
        $dir = Config::storage('diagnostics');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $name = "diag-$commandId.$ext";
        if (file_put_contents("$dir/$name", $bin, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write $dir/$name");
        }
        return $name;
    }

    /** GET /api/v1/device/releases/{id}/download */
    public static function downloadRelease(Request $req): Response
    {
        [, $device] = DeviceAuth::require($req);
        RateLimiter::hit('dev-download:' . $device['uid'], 30, 3600);
        $rel = Db::one('SELECT * FROM releases WHERE id = :id', ['id' => $req->intParam('id')]);
        if ($rel === null) {
            throw HttpError::notFound('Release');
        }
        return Response::file(
            Releases::dir() . '/' . basename((string) $rel['file_name']),
            'application/vnd.android.package-archive',
            'kiosky-' . $rel['version_name'] . '.apk',
            ['X-Checksum-SHA256' => (string) $rel['sha256'], 'Cache-Control' => 'private, max-age=3600']
        );
    }
}
