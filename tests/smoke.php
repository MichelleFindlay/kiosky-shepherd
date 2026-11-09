<?php

declare(strict_types=1);

// End-to-end check of the admin and device APIs against a running server with a
// freshly migrated database. Run by .github/workflows/ci.yml; also usable locally:
//   php tests/smoke.php http://127.0.0.1:8080 <admin-username> <admin-password>
// It enrolls a fake device, so don't point it at a production server.

if (PHP_SAPI !== 'cli' || $argc < 4) {
    fwrite(STDERR, "Usage: php tests/smoke.php <base-url> <admin-username> <admin-password>\n");
    exit(2);
}
[, $base, $adminUser, $adminPass] = $argv;
$base = rtrim($base, '/');

require dirname(__DIR__) . '/src/Util.php';
require dirname(__DIR__) . '/src/Auth/Totp.php';

use Shepherd\Auth\Totp;

/** Tiny HTTP client with a cookie jar and the console's CSRF header. */
final class Client
{
    /** @var array<string, string> */
    private array $cookies = [];
    public ?string $csrf = null;
    public ?string $bearer = null;

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{status: int, body: mixed, raw: string, headers: array<string, string>} */
    /** @param list<string> $extra raw header lines, e.g. "X-Forwarded-For: 203.0.113.9" */
    public function call(string $method, string $path, mixed $body = null, array $extra = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = array_merge(['Accept: application/json'], $extra);
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if ($this->csrf !== null && $method !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($this->bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->bearer;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $m)) {
                    if ($m[2] === '' || $m[2] === 'deleted') {
                        unset($this->cookies[$m[1]]);
                    } else {
                        $this->cookies[$m[1]] = $m[2];
                    }
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException("$method $path: " . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw, 'headers' => $respHeaders];
    }
}

$step = 0;
function check(bool $ok, string $what, mixed $context = null): void
{
    global $step;
    $step++;
    if ($ok) {
        echo "ok $step - $what\n";
        return;
    }
    echo "FAILED $step - $what\n";
    if ($context !== null) {
        echo is_string($context) ? $context : json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }
    exit(1);
}

/** Calls and insists on a status code. */
function expect(Client $c, int $status, string $method, string $path, mixed $body = null): mixed
{
    $r = $c->call($method, $path, $body);
    check($r['status'] === $status, "$method $path → $status", ['got' => $r['status'], 'body' => $r['body'] ?? $r['raw']]);
    return $r['body'];
}

$A = '/api/v1/admin';
$D = '/api/v1/device';

// ---- TOTP against the RFC 6238 test vectors (SHA-1 seed "12345678901234567890") ----
$rfc = Totp::base32Encode('12345678901234567890');
check($rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 encoding matches RFC 4648', $rfc);
check(Totp::base32Decode($rfc) === '12345678901234567890', 'base32 decoding round-trips');
foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $want) {
    $got = Totp::code($rfc, Totp::currentStep($time));
    check($got === $want, "TOTP code at T=$time", ['want' => $want, 'got' => $got]);
}
check(Totp::verify($rfc, '287082', null, 59) === 1, 'verify accepts the current code');
check(Totp::verify($rfc, '287082', 1, 59) === null, 'verify refuses a code already used');

// ---- Basics -------------------------------------------------------------------
$anon = new Client($base);
$h = expect($anon, 200, 'GET', '/api/v1/health');
check(($h['ok'] ?? false) === true, 'health reports ok');

$admin = new Client($base);
$s = expect($admin, 200, 'POST', "$A/session", ['username' => $adminUser, 'password' => $adminPass]);
$admin->csrf = $s['csrf_token'] ?? null;
check(is_string($admin->csrf), 'login returns a CSRF token');
check(($s['user']['two_factor'] ?? null) === false, 'two-factor is off for a new user');
check(($s['server']['pending_migrations'] ?? null) === [], 'no pending migrations', $s['server'] ?? null);
check(isset($s['server']['cron']['stale']), 'session includes cron status');

// ---- Enrollment and check-in ------------------------------------------------------
$tok = expect($admin, 201, 'POST', "$A/enrollment-tokens", ['profile_id' => 1, 'label' => 'ci', 'max_uses' => 5]);
$device = new Client($base);
$en = expect($device, 201, 'POST', "$D/enroll", ['token' => $tok['code'], 'device' => [
    'android_id' => 'ci-android-1', 'model' => 'CI Tablet', 'manufacturer' => 'Test',
    'app_version' => '1.4.0', 'app_version_code' => 10400,
]]);
$device->bearer = $en['device_token'];
$list = expect($admin, 200, 'GET', "$A/devices?q=" . rawurlencode('CI Tablet'));
check(count($list['items']) === 1, 'enrolled device is listed', $list);
$id = (int) $list['items'][0]['id'];
check($list['items'][0]['tags'] === [], 'new device has no tags');

$ci = expect($device, 200, 'POST', "$D/checkin", ['app_version' => '1.4.0', 'policy_version' => $en['policy']['version'], 'status' => ['screen_on' => true, 'battery_level' => 55, 'wifi_rssi' => -60]]);
check($ci['checkin_seconds'] === 60, 'check-in interval is the policy default', $ci['checkin_seconds']);

// ---- Fast check-in while watched --------------------------------------------------
$w = expect($admin, 200, 'POST', "$A/devices/$id/watch");
$ci = expect($device, 200, 'POST', "$D/checkin", []);
check($ci['checkin_seconds'] === $w['fast_checkin_seconds'], 'watched device is told to check in faster', $ci['checkin_seconds']);

// ---- Commands ---------------------------------------------------------------------
expect($admin, 422, 'POST', "$A/devices/$id/commands", ['type' => 'wake_screen', 'params' => ['minutes' => 300]]);
expect($admin, 422, 'POST', "$A/devices/$id/commands", ['type' => 'announce']);
expect($admin, 201, 'POST', "$A/devices/$id/commands", ['type' => 'announce', 'params' => ['text' => 'Hello', 'volume' => 50]]);
expect($admin, 201, 'POST', "$A/devices/$id/commands", ['type' => 'reboot']);
expect(new Client($base), 403, 'GET', '/api/v1/provisioning/NOTACODE/kiosky.apk');
expect($admin, 201, 'POST', "$A/devices/$id/commands", ['type' => 'wake_screen']);
$cmd = expect($admin, 201, 'POST', "$A/devices/$id/commands", ['type' => 'reload']);
$ci = expect($device, 200, 'POST', "$D/checkin", []);
check(in_array($cmd['id'], array_column($ci['commands'], 'id'), true), 'command is delivered at check-in');
expect($device, 200, 'POST', "$D/commands/{$cmd['id']}/result", ['status' => 'done', 'message' => 'reloaded']);
$cl = expect($admin, 200, 'GET', "$A/commands?device_id=$id&type=reload");
check(($cl['items'][0]['status'] ?? null) === 'done', 'command shows as done', $cl);
$shots = expect($admin, 200, 'GET', "$A/commands?device_id=$id&type=screenshot&has_attachment=1");
check($shots['items'] === [], 'screenshot filter returns nothing yet');

// ---- Tags -------------------------------------------------------------------------
$d = expect($admin, 200, 'PATCH', "$A/devices/$id", ['tags' => ['ci', 'Lobby', 'lobby']]);
check($d['tags'] === ['ci', 'lobby'], 'tags are normalised and de-duplicated', $d['tags']);
expect($admin, 422, 'PATCH', "$A/devices/$id", ['tags' => ['not ok!']]);
$byTag = expect($admin, 200, 'GET', "$A/devices?tag=lobby");
check(count($byTag['items']) === 1, 'device list filters by tag');
$bt = expect($admin, 200, 'POST', "$A/device-tags", ['device_ids' => [$id], 'remove' => ['ci'], 'add' => ['floor-2']]);
check($bt['updated'] === 1, 'bulk tag change updates the device');
$tags = expect($admin, 200, 'GET', "$A/tags");
check(array_column($tags, 'tag') === ['floor-2', 'lobby'], 'tag list reflects bulk change', $tags);
$q = expect($admin, 201, 'POST', "$A/commands", ['type' => 'identify', 'tag' => 'lobby']);
check($q['queued'] === 1, 'bulk command can target a tag');
expect($admin, 422, 'POST', "$A/commands", ['type' => 'identify', 'tag' => 'nobody-has-this']);

// ---- Schedules --------------------------------------------------------------------
$sc = expect($admin, 201, 'POST', "$A/schedules", [
    'name' => 'ci nightly', 'type' => 'reload', 'target' => ['tag' => 'lobby'],
    'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'time' => '05:00', 'timezone' => 'Europe/London',
]);
check($sc['next_run_at'] !== null && $sc['target_label'] === 'tag lobby', 'schedule gets a next run', $sc);
expect($admin, 422, 'POST', "$A/schedules", ['name' => 'bad', 'type' => 'reload', 'target' => ['all' => true], 'days' => ['someday'], 'time' => '25:00']);
$run = expect($admin, 200, 'POST', "$A/schedules/{$sc['id']}/run");
check(str_starts_with($run['result'], 'Queued reload for 1 device'), 'run now queues the command', $run['result']);
$cl = expect($admin, 200, 'GET', "$A/commands?device_id=$id&type=reload");
check(($cl['items'][0]['schedule']['name'] ?? null) === 'ci nightly', 'scheduled command links to its schedule', $cl['items'][0] ?? null);
$paused = expect($admin, 200, 'PATCH', "$A/schedules/{$sc['id']}", ['enabled' => false]);
check($paused['next_run_at'] === null, 'pausing clears the next run');
expect($admin, 204, 'DELETE', "$A/schedules/{$sc['id']}");
// Left in place for the workflow's cron step, which makes it due and runs bin/cron.php
expect($admin, 201, 'POST', "$A/schedules", [
    'name' => 'ci due', 'type' => 'go_home', 'target' => ['all' => true], 'days' => ['mon', 'wed', 'fri'], 'time' => '23:59',
]);

// ---- Profile export and import ----------------------------------------------------
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['url' => 'https://example.com/', 'toolbar.pin' => '1234']]);
$ex = $admin->call('GET', "$A/profiles/1/export");
check($ex['status'] === 200 && str_contains($ex['headers']['content-disposition'] ?? '', 'attachment'), 'export downloads as a file', $ex['headers']);
check(($ex['body']['profiles'][0]['settings']['url'] ?? null) === 'https://example.com/', 'export includes settings', $ex['body']);
check(isset($ex['body']['profiles'][0]['settings']['toolbar.pin']['hash']), 'export carries the PIN only as a hash');
$doc = $ex['body'];
$doc['profiles'][0]['settings']['from_the_future'] = true;
$im = expect($admin, 201, 'POST', "$A/profiles/import", $doc);
check(($im['created'][0]['name'] ?? null) === 'Default (2)', 'import renames a clashing profile', $im);
check(($im['skipped_settings']['Default'] ?? null) === ['from_the_future'], 'import reports unknown settings', $im);
$np = expect($admin, 200, 'GET', "$A/profiles/{$im['created'][0]['id']}");
check($np['settings']['url'] === 'https://example.com/' && $np['settings']['toolbar.pin'] === ['set' => true], 'imported profile has the settings', $np);
expect($admin, 422, 'POST', "$A/profiles/import", ['format' => 'something-else']);
$all = expect($admin, 200, 'GET', "$A/profiles/export");
check(count($all['profiles']) === 2, 'export all includes every profile');

