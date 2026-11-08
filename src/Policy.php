<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * The managed settings a Kiosky device obeys.
 *
 * Settings are stored as flat maps ("toolbar.mode" => "hidden") on profiles and
 * as per-device overrides. The effective value of each key is the device
 * override if set, else the profile value if set, else the default below.
 * Devices receive the result as nested JSON (see docs/PROTOCOL.md).
 */
final class Policy
{
    public const SCHEMA = [
        // --- Page ---------------------------------------------------------------
        'url' => [
            'type' => 'url', 'default' => '', 'group' => 'Page', 'label' => 'Start page',
            'help' => 'The https:// address Kiosky shows. Leave empty to show a "waiting for configuration" screen.',
        ],
        'allowed_hosts' => [
            'type' => 'hosts', 'default' => [], 'group' => 'Page', 'label' => 'Allowed sites',
            'help' => 'Only pages on these hosts can open, e.g. ha.example.com or *.example.com. The start page\'s host is always allowed. Empty allows any https site.',
        ],
        'reload_minutes' => [
            'type' => 'int', 'min' => 0, 'max' => 10080, 'default' => 0, 'group' => 'Page',
            'label' => 'Reload every (minutes)', 'help' => '0 turns periodic reloading off.',
        ],
        'retry_seconds' => [
            'type' => 'int', 'min' => 3, 'max' => 3600, 'default' => 10, 'group' => 'Page',
            'label' => 'Retry after a load error (seconds)', 'help' => '',
        ],
        'text_zoom' => [
            'type' => 'int', 'min' => 50, 'max' => 300, 'default' => 100, 'group' => 'Page',
            'label' => 'Text size (%)', 'help' => '',
        ],
        'user_agent_suffix' => [
            'type' => 'string', 'max' => 100, 'default' => '', 'group' => 'Page',
            'label' => 'User agent suffix', 'help' => 'Appended to the WebView user agent, so sites can recognise kiosks.',
        ],
        'autoplay_media' => [
            'type' => 'bool', 'default' => true, 'group' => 'Page',
            'label' => 'Play sound and video without a tap', 'help' => '',
        ],
        'third_party_cookies' => [
            'type' => 'bool', 'default' => true, 'group' => 'Page',
            'label' => 'Allow third-party cookies', 'help' => 'Some single sign-on flows need this.',
        ],

        // --- Device -------------------------------------------------------------
        'start_on_boot' => [
            'type' => 'bool', 'default' => true, 'group' => 'Device',
            'label' => 'Start on boot', 'help' => 'Android 10+ also needs "Display over other apps" granted on the device; its status is shown on the device page.',
        ],
        'keep_screen_on' => [
            'type' => 'bool', 'default' => true, 'group' => 'Device',
            'label' => 'Keep the screen on', 'help' => '',
        ],
        'orientation' => [
            'type' => 'enum', 'values' => ['auto', 'portrait', 'landscape', 'reverse_portrait', 'reverse_landscape'],
            'default' => 'auto', 'group' => 'Device', 'label' => 'Screen orientation', 'help' => '',
        ],

        // --- Lockdown -----------------------------------------------------------
        'toolbar.mode' => [
            'type' => 'enum', 'values' => ['hidden', 'limited', 'full'], 'default' => 'hidden', 'group' => 'Lockdown',
            'label' => 'Toolbar',
            'help' => 'hidden: edge swipes show nothing. limited: Reload and Help only. full: also the address box; anything typed there is temporary and resets on restart.',
        ],
        'toolbar.timeout_seconds' => [
            'type' => 'int', 'min' => 5, 'max' => 600, 'default' => 15, 'group' => 'Lockdown',
            'label' => 'Hide toolbar after (seconds)', 'help' => '',
        ],
        'toolbar.pin' => [
            'type' => 'pin', 'default' => null, 'group' => 'Lockdown', 'label' => 'Technician PIN',
            'help' => '4–12 digits. Entering it on the device opens the full toolbar until it next hides. It deters passers-by; it is not strong protection.',
        ],
        'allow_exit' => [
            'type' => 'bool', 'default' => false, 'group' => 'Lockdown',
            'label' => 'Back button can close Kiosky', 'help' => 'When off, Back on the first page does nothing.',
        ],
        'lockdown' => [
            'type' => 'bool', 'default' => false, 'group' => 'Lockdown',
            'label' => 'Lock the tablet to Kiosky',
            'help' => 'Blocks the notification shade, Home, Recents and Settings so Kiosky can\'t be left. Complete when Kiosky is the device owner or allowed by an MDM; otherwise partial, and stronger with the accessibility helper turned on (each device page shows lockdown_mode). Needs Kiosky 1.4.0 or later.',
        ],

        // --- Updates ------------------------------------------------------------
        'updates.source' => [
            'type' => 'enum', 'values' => ['server', 'github', 'off'], 'default' => 'server', 'group' => 'Updates',
            'label' => 'Update source',
            'help' => 'server: install the release chosen below from this server. github: Kiosky\'s own GitHub release check. off: never update.',
        ],
        'updates.release' => [
            'type' => 'release', 'default' => 'latest', 'group' => 'Updates', 'label' => 'Release',
            'help' => '"latest" or a version uploaded under Releases, e.g. 1.2.0. Devices only ever move up; Android refuses downgrades.',
        ],
        'updates.auto_install' => [
            'type' => 'bool', 'default' => true, 'group' => 'Updates', 'label' => 'Install without waiting',
            'help' => 'Start installing as soon as an update is found. Android may still show its own confirmation.',
        ],
        'updates.check_hours' => [
            'type' => 'int', 'min' => 1, 'max' => 168, 'default' => 12, 'group' => 'Updates',
            'label' => 'GitHub check interval (hours)', 'help' => 'Only used when the source is github.',
        ],

        // --- Management ---------------------------------------------------------
        'checkin_seconds' => [
            'type' => 'int', 'min' => 15, 'max' => 3600, 'default' => 60, 'group' => 'Management',
            'label' => 'Check in every (seconds)', 'help' => 'How often devices fetch policy and commands.',
        ],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $d) => $d['default'], self::SCHEMA);
    }

    /**
     * Validates a partial settings map. A null value means "remove this key"
     * (inherit), and is kept as null in the result so callers can apply it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> normalised values
     * @throws Http\HttpError on invalid input
     */
    public static function validate(array $input): array
    {
        $out = [];
        $errors = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            $def = self::SCHEMA[$key] ?? null;
            if ($def === null) {
                $errors[$key] = 'Unknown setting.';
                continue;
            }
            if ($value === null) {
                $out[$key] = null;
                continue;
            }
            if ($def['type'] === 'pin' && is_array($value) && ($value['set'] ?? null) === true) {
                continue; // the redacted placeholder from a GET: leave the stored PIN alone
            }
            $err = null;
            $norm = self::normalise($def, $value, $err);
            if ($err !== null) {
                $errors[$key] = $err;
            } else {
                $out[$key] = $norm;
            }
        }
        if ($errors !== []) {
            throw Http\HttpError::validation($errors);
        }
        return $out;
    }

    private static function normalise(array $def, mixed $v, ?string &$err): mixed
    {
        switch ($def['type']) {
            case 'bool':
                if (is_bool($v)) {
                    return $v;
                }
                $err = 'Must be true or false.';
                return null;

            case 'int':
                if (is_string($v) && preg_match('/^-?\d+$/', trim($v))) {
                    $v = (int) trim($v);
                }
                if (!is_int($v)) {
                    $err = 'Must be a whole number.';
                    return null;
                }
                if ($v < $def['min'] || $v > $def['max']) {
                    $err = "Must be between {$def['min']} and {$def['max']}.";
                    return null;
                }
                return $v;

            case 'string':
                if (!is_string($v)) {
                    $err = 'Must be text.';
                    return null;
                }
                $v = trim($v);
                if (mb_strlen($v) > $def['max']) {
                    $err = "Use at most {$def['max']} characters.";
                    return null;
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
                    $err = 'Control characters are not allowed.';
                    return null;
                }
                return $v;

            case 'enum':
                if (!is_string($v) || !in_array($v, $def['values'], true)) {
                    $err = 'Must be one of: ' . implode(', ', $def['values']) . '.';
                    return null;
                }
                return $v;

            case 'url':
                if (!is_string($v)) {
                    $err = 'Must be text.';
                    return null;
                }
                $v = trim($v);
                if ($v === '') {
                    return '';
                }
                if (!str_contains($v, '://')) {
                    $v = 'https://' . $v; // same convenience as the app's address box
                }
                if (strlen($v) > 2048 || filter_var($v, FILTER_VALIDATE_URL) === false
                    || strtolower((string) parse_url($v, PHP_URL_SCHEME)) !== 'https') {
                    $err = 'Must be an https:// address. Kiosky blocks plain http.';
                    return null;
                }
                return $v;

            case 'hosts':
                if (is_string($v)) {
                    $v = preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                if (!is_array($v) || !array_is_list($v)) {
                    $err = 'Must be a list of host names.';
                    return null;
                }
                if (count($v) > 100) {
                    $err = 'At most 100 hosts.';
                    return null;
                }
                $hosts = [];
                foreach ($v as $h) {
                    $h = is_string($h) ? strtolower(trim($h)) : '';
                    if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(:\d{1,5})?$/', $h)) {
                        $err = 'Not a valid host name: ' . Util::clip($h, 60) . '. Use names like example.com or *.example.com.';
                        return null;
                    }
                    $hosts[$h] = true;
                }
                return array_keys($hosts);

            case 'pin':
                // A stored hash (round-tripped through the API) is accepted unchanged
                if (is_array($v) && self::isPinHash($v)) {
                    return $v;
                }
                if (is_int($v)) {
                    $v = (string) $v;
                }
                if (!is_string($v) || !preg_match('/^\d{4,12}$/', $v)) {
                    $err = 'Use 4 to 12 digits.';
                    return null;
                }
                return self::hashPin($v);

            case 'release':
                if (!is_string($v) || trim($v) === '') {
                    $err = 'Use "latest" or a release version.';
                    return null;
                }
                $v = trim($v);
                if ($v === 'latest') {
                    return $v;
                }
                if (Db::value('SELECT id FROM releases WHERE version_name = :v', ['v' => $v]) === null) {
                    $err = "No release $v has been uploaded.";
                    return null;
                }
                return $v;
        }
        $err = 'Unsupported setting type.';
        return null;
    }

    /** PBKDF2 so the device can check a PIN offline without the server holding it in clear. */
    public static function hashPin(string $pin): array
    {
        $iterations = (int) Config::get('pbkdf2_iterations', 120000);
        $salt = random_bytes(16);
        return [
            'algo' => 'pbkdf2-sha256',
            'iterations' => $iterations,
            'salt' => base64_encode($salt),
            'hash' => base64_encode(hash_pbkdf2('sha256', $pin, $salt, $iterations, 32, true)),
        ];
    }

    private static function isPinHash(array $v): bool
    {
        return ($v['algo'] ?? null) === 'pbkdf2-sha256'
            && is_int($v['iterations'] ?? null) && $v['iterations'] >= 10000
            && is_string($v['salt'] ?? null) && base64_decode($v['salt'], true) !== false
            && is_string($v['hash'] ?? null) && strlen((string) base64_decode($v['hash'], true)) === 32;
    }

    /**
     * Applies a validated partial map onto stored settings: null removes a key.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function apply(array $stored, array $changes): array
    {
        foreach ($changes as $k => $v) {
            if ($v === null) {
                unset($stored[$k]);
            } else {
                $stored[$k] = $v;
            }
        }
        // Drop keys that are no longer in the schema
        $stored = array_intersect_key($stored, self::SCHEMA);
        ksort($stored);
        return $stored;
    }

    /**
     * Effective flat settings plus where each value came from.
     *
     * @return array{values: array<string, mixed>, sources: array<string, string>}
     */
    public static function resolve(array $profileSettings, array $deviceOverrides): array
    {
        $values = [];
        $sources = [];
        foreach (self::SCHEMA as $key => $def) {
            if (array_key_exists($key, $deviceOverrides)) {
                $values[$key] = $deviceOverrides[$key];
                $sources[$key] = 'device';
            } elseif (array_key_exists($key, $profileSettings)) {
                $values[$key] = $profileSettings[$key];
                $sources[$key] = 'profile';
            } else {
                $values[$key] = $def['default'];
                $sources[$key] = 'default';
            }
        }
        return ['values' => $values, 'sources' => $sources];
    }

    /** Effective policy for a device row, in the nested form sent to the app. */
    public static function forDevice(array $device): array
    {
        $resolved = self::resolve(
            self::profileSettings((int) $device['profile_id']),
            Db::decode($device['overrides'] ?? null)
        );
        return self::toDevice($resolved['values']);
    }

    /** @var array<int, array<string, mixed>> per-request cache */
    private static array $profileCache = [];

    public static function profileSettings(int $profileId): array
    {
        if (!isset(self::$profileCache[$profileId])) {
            $json = Db::value('SELECT settings FROM profiles WHERE id = :id', ['id' => $profileId]);
            self::$profileCache[$profileId] = Db::decode(is_string($json) ? $json : null);
        }
        return self::$profileCache[$profileId];
    }

    public static function forgetProfile(int $profileId): void
    {
        unset(self::$profileCache[$profileId]);
    }

    /** Builds the nested device policy with a content-derived version. */
    public static function toDevice(array $flat): array
    {
        $nested = ['managed' => true, 'organisation' => (string) Config::get('organisation', 'Kiosky Shepherd')];
        foreach ($flat as $key => $value) {
            $ref = &$nested;
            foreach (explode('.', $key) as $part) {
                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
            $ref = $value;
            unset($ref);
        }
        self::ksortRecursive($nested);
        $nested['version'] = substr(hash('sha256', Db::json($nested)), 0, 16);
        return $nested;
    }

    private static function ksortRecursive(array &$a): void
    {
        if (array_is_list($a)) {
            return;
        }
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
    }

    /** Hides PIN hashes in admin output: they are replaced with {"set": true}. */
    public static function redact(array $flat): array
    {
        foreach (self::SCHEMA as $key => $def) {
            if ($def['type'] === 'pin' && isset($flat[$key]) && is_array($flat[$key])) {
                $flat[$key] = ['set' => true];
            }
        }
        return $flat;
    }

    /** Schema for building forms in the admin UI. */
    public static function schemaForUi(): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            $out[] = ['key' => $key] + $def;
        }
        return $out;
    }
}
