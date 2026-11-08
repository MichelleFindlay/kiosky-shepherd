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
expect($admin, 200, 'PATCH', "$A/profiles/1", ['settings' => ['screen.off_at' => '22:00', 'screen.on_at' => '07:00']]);
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
expect($admin, 200, 'PATCH', "$A/system/settings", ['retention_days' => null, 'alerts.webhooks' => null]);
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
$su = expect($admin, 200, 'GET', "$A/system/update");
check($su['current'] === $h['version'] && is_array($su['problems']) && is_array($su['backups']), 'update status reports the installed version', $su);
$inst = $admin->call('POST', "$A/system/update/install", ['version' => '99.0.0']);
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

// ---- Device rate limit and retirement ---------------------------------------------
$limited = false;
for ($i = 0; $i < 130 && !$limited; $i++) {
    $limited = $device->call('POST', "$D/checkin", [])['status'] === 429;
}
check($limited, 'device check-ins are rate limited');
expect($second, 200, 'DELETE', "$A/devices/$id");
expect($device, 410, 'POST', "$D/checkin", []);

echo "All $step checks passed.\n";