// ---- Profile history and restore --------------------------------------------------
$hist = expect($admin, 200, 'GET', "$A/profiles/1/versions");
check(($hist['items'][0]['action'] ?? null) === 'saved' && $hist['items'][0]['is_current'], 'a save is recorded as the current version', $hist['items'][0] ?? null);
check(in_array('url', array_column($hist['items'][0]['changes'], 'key'), true), 'the version lists what changed', $hist['items'][0]['changes']);
$baseline = end($hist['items']);
check($baseline['action'] === 'baseline', 'the migration recorded a baseline', $baseline);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['screen.off_at' => '25:00']]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['screen.dim_brightness' => 0]]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['watchdog_minutes' => 61]]);
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['screen.off_at' => '22:00', 'screen.on_at' => '07:00',
    'screen.dim_at' => '20:00', 'screen.dim_until' => '07:00', 'screen.wake_on_motion' => true, 'idle_home_minutes' => 10,
    'watchdog_minutes' => 3, 'media.camera' => false, 'battery.protect' => true]]);
$pol = expect($device, 200, 'POST', "$D/checkin", [])['policy'];
check(($pol['screen']['dim_at'] ?? null) === '20:00' && ($pol['media']['camera'] ?? null) === false
    && ($pol['battery']['protect'] ?? null) === true && ($pol['idle_home_minutes'] ?? null) === 10,
    'Kiosky 1.7.0 keys reach the device nested', $pol);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['trusted_certificates' => ['not-a-fingerprint']]]);
$fp = str_repeat('ab', 32);
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['trusted_certificates' => [$fp, strtoupper($fp)],
    'http_auth.username' => 'kiosk', 'http_auth.password' => ' s3cret ', 'reboot_at' => '04:00',
    'screensaver.minutes' => 10, 'audio.volume' => 40, 'playlist.urls' => ['https://example.com/cams']]]);
$prof = expect($admin, 200, 'GET', "$A/profiles");
$p1 = array_values(array_filter($prof, static fn ($p) => (int) $p['id'] === 1))[0] ?? [];
check(((array) ($p1['settings'] ?? []))['http_auth.password'] === ['set' => true], 'the HTTP sign-in password is hidden from the console', $p1);
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['http_auth.password' => ['set' => true]]]); // round-trip keeps it
$pol = expect($device, 200, 'POST', "$D/checkin", [])['policy'];
check(($pol['http_auth']['password'] ?? null) === ' s3cret ' && count($pol['trusted_certificates'] ?? []) === 1
    && ($pol['trusted_certificates'][0] ?? '') === implode(':', str_split(strtoupper($fp), 2)),
    'the device gets the password and one normalised fingerprint', $pol);
// Kiosky 1.8.0: shared tablets, MQTT, Wi-Fi and GitHub rollout spread
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['mqtt.host' => 'mqtt://ha.local']]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['mqtt.topic' => 'kiosky/#']]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['mqtt.fingerprint' => 'ab:cd']]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['wifi.networks' => "Office | short"]]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['wifi.networks' => "Office | password1\nOffice | password2"]]);
expect($admin, 422, 'PATCH', "$A/profiles/1", ['settings' => ['wifi.networks' => "Office | (saved) | wpa2"]]); // nothing saved to keep
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['session.clear_on_idle' => true, 'mqtt.host' => '192.168.1.10',
    'mqtt.password' => 'mq pass', 'mqtt.topic' => '/wall/hall/', 'mqtt.fingerprint' => $fp, 'updates.rollout_hours' => 48,
    'wifi.networks' => "Office | office-pass | wpa3 | hidden\nGuest", 'touch.long_press' => false, 'input.nfc' => true,
    'screen.pixel_shift' => true, 'offline.last_page' => false]]);
$prof = expect($admin, 200, 'GET', "$A/profiles");
$s1 = (array) (array_values(array_filter($prof, static fn ($p) => (int) $p['id'] === 1))[0]['settings'] ?? []);
check(($s1['wifi.networks'][0]['password'] ?? null) === ['set' => true] && ($s1['wifi.networks'][1]['security'] ?? null) === 'open'
    && ($s1['mqtt.password'] ?? null) === ['set' => true], 'Wi-Fi and MQTT passwords are hidden from the console', $s1);
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['wifi.networks' => "Office | (saved) | wpa3 | hidden\nGuest | | open\nNew | new-pass-1"]]);
$pol = expect($device, 200, 'POST', "$D/checkin", [])['policy'];
$office = (array) ($pol['wifi']['networks'][0] ?? []);
ksort($office); // MySQL 8 stores JSON objects with their keys reordered
check($office === ['hidden' => true, 'password' => 'office-pass', 'security' => 'wpa3', 'ssid' => 'Office']
    && count($pol['wifi']['networks']) === 3 && ($pol['session']['clear_on_idle'] ?? null) === true
    && ($pol['mqtt']['topic'] ?? null) === 'wall/hall' && ($pol['mqtt']['password'] ?? null) === 'mq pass'
    && ($pol['mqtt']['port'] ?? null) === 1883 && ($pol['updates']['rollout_hours'] ?? null) === 48
    && ($pol['touch']['long_press'] ?? null) === false && ($pol['input']['nfc'] ?? null) === true
    && ($pol['screen']['pixel_shift'] ?? null) === true && ($pol['offline']['last_page'] ?? null) === false,
    'Kiosky 1.8.0 keys reach the device, with the kept Wi-Fi password', $pol);
expect($admin, 422, 'PATCH', "$A/devices/$id", ['overrides' => ['wifi.networks' => "Office | (saved)"]]); // overrides keep their own
$ci = expect($device, 200, 'POST', "$D/checkin", ['status' => ['safe_mode' => true, 'update_health' => 'unhealthy', 'wifi_config' => 'reverted']]);
$dv = expect($admin, 200, 'GET', "$A/devices/$id");
check(($dv['state']['wifi_config'] ?? null) === 'reverted', 'Kiosky 1.8.0 status keys are stored', $dv['state']);
expect($device, 200, 'POST', "$D/checkin", ['status' => ['screen_on' => true]]);

$restored = expect($admin, 200, 'POST', "$A/profiles/1/versions/{$baseline['id']}/restore");
check((array) $restored['settings'] === [], 'restore puts the profile back as it was', $restored['settings']);
$hist = expect($admin, 200, 'GET', "$A/profiles/1/versions");
check($hist['items'][0]['action'] === 'restored', 'the restore is itself a version');

// ---- Scheduled profile switch -----------------------------------------------------
$night = $im['created'][0]['id'];
$sw = expect($admin, 201, 'POST', "$A/schedules", [
    'name' => 'ci night', 'type' => 'set_profile', 'params' => ['profile_id' => $night], 'target' => ['tag' => 'lobby'],
    'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'time' => '22:00',
]);
expect($admin, 422, 'POST', "$A/schedules", ['name' => 'bad', 'type' => 'set_profile', 'params' => ['profile_id' => 999999],
    'target' => ['all' => true], 'days' => ['mon'], 'time' => '22:00']);
$run = expect($admin, 200, 'POST', "$A/schedules/{$sw['id']}/run");
check(str_starts_with($run['result'], 'Moved 1 device'), 'a profile switch moves the tagged device', $run['result']);
$dv = expect($admin, 200, 'GET', "$A/devices/$id");
check($dv['profile_id'] === $night, 'the device is on the new profile', $dv['profile_id']);
$ev = expect($admin, 200, 'GET', "$A/devices/$id/events?limit=5");
check(in_array('profile_changed', array_column($ev['items'], 'type'), true), 'the move is in the device\'s event log');
expect($admin, 200, 'PATCH', "$A/devices/$id", ['profile_id' => 1]);
expect($admin, 204, 'DELETE', "$A/schedules/{$sw['id']}");

// ---- Release rings ----------------------------------------------------------------
expect($admin, 422, 'PUT', "$A/releases/rings/canary", ['release' => '9.9.9']);
expect($admin, 200, 'PUT', "$A/releases/rings/canary", ['release' => 'latest']);
expect($admin, 200, 'POST', "$A/device-tags", ['device_ids' => [$id], 'add' => ['canary']]);
$pol = expect($admin, 200, 'GET', "$A/devices/$id/policy");
check(($pol['sources']['updates.release'] ?? null) === 'tag:canary', 'a ring sets the release for its tag', $pol['sources']);
$rings = expect($admin, 200, 'GET', "$A/releases/rings");
check(($rings[0]['tag'] ?? null) === 'canary' && $rings[0]['devices'] === 1, 'rings list their devices', $rings);
expect($admin, 204, 'DELETE', "$A/releases/rings/canary");
$pol = expect($admin, 200, 'GET', "$A/devices/$id/policy");
check($pol['sources']['updates.release'] === 'default', 'removing the ring returns the device to its profile');

// ---- Release channels (needs the two releases the CI workflow seeds) --------------
$rels = expect($admin, 200, 'GET', "$A/releases");
$byName = array_column($rels, null, 'version_name');
if (isset($byName['1.0.0'], $byName['1.1.0-dev.1'])) {
    check($byName['1.1.0-dev.1']['channel'] === 'dev', 'releases report their channel');
    $pol = expect($admin, 200, 'GET', "$A/devices/$id/policy");
    check(($pol['update']['version'] ?? null) === '1.0.0', 'the stable channel gets the newest stable release', $pol['update']);
    expect($admin, 422, 'PATCH', "$A/devices/$id", ['overrides' => ['updates.channel' => 'beta']]);
    expect($admin, 200, 'PATCH', "$A/devices/$id", ['overrides' => ['updates.channel' => 'dev']]);
    $ci = expect($device, 200, 'POST', "$D/checkin", []);
    check(($ci['update']['version'] ?? null) === '1.1.0-dev.1' && $ci['update']['channel'] === 'dev', 'the dev channel gets the dev build', $ci['update']);
    check(($ci['policy']['updates']['channel'] ?? null) === 'dev', 'the device is told its channel');
    expect($admin, 200, 'PATCH', "$A/devices/$id", ['overrides' => ['updates.channel' => null]]);
    expect($admin, 422, 'PATCH', "$A/releases/{$byName['1.1.0-dev.1']['id']}", ['channel' => 'beta']);
    expect($admin, 200, 'PATCH', "$A/releases/{$byName['1.1.0-dev.1']['id']}", ['channel' => 'stable']);
    $pol = expect($admin, 200, 'GET', "$A/devices/$id/policy");
    check(($pol['update']['version'] ?? null) === '1.1.0-dev.1', 'a promoted build reaches the stable channel', $pol['update']);
    expect($admin, 200, 'PATCH', "$A/releases/{$byName['1.1.0-dev.1']['id']}", ['channel' => 'dev']);

    // Staged rollout: just started over 720 h, so this device's turn hasn't come
    $stable = $byName['1.0.0']['id'];
    expect($admin, 422, 'PATCH', "$A/releases/$stable", ['rollout_hours' => 9999]);
    $r = expect($admin, 200, 'PATCH', "$A/releases/$stable", ['rollout_hours' => 720, 'restart_rollout' => true]);
    check($r['rollout_hours'] === 720 && $r['rollout_percent'] === 0, 'a rollout starts at 0%', $r);
    $ci = expect($device, 200, 'POST', "$D/checkin", []);
    check($ci['update'] === null, 'a device whose turn hasn\'t come gets no update', $ci['update']);
    expect($admin, 200, 'PATCH', "$A/releases/$stable", ['rollout_hours' => 0]);
    $ci = expect($device, 200, 'POST', "$D/checkin", []);
    check(($ci['update']['version'] ?? null) === '1.0.0', 'without a spread every device gets it', $ci['update']);
    // A device reporting the release crashing pauses it
    expect($device, 200, 'POST', "$D/checkin", ['events' => [['type' => 'update_unhealthy', 'at' => gmdate('c'),
        'data' => ['version' => '1.0.0', 'version_code' => 100, 'previous_version' => '0.9.0', 'previous_version_code' => 90,
            'crashes' => 2, 'last_crash' => 'java.lang.IllegalStateException: ci']]]]);
    $rels = array_column(expect($admin, 200, 'GET', "$A/releases"), null, 'version_name');
    check($rels['1.0.0']['halted_at'] !== null && $rels['1.0.0']['unhealthy_count'] === 1
        && str_contains((string) $rels['1.0.0']['halt_reason'], 'IllegalStateException'), 'an unhealthy report pauses the rollout', $rels['1.0.0']);
    $ci = expect($device, 200, 'POST', "$D/checkin", []);
    check($ci['update'] === null, 'a paused release isn\'t offered', $ci['update']);
    $r = expect($admin, 200, 'PATCH', "$A/releases/$stable", ['halted' => false]);
    check($r['halted_at'] === null && $r['halt_reason'] === null, 'resuming clears the pause', $r);
    expect($device, 200, 'POST', "$D/checkin", ['events' => [['type' => 'update_healthy', 'at' => gmdate('c'),
        'data' => ['version' => '1.0.0', 'version_code' => 100, 'crashes' => 0]]]]);
    $rels = array_column(expect($admin, 200, 'GET', "$A/releases"), null, 'version_name');
    check($rels['1.0.0']['healthy_count'] === 1 && $rels['1.0.0']['halted_at'] === null, 'healthy reports are counted', $rels['1.0.0']);
} else {
    echo "skip - release channel checks (no seeded releases)\n";
}

// ---- Health history ---------------------------------------------------------------
$hh = expect($admin, 200, 'GET', "$A/devices/$id/history?days=7");
$today = end($hh['days']);
check(count($hh['days']) === 7 && $today['checkins'] >= 3, 'check-ins are counted per day', $today);
check($today['battery_min'] === 55 && $today['wifi_avg'] === -60, 'battery and Wi-Fi are summarised', $today);
$dl = expect($admin, 200, 'GET', "$A/devices?q=" . rawurlencode('CI Tablet'));
check(array_key_exists('uptime_7d', $dl['items'][0]), 'the device list includes 7-day uptime');

// ---- Live view --------------------------------------------------------------------
expect($admin, 201, 'POST', "$A/devices/$id/commands", ['type' => 'live_view', 'params' => ['seconds' => 0]]);
expect($admin, 404, 'GET', "$A/devices/$id/live");
$jpeg = base64_encode("\xFF\xD8\xFF\xE0" . str_repeat("\0", 64) . "\xFF\xD9");
expect($device, 200, 'POST', "$D/live", ['screenshot' => $jpeg]);
expect($device, 422, 'POST', "$D/live", ['screenshot' => base64_encode('<svg/>')]);
$frame = $admin->call('GET', "$A/devices/$id/live");
check($frame['status'] === 200 && ($frame['headers']['content-type'] ?? '') === 'image/jpeg', 'the latest live frame is served', $frame['headers']);

// ---- Scoped operators -------------------------------------------------------------
$other = new Client($base);
$en2d = expect($other, 201, 'POST', "$D/enroll", ['token' => $tok['code'], 'device' => ['android_id' => 'ci-android-2', 'model' => 'CI Other']]);
$other->bearer = $en2d['device_token'];
$otherId = (int) expect($admin, 200, 'GET', "$A/devices?q=" . rawurlencode('CI Other'))['items'][0]['id'];
expect($admin, 422, 'POST', "$A/users", ['username' => 'ci-bad', 'password' => 'ci-password-456', 'role' => 'admin', 'scope_tags' => ['lobby']]);
$op = expect($admin, 201, 'POST', "$A/users", ['username' => 'ci-lobby', 'password' => 'ci-password-456', 'role' => 'operator', 'scope_tags' => ['lobby']]);
check($op['scope_tags'] === ['lobby'], 'a user can be limited to a tag', $op);
$scoped = new Client($base);
$ss = expect($scoped, 200, 'POST', "$A/session", ['username' => 'ci-lobby', 'password' => 'ci-password-456']);
$scoped->csrf = $ss['csrf_token'];
check($ss['user']['scope_tags'] === ['lobby'], 'the session reports the scope');
$sl = expect($scoped, 200, 'GET', "$A/devices");
check(array_column($sl['items'], 'id') === [$id], 'a scoped user sees only tagged devices', array_column($sl['items'], 'name'));
expect($scoped, 404, 'GET', "$A/devices/$otherId");
expect($scoped, 404, 'GET', "$A/devices/$otherId/live");
expect($scoped, 403, 'GET', "$A/enrollment-tokens");
expect($scoped, 403, 'POST', "$A/schedules", ['name' => 'x', 'type' => 'reload', 'target' => ['all' => true], 'days' => ['mon'], 'time' => '01:00']);
expect($scoped, 422, 'PATCH', "$A/devices/$id", ['tags' => ['floor-2']]);
$sq = expect($scoped, 201, 'POST', "$A/commands", ['type' => 'identify', 'all' => true]);
check($sq['queued'] === 1, 'a scoped user\'s "all devices" means their devices');
$so = expect($scoped, 200, 'GET', "$A/overview");
check($so['devices']['total'] === 1, 'the overview counts only their devices', $so['devices']);
$ad = expect($admin, 200, 'GET', "$A/devices");
check(count($ad['items']) === 2, 'admins still see every device');

// ---- Alerts -----------------------------------------------------------------------
$ov = expect($admin, 200, 'GET', "$A/overview");
check(($ov['alerts']['email'] ?? null) === 0 && ($ov['alerts']['webhooks'] ?? null) === [], 'overview shows alerts as off', $ov['alerts'] ?? null);
expect($admin, 409, 'POST', "$A/alerts/test");

// ---- Reverse proxies (CI trusts 'private' and 'cloudflare'; requests come from 127.0.0.1)
$net = function (array $headers) use ($admin, $A): array {
    $r = $admin->call('GET', "$A/system/network", null, $headers);
    check($r['status'] === 200, 'network diagnostics answer', $r['body']);
    return $r['body'];
};
$n = $net(['X-Forwarded-For: 203.0.113.9, 10.0.0.2', 'X-Forwarded-Proto: https']);
check($n['client_ip'] === '203.0.113.9' && $n['https'] === true && $n['remote_trusted'], 'X-Forwarded-For through two trusted hops', $n);
$n = $net(['X-Forwarded-For: 203.0.113.9, 104.16.0.1']);
check($n['client_ip'] === '203.0.113.9', 'a Cloudflare hop in the chain is skipped', $n);
$n = $net(['CF-Connecting-IP: 198.51.100.7']);
check($n['client_ip'] === '127.0.0.1', 'CF-Connecting-IP is ignored unless Cloudflare itself connects', $n);
$n = $net(['Forwarded: for="[2001:db8::5]:443";proto=https']);
check($n['client_ip'] === '2001:db8::5' && $n['https'] === true, 'the standard Forwarded header works', $n);
$n = $net(['X-Forwarded-For: not-an-ip']);
check($n['client_ip'] === '127.0.0.1', 'a malformed chain falls back to the connecting address', $n);
$n = $net(['CF-Visitor: {"scheme":"https"}', 'CF-Ray: abc']);
check($n['https'] === true && $n['via'] === 'cloudflare', 'Cloudflare\'s CF-Visitor marks HTTPS', $n);

// ---- Email over SMTP (the workflow runs a test SMTP server on 127.0.0.1:2525) -----
$ml = expect($admin, 200, 'GET', "$A/system/mail");
check(!array_key_exists('password', $ml), 'mail settings are shown without secrets', $ml);
expect($admin, 422, 'POST', "$A/system/mail/test", ['to' => 'not-an-address']);
if (getenv('CI') !== false) {
    check($ml['transport'] === 'smtp' && $ml['host'] === '127.0.0.1' && $ml['port'] === 2525, 'the SMTP server from config.php is used', $ml);
    expect($admin, 200, 'POST', "$A/system/mail/test", ['to' => 'ci@example.com']);
}

// ---- Server settings (storage/settings.json over config.php) ----------------------
$st = expect($admin, 200, 'GET', "$A/system/settings");
$byKey = array_column($st['settings'], null, 'key');
check(($byKey['retention_days']['value'] ?? null) === 90 && !$byKey['retention_days']['overridden'], 'settings show config.php values', $byKey['retention_days'] ?? null);
check(!isset($byKey['db.password']) && !isset($byKey['self_update.repo']) && !isset($byKey['base_url']), 'secrets and the update source are not editable');
expect($admin, 422, 'PATCH', "$A/system/settings", ['db.password' => 'x']);
expect($admin, 422, 'PATCH', "$A/system/settings", ['retention_days' => 1]);
expect($admin, 422, 'PATCH', "$A/system/settings", ['base_url' => 'https://elsewhere.example']);
expect($admin, 422, 'PATCH', "$A/system/settings", ['fast_checkin_seconds' => 10]);
$sv = expect($admin, 200, 'PATCH', "$A/system/settings", ['retention_days' => 30,
    'alerts.webhooks' => [['type' => 'json', 'url' => 'https://example.invalid/hook']]]);
check(count($sv['changed']) === 2, 'settings are saved', $sv['changed']);
$ov = expect($admin, 200, 'GET', "$A/overview");
check($ov['alerts']['webhooks'] === ['json'], 'a saved setting takes effect straight away', $ov['alerts']);
expect($admin, 422, 'PATCH', "$A/system/settings", ['self_update.channel' => 'beta']);
expect($admin, 200, 'PATCH', "$A/system/settings", ['self_update.channel' => 'dev']);
check(expect($admin, 200, 'GET', "$A/system/update")['channel'] === 'dev', 'the update channel can be switched to dev');
expect($admin, 200, 'PATCH', "$A/system/settings", ['retention_days' => null, 'alerts.webhooks' => null, 'self_update.channel' => null]);
$st = expect($admin, 200, 'GET', "$A/system/settings");
$byKey = array_column($st['settings'], null, 'key');
check($byKey['retention_days']['value'] === 90 && !$byKey['retention_days']['overridden'], 'reset goes back to config.php');

// ---- API tokens -------------------------------------------------------------------
$t = expect($admin, 201, 'POST', "$A/tokens", ['name' => 'ci', 'role' => 'viewer', 'expires_days' => 1]);
$api = new Client($base);
$api->bearer = $t['token'];
expect($api, 200, 'GET', "$A/overview");
expect($api, 403, 'POST', "$A/profiles", ['name' => 'nope']);

// ---- Self-update (no GitHub calls: the CI checkout is a git clone, which it refuses to overwrite)
expect($api, 403, 'GET', "$A/system/update");
expect($api, 403, 'GET', "$A/system/settings");
expect($api, 403, 'GET', "$A/system/network");
expect($api, 403, 'POST', "$A/system/mail/test", ['to' => 'ci@example.com']);
$su = expect($admin, 200, 'GET', "$A/system/update");
check($su['current'] === $h['version'] && is_array($su['problems']) && is_array($su['backups']), 'update status reports the installed version', $su);
check($su['channel'] === 'stable' && $su['branches'] === ['stable' => 'main', 'dev' => 'dev'], 'channels follow main and dev', $su);
expect($admin, 422, 'POST', "$A/system/update/install", ['sha' => 'not-a-commit']);
$inst = $admin->call('POST', "$A/system/update/install", ['sha' => str_repeat('a', 40)]);
check($inst['status'] === 409, 'installing refuses when it can\'t safely update', $inst['body']);
expect($admin, 404, 'POST', "$A/system/update/rollback", ['backup' => '../../config/config.php']);

// ---- Two-factor sign-in -----------------------------------------------------------
$setup = expect($admin, 200, 'POST', "$A/session/2fa/setup");
check(str_starts_with($setup['uri'], 'otpauth://totp/'), 'setup returns an otpauth URI', $setup);
expect($admin, 422, 'POST', "$A/session/2fa/enable", ['code' => '000000']);
$code = Totp::code($setup['secret'], Totp::currentStep());
$en2 = expect($admin, 200, 'POST', "$A/session/2fa/enable", ['code' => $code]);
check(count($en2['recovery_codes']) === 10, 'enabling returns 10 recovery codes');
$me = expect($admin, 200, 'GET', "$A/session");
check($me['user']['two_factor'] === true && $me['user']['recovery_codes_left'] === 10, 'session shows two-factor on', $me['user']);
expect($admin, 204, 'DELETE', "$A/session");

$second = new Client($base);
$r = expect($second, 200, 'POST', "$A/session", ['username' => $adminUser, 'password' => $adminPass]);
check(($r['two_factor_required'] ?? false) === true && !isset($r['csrf_token']), 'password alone no longer signs in', $r);
expect($second, 401, 'GET', "$A/session");
$replay = $second->call('POST', "$A/session/2fa", ['code' => $code]);
check($replay['status'] === 401 && ($replay['body']['error']['code'] ?? null) === 'two_factor_failed', 'a used TOTP code is refused', $replay['body']);
$s2 = expect($second, 200, 'POST', "$A/session/2fa", ['code' => strtolower($en2['recovery_codes'][0])]);
$second->csrf = $s2['csrf_token'];
check($s2['user']['recovery_codes_left'] === 9, 'a recovery code signs in once', $s2['user']);

$third = new Client($base);
expect($third, 200, 'POST', "$A/session", ['username' => $adminUser, 'password' => $adminPass]);
expect($third, 401, 'POST', "$A/session/2fa", ['code' => $en2['recovery_codes'][0]]);

expect($second, 422, 'POST', "$A/session/2fa/disable", ['password' => 'wrong-password']);
expect($second, 200, 'POST', "$A/session/2fa/disable", ['password' => $adminPass]);
$me = expect($second, 200, 'GET', "$A/session");
check($me['user']['two_factor'] === false, 'two-factor can be turned off');

// ---- Device problems in the overview -----------------------------------------------
expect($device, 200, 'POST', "$D/checkin", ['status' => ['battery_level' => 5, 'charging' => false, 'screen_on' => true]]);
$ov = expect($second, 200, 'GET', "$A/overview");
$why = implode(' | ', array_merge(...array_map(static fn ($a) => $a['reasons'], $ov['attention'])));
check(str_contains($why, 'battery at 5%'), 'low battery shows on the overview', $ov['attention']);
expect($device, 200, 'POST', "$D/checkin", ['status' => ['battery_level' => 100, 'charging' => true, 'battery_temperature' => 51.5, 'battery_health' => 'overheat']]);
$ov = expect($second, 200, 'GET', "$A/overview");
$why = implode(' | ', array_merge(...array_map(static fn ($a) => $a['reasons'], $ov['attention'])));
check(str_contains($why, 'battery at 52 °C') && str_contains($why, 'battery reports overheat'), 'a hot, failing battery shows on the overview', $ov['attention']);

// ---- Enrollment codes with tags and notes, and the device.enrolled event ---------------
expect($second, 200, 'PATCH', "$A/system/settings", ['events.webhooks' => [['url' => 'http://127.0.0.1:8099/hook', 'events' => [], 'secret' => 'ci-secret']]]);
$tok3 = expect($second, 201, 'POST', "$A/enrollment-tokens", ['profile_id' => 1, 'label' => 'tagged', 'tags' => ['Fresh', 'lobby'], 'notes' => 'Bracket B']);
check($tok3['tags'] === ['fresh', 'lobby'] && $tok3['notes'] === 'Bracket B', 'enrollment codes keep tags and notes', $tok3);
$d3 = new Client($base);
expect($d3, 201, 'POST', "$D/enroll", ['token' => $tok3['code'], 'device' => ['android_id' => 'ci-android-3', 'model' => 'CI Third']]);
$third3 = expect($second, 200, 'GET', "$A/devices?q=" . rawurlencode('CI Third'))['items'][0];
check($third3['tags'] === ['fresh', 'lobby'], 'a device enrolled with the code gets its tags', $third3['tags']);
check(expect($second, 200, 'GET', "$A/devices/{$third3['id']}")['notes'] === 'Bracket B', 'and its notes');

// ---- Event webhooks (the workflow runs a receiver on 127.0.0.1:8099) -----------------
if (getenv('CI') !== false) {
    $ev = expect($second, 200, 'POST', "$A/events/test");
    check($ev['delivered'] >= 1, 'a test event is delivered', $ev);
    $recent = expect($second, 200, 'GET', "$A/events")['recent'];
    check(in_array('device.enrolled', array_column($recent, 'event'), true), 'device.enrolled was queued', $recent);
}
expect($second, 200, 'PATCH', "$A/system/settings", ['events.webhooks' => null]);

// ---- CSV exports and audit search ------------------------------------------------------
$csv = $second->call('GET', "$A/devices/export");
check($csv['status'] === 200 && str_contains($csv['headers']['content-type'] ?? '', 'text/csv') && str_contains($csv['raw'], 'CI Third'), 'devices export as CSV', $csv['headers']);
$aud = expect($second, 200, 'GET', "$A/audit?action=device.&limit=50");
check($aud['items'] !== [] && array_filter($aud['items'], static fn ($a) => !str_starts_with($a['action'], 'device.')) === [], 'audit log filters by action', array_column($aud['items'], 'action'));
$acsv = $second->call('GET', "$A/audit/export?actor=ci");
check($acsv['status'] === 200 && str_contains($acsv['raw'], 'When (UTC)'), 'the audit log exports as CSV');

// ---- Alert silences --------------------------------------------------------------------
$si = expect($second, 201, 'POST', "$A/alerts/silences", ['device_id' => $third3['id'], 'minutes' => 60, 'note' => 'ci']);
$sl = expect($second, 200, 'GET', "$A/alerts/silences")['items'];
check(in_array($si['id'], array_column($sl, 'id'), true), 'a device can be silenced', $sl);
expect($scoped, 403, 'POST', "$A/alerts/silences", ['all' => true, 'minutes' => 60]);
expect($second, 204, 'DELETE', "$A/alerts/silences/{$si['id']}");

// ---- Diagnostics bundle ----------------------------------------------------------------
$dg = expect($second, 201, 'POST', "$A/devices/$id/commands", ['type' => 'diagnostics']);
$ci = expect($device, 200, 'POST', "$D/checkin", []);
check(in_array($dg['id'], array_column($ci['commands'], 'id'), true), 'the diagnostics command is delivered');
expect($device, 200, 'POST', "$D/commands/{$dg['id']}/result", ['status' => 'done', 'bundle' => base64_encode(gzencode("logcat line 1\nline 2\n"))]);
$att = $second->call('GET', "$A/commands/{$dg['id']}/attachment");
check($att['status'] === 200 && str_starts_with($att['raw'], "\x1f\x8b"), 'the bundle downloads', $att['headers']);

// ---- Page playlist policy ----------------------------------------------------------------
$pl = expect($second, 200, 'PATCH', "$A/profiles/1", ['settings' => ['playlist.urls' => "https://a.example/\nb.example"]]);
check(($pl['settings']['playlist.urls'] ?? null) === ['https://a.example/', 'https://b.example'], 'playlist pages are normalised', $pl['settings']);
expect($second, 422, 'PATCH', "$A/profiles/1", ['settings' => ['playlist.urls' => ['ftp://nope']]]);
expect($second, 200, 'PATCH', "$A/profiles/1", ['settings' => ['playlist.urls' => null]]);

// ---- Database backup -------------------------------------------------------------------
$bk = expect($second, 201, 'POST', "$A/system/backups");
$dl = $second->call('GET', "$A/system/backups/" . rawurlencode($bk['name']));
check($dl['status'] === 200 && str_starts_with($dl['raw'], "\x1f\x8b") && str_contains((string) gzdecode($dl['raw']), 'CREATE TABLE `devices`'), 'a database backup downloads as .sql.gz');
expect($second, 404, 'GET', "$A/system/backups/" . rawurlencode('../config/config.php'));
expect($second, 204, 'DELETE', "$A/system/backups/" . rawurlencode($bk['name']));

// ---- Weekly report ---------------------------------------------------------------------
$rp = expect($second, 200, 'GET', "$A/system/report");
check(str_starts_with($rp['subject'], 'Weekly report') && str_contains($rp['text'], 'active kiosks'), 'the weekly report builds', $rp);
if (getenv('CI') !== false) {
    expect($second, 200, 'PATCH', "$A/system/settings", ['report.recipients' => ['ci@example.com']]);
    expect($second, 200, 'POST', "$A/system/report");
    expect($second, 200, 'PATCH', "$A/system/settings", ['report.recipients' => null]);
}

// ---- Prometheus metrics (API token) ------------------------------------------------------
$m = $api->call('GET', '/metrics');
check($m['status'] === 200 && str_contains($m['raw'], 'kiosky_devices ') && str_contains($m['raw'], 'kiosky_device_up{'), 'metrics are served to an API token', $m['raw']);
check($anon->call('GET', '/metrics')['status'] === 401, 'metrics need a token');

// ---- Push subscriptions ------------------------------------------------------------------
$pi = expect($second, 200, 'GET', "$A/push");
check(strlen($pi['public_key']) === 87, 'the server has a VAPID key', $pi);
$keys = ['p256dh' => rtrim(strtr(base64_encode("\x04" . random_bytes(64)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')];
expect($second, 422, 'POST', "$A/push", ['endpoint' => 'https://evil.example/push', 'keys' => $keys]);
expect($second, 201, 'POST', "$A/push", ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ci-test', 'keys' => $keys]);
expect($second, 204, 'DELETE', "$A/push", ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ci-test']);

// ---- Sessions ------------------------------------------------------------------------------
$elsewhere = new Client($base);
expect($elsewhere, 200, 'POST', "$A/session", ['username' => $adminUser, 'password' => $adminPass]);
$ss = expect($second, 200, 'GET', "$A/session/sessions")['items'];
check(count($ss) >= 2 && count(array_filter($ss, static fn ($x) => $x['current'])) === 1, 'sessions are listed, with this one marked', $ss);
$end = expect($second, 200, 'POST', "$A/session/sessions/end-others");
check($end['ended'] >= 1, 'other sessions can be ended', $end);
expect($elsewhere, 401, 'GET', "$A/session");
expect($second, 200, 'GET', "$A/session");

// ---- Passkeys, with a software authenticator -------------------------------------------
/** Minimal CBOR encoder for the authenticator's side */
function cbor(mixed $v): string
{
    $head = static function (int $major, int $n): string {
        if ($n < 24) { return chr(($major << 5) | $n); }
        if ($n < 256) { return chr(($major << 5) | 24) . chr($n); }
        return chr(($major << 5) | 25) . pack('n', $n);
    };
    if (is_int($v)) { return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v); }
    if (is_array($v) && isset($v['__bytes'])) { return $head(2, strlen($v['__bytes'])) . $v['__bytes']; }
    if (is_string($v)) { return $head(3, strlen($v)) . $v; }
    $out = $head(5, count($v));
    foreach ($v as $k => $x) { $out .= cbor($k) . cbor($x); }
    return $out;
}
$b64u = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$origin = $base;
$rpHash = hash('sha256', (string) parse_url($base, PHP_URL_HOST), true);
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$ec = openssl_pkey_get_details($key)['ec'];
$credId = random_bytes(16);
$ro = expect($second, 200, 'POST', "$A/session/passkeys/options");
check($ro['rp']['id'] === parse_url($base, PHP_URL_HOST), 'passkey options name this site', $ro['rp']);
$cose = cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)], -3 => ['__bytes' => str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)]]);
$authData = $rpHash . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $cose;
$clientCreate = json_encode(['type' => 'webauthn.create', 'challenge' => $ro['challenge'], 'origin' => $origin]);
expect($second, 201, 'POST', "$A/session/passkeys", ['name' => 'CI key', 'credential' => [
    'id' => $b64u($credId), 'clientDataJSON' => $b64u($clientCreate),
    'attestationObject' => $b64u(cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $authData]])),
]]);
$signIn = static function (Client $c, string $mode, int $counter, bool $uv = true) use ($A, $b64u, $rpHash, $key, $credId, $origin): array {
    $o = $c->call('POST', "$A/session/passkey/options", ['mode' => $mode])['body'];
    $client = json_encode(['type' => 'webauthn.get', 'challenge' => $o['challenge'], 'origin' => $origin]);
    $ad = $rpHash . chr($uv ? 0x05 : 0x01) . pack('N', $counter);
    openssl_sign($ad . hash('sha256', $client, true), $sig, $key, OPENSSL_ALGO_SHA256);
    return $c->call('POST', "$A/session/passkey", ['mode' => $mode, 'credential' => [
        'id' => $b64u($credId), 'clientDataJSON' => $b64u($client), 'authenticatorData' => $b64u($ad), 'signature' => $b64u($sig)]]);
};
$pk = new Client($base);
$r = $signIn($pk, 'login', 1);
check($r['status'] === 200 && $r['body']['user']['username'] === $adminUser, 'a passkey alone signs in', $r['body']);
$r = $signIn(new Client($base), 'login', 1);
check($r['status'] === 401, 'a passkey counter that doesn\'t go up is refused', $r['body']);
$r = $signIn(new Client($base), 'login', 5, false);
check($r['status'] === 401, 'a passkey alone needs user verification', $r['body']);
$pw = new Client($base);
$lr = expect($pw, 200, 'POST', "$A/session", ['username' => $adminUser, 'password' => $adminPass]);
check(($lr['two_factor_required'] ?? false) && $lr['methods'] === ['passkey'], 'with a passkey, a password needs a second step', $lr);
$r = $signIn($pw, 'second', 6, false);
check($r['status'] === 200, 'the passkey completes a password sign-in', $r['body']);
// ---- Require two-factor sign-in (the admin has a passkey now, so isn't blocked) -----------
expect($second, 201, 'POST', "$A/users", ['username' => 'ci-req', 'password' => 'ci-password-789', 'role' => 'viewer']);
expect($second, 200, 'PATCH', "$A/system/settings", ['require_2fa' => true]);
$req2 = new Client($base);
$rs = expect($req2, 200, 'POST', "$A/session", ['username' => 'ci-req', 'password' => 'ci-password-789']);
check($rs['user']['must_setup_2fa'] === true, 'a user without a second factor must set one up', $rs['user']);
$blocked = $req2->call('GET', "$A/devices");
check($blocked['status'] === 403 && ($blocked['body']['error']['code'] ?? null) === 'two_factor_required', 'everything else is blocked until then', $blocked['body']);
expect($req2, 200, 'GET', "$A/session");
expect($second, 200, 'PATCH', "$A/system/settings", ['require_2fa' => null]);
expect($req2, 200, 'GET', "$A/devices");

$mine = expect($second, 200, 'GET', "$A/session/passkeys")['items'];
expect($second, 204, 'DELETE', "$A/session/passkeys/{$mine[0]['id']}");

// ---- Device rate limit and retirement ---------------------------------------------
$limited = false;
for ($i = 0; $i < 130 && !$limited; $i++) {
    $limited = $device->call('POST', "$D/checkin", [])['status'] === 429;
}
check($limited, 'device check-ins are rate limited');
expect($second, 200, 'DELETE', "$A/devices/$id");
expect($device, 410, 'POST', "$D/checkin", []);

echo "All $step checks passed.\n";
