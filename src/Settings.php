<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;
use Shepherd\Http\HttpError;

/**
 * Server settings admins can change in the console (Server → Settings). They are
 * stored in storage/settings.json and applied on top of config/config.php, which
 * is never rewritten: it holds secrets and PHP code, and a bad write there would
 * take the whole site (and this page) down.
 *
 * Only the settings below can be changed here. Server address, database, single
 * sign-on, setup key, trusted proxies, cookies, password sign-in and where
 * self-updates come from stay in config.php, so a stolen admin login can't use
 * them to lock people out, weaken security or point updates at someone else's
 * code. The server address is one of them because single sign-on callbacks are built from it (a wrong value breaks SSO
 * sign-in) and enrolled tablets keep the address they enrolled with anyway.
 */
final class Settings
{
    private const FILE = 'settings.json';

    public const SCHEMA = [
        // --- General -----------------------------------------------------------
        'organisation' => ['type' => 'string', 'max' => 100, 'group' => 'General', 'label' => 'Organisation',
            'help' => 'Shown on managed tablets ("Managed by …"). Changing it changes every device\'s policy once.'],
        'timezone' => ['type' => 'timezone', 'group' => 'General', 'label' => 'Time zone',
            'help' => 'Default for new schedules, and the day boundaries of health history.'],
        'auto_migrate' => ['type' => 'bool', 'group' => 'General', 'label' => 'Apply database updates automatically',
            'help' => 'Off: admins apply them with the Update database banner instead.'],
        'session_lifetime' => ['type' => 'int', 'min' => 300, 'max' => 604800, 'group' => 'General',
            'label' => 'Sign out after being idle for (seconds)', 'help' => '43200 is 12 hours.'],

        // --- Devices and commands ------------------------------------------------
        'fast_checkin_seconds' => ['type' => 'int', 'min' => 15, 'max' => 60, 'group' => 'Devices and commands',
            'label' => 'Check-in interval while a device page is open (seconds)', 'help' => 'Kiosky never checks in more often than every 15 seconds.'],
        'command_ttl_hours' => ['type' => 'int', 'min' => 1, 'max' => 720, 'group' => 'Devices and commands',
            'label' => 'Queued commands expire after (hours)', 'help' => ''],
        'command_redeliver_seconds' => ['type' => 'int', 'min' => 60, 'max' => 3600, 'group' => 'Devices and commands',
            'label' => 'Resend an unanswered command after (seconds)', 'help' => ''],
        'max_screenshot_mb' => ['type' => 'int', 'min' => 1, 'max' => 20, 'group' => 'Devices and commands',
            'label' => 'Largest screenshot (MB)', 'help' => ''],
        'max_apk_mb' => ['type' => 'int', 'min' => 10, 'max' => 500, 'group' => 'Devices and commands',
            'label' => 'Largest APK upload (MB)', 'help' => 'PHP\'s upload_max_filesize and post_max_size must allow it too.'],
        'retention_days' => ['type' => 'int', 'min' => 7, 'max' => 3650, 'group' => 'Devices and commands',
            'label' => 'Keep events and finished commands for (days)', 'help' => 'The audit log is kept for at least a year.'],

        // --- Offline alerts ------------------------------------------------------
        'alerts.offline_minutes' => ['type' => 'int', 'min' => 5, 'max' => 1440, 'group' => 'Alerts',
            'label' => 'Report a device offline after (minutes)', 'help' => ''],
        'alerts.email' => ['type' => 'emails', 'group' => 'Alerts', 'label' => 'Email addresses',
            'help' => 'One per line. Sent through the SMTP server in config.php, or PHP mail() when there is none (see Email below).'],
        'alerts.email_from' => ['type' => 'email', 'group' => 'Alerts', 'label' => 'Send email from',
            'help' => 'An address on your domain, so messages don\'t land in spam. Empty: kiosky-shepherd@<server>.'],
        'alerts.webhooks' => ['type' => 'webhooks', 'group' => 'Alerts', 'label' => 'Webhooks',
            'help' => 'Webhooks that need extra headers (e.g. a token) can only be set in config.php.'],
        'alerts.ignore_tags' => ['type' => 'tags', 'group' => 'Alerts', 'label' => 'Never report devices tagged',
            'help' => 'Separated by commas.'],

        'alerts.problems' => ['type' => 'choices', 'group' => 'Alerts', 'label' => 'Also alert about',
            'choices' => ['page_error' => 'Page errors', 'lockdown' => 'Lockdown that can be escaped', 'low_battery' => 'Low battery',
                'battery_hot' => 'Battery hot', 'battery_health' => 'Battery failing (as reported by Android)',
                'permission' => 'Missing "Display over other apps" permission', 'policy' => 'Policy not applied', 'low_uptime' => 'Low uptime (under 90% over 7 days)'],
            'help' => 'Each is reported once when it has lasted two cron runs, and again when it clears.'],
        'alerts.low_battery' => ['type' => 'int', 'min' => 0, 'max' => 99, 'group' => 'Alerts',
            'label' => 'Low battery below (%)', 'help' => 'Only while not charging. 0 turns the battery check off.'],
        'alerts.battery_hot' => ['type' => 'int', 'min' => 0, 'max' => 80, 'group' => 'Alerts',
            'label' => 'Battery hot at (°C)', 'help' => 'Reported by Kiosky 1.7.0 and later. 0 turns the temperature check off.'],
        'alerts.quiet_start' => ['type' => 'time', 'group' => 'Alerts', 'label' => 'Quiet hours start',
            'help' => 'No alerts are sent between the two times (server time zone); whatever is still wrong afterwards is reported then. Leave both empty for none.'],
        'alerts.quiet_end' => ['type' => 'time', 'group' => 'Alerts', 'label' => 'Quiet hours end', 'help' => ''],

        // --- Security ------------------------------------------------------------
        'require_2fa' => ['type' => 'bool', 'group' => 'Security', 'label' => 'Require two-factor sign-in',
            'help' => 'Password users without an authenticator app or passkey must set one up before they can do anything else. Single sign-on users rely on their identity provider.'],

        // --- Reports and backups --------------------------------------------------
        'report.weekly' => ['type' => 'bool', 'group' => 'Reports and backups', 'label' => 'Email a weekly fleet report',
            'help' => 'Monday from 08:00 (server time zone): offline devices, least reliable tablets, versions, failed commands and changes.'],
        'report.recipients' => ['type' => 'emails', 'group' => 'Reports and backups', 'label' => 'Report recipients',
            'help' => 'One per line. Empty: the alert email addresses.'],
        'backup.nightly' => ['type' => 'bool', 'group' => 'Reports and backups', 'label' => 'Back up the database every night',
            'help' => 'After 03:00 (server time zone), into storage/backups. Download them under Server → Database backups.'],
        'backup.keep' => ['type' => 'int', 'min' => 1, 'max' => 60, 'group' => 'Reports and backups', 'label' => 'Database backups to keep', 'help' => ''],

        // --- Integrations ---------------------------------------------------------
        'events.webhooks' => ['type' => 'event_webhooks', 'group' => 'Integrations', 'label' => 'Event webhooks',
            'help' => 'POSTs JSON for each chosen event (device enrolled, offline, command failed…). With a secret, requests carry X-Shepherd-Signature: sha256=<HMAC of the body>.'],

        // --- Updates -------------------------------------------------------------
        'self_update.auto' => ['type' => 'bool', 'group' => 'Kiosky Shepherd updates', 'label' => 'Install new releases automatically',
            'help' => 'bin/cron.php installs each new build on the channel\'s branch by itself (every 6 hours at most). Never installs an older version. Needs installing from the console to be allowed in config.php.'],
        'self_update.channel' => ['type' => 'enum', 'values' => ['stable', 'dev'], 'group' => 'Kiosky Shepherd updates',
            'label' => 'Update channel',
            'help' => 'stable follows the main branch; dev follows the dev branch (newest changes first, less tested).'],
    ];

    /** Shown on the page, so admins know where the rest lives. */
    public const FILE_ONLY = [
        'Server address (base_url)', 'Database connection', 'Single sign-on providers and secrets', 'setup_key', 'Password sign-in on/off',
        'Trusted proxies', 'SMTP server and its password', 'Session cookie settings', 'Storage folder', 'Kiosky package name',
        'Where updates come from, and whether the console may install them',
    ];

    private const WEBHOOK_TYPES = ['json', 'slack', 'discord', 'teams', 'ntfy'];

    /** @return array<string, mixed> stored console settings, only known keys */
    public static function stored(): array
    {
        $data = json_decode((string) @file_get_contents(Config::storage(self::FILE)), true);
        return is_array($data) ? array_intersect_key($data, self::SCHEMA) : [];
    }

    /** Called by Config::load(): puts the stored settings on top of config.php. */
    public static function applyStored(): void
    {
        foreach (self::stored() as $key => $value) {
            Config::set($key, $value);
        }
    }

    /** Built-in defaults for keys whose default lives in code rather than Config::DEFAULTS. */
    private static function codeDefault(string $key): mixed
    {
        return match ($key) {
            'alerts.offline_minutes' => 15,
            'alerts.email', 'alerts.webhooks' => [],
            'alerts.email_from' => '',
            'alerts.ignore_tags' => ['no-alerts'],
            'self_update.auto', 'require_2fa', 'report.weekly', 'backup.nightly' => false,
            'alerts.problems' => ['page_error', 'lockdown', 'low_battery', 'battery_hot', 'battery_health'],
            'alerts.low_battery' => 20,
            'alerts.battery_hot' => 45,
            'alerts.quiet_start', 'alerts.quiet_end' => '',
            'report.recipients', 'events.webhooks' => [],
            'backup.keep' => 7,
            'self_update.channel' => 'stable',
            default => null,
        };
    }

    /** Everything the console's settings form needs. */
    public static function describe(): array
    {
        $stored = self::stored();
        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            $file = Config::fileValue($key, self::codeDefault($key));
            $item = ['key' => $key] + $def + [
                'file_value' => self::forDisplay($key, $file),
                'value' => self::forDisplay($key, array_key_exists($key, $stored) ? $stored[$key] : $file),
                'overridden' => array_key_exists($key, $stored),
            ];
            if (str_starts_with($key, 'self_update.') && !Config::fileValue('self_update.enabled', true)) {
                $item['disabled_reason'] = 'Installing from the console is turned off in config.php.';
            }
            $out[] = $item;
        }
        return [
            'settings' => $out,
            'file_only' => self::FILE_ONLY,
            'writable' => is_writable(Config::storage()),
            'webhook_types' => self::WEBHOOK_TYPES,
        ];
    }

    /** Webhooks from config.php may carry header secrets: the console only ever sees type and URL. */
    private static function forDisplay(string $key, mixed $value): mixed
    {
        if ($key === 'events.webhooks' && is_array($value)) {
            return array_values(array_map(static fn ($w) => [
                'url' => (string) ($w['url'] ?? ''), 'events' => array_values((array) ($w['events'] ?? [])),
                'has_secret' => !empty($w['secret']),
            ], array_filter($value, 'is_array')));
        }
        if ($key === 'alerts.webhooks' && is_array($value)) {
            return array_values(array_map(static fn ($w) => [
                'type' => is_array($w) ? (string) ($w['type'] ?? 'json') : 'json',
                'url' => is_array($w) ? (string) ($w['url'] ?? '') : '',
                'has_headers' => is_array($w) && !empty($w['headers']),
            ], array_filter($value, 'is_array')));
        }
        return $value;
    }

    /**
     * Saves changes: {key: value} sets a value, {key: null} goes back to config.php.
     *
     * @param array<string, mixed> $changes
     * @return list<string> the keys that changed
     */
    public static function save(array $changes, Principal $by): array
    {
        $stored = self::stored();
        $errors = [];
        $changed = [];
        foreach ($changes as $key => $value) {
            $key = (string) $key;
            if (!isset(self::SCHEMA[$key])) {
                $errors[$key] = 'This setting can only be changed in config/config.php.';
                continue;
            }
            if ($value === null) {
                if (array_key_exists($key, $stored)) {
                    unset($stored[$key]);
                    $changed[] = $key;
                }
                continue;
            }
            $err = null;
            $norm = self::normalise($key, self::SCHEMA[$key], $value, $err);
            if ($err !== null) {
                $errors[$key] = $err;
                continue;
            }
            if (!array_key_exists($key, $stored) || Db::json($stored[$key]) !== Db::json($norm)) {
                $stored[$key] = $norm;
                $changed[] = $key;
            }
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        if ($changed === []) {
            return [];
        }
        $path = Config::storage(self::FILE);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(3));
        ksort($stored);
        if (file_put_contents($tmp, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false
            || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new HttpError(500, 'settings_not_saved', 'Couldn\'t write ' . $path . '. PHP needs write access to the storage folder.');
        }
        // Apply now too, so this response and anything after it in the request sees them
        foreach ($changed as $key) {
            Config::set($key, array_key_exists($key, $stored) ? $stored[$key] : Config::fileValue($key, self::codeDefault($key)));
        }
        Audit::log($by, 'server.settings_changed', null, null, ['keys' => $changed]);
        return $changed;
    }

    private static function normalise(string $key, array $def, mixed $v, ?string &$err): mixed
    {
        switch ($def['type']) {
            case 'bool':
                if (!is_bool($v)) {
                    $err = 'Must be on or off.';
                }
                return $v;
            case 'int':
                if (is_string($v) && preg_match('/^\d+$/', trim($v))) {
                    $v = (int) trim($v);
                }
                if (!is_int($v) || $v < $def['min'] || $v > $def['max']) {
                    $err = "Use a whole number from {$def['min']} to {$def['max']}.";
                }
                return $v;
            case 'string':
                $v = is_string($v) ? trim($v) : '';
                if ($v === '' || mb_strlen($v) > $def['max'] || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                    $err = "Enter up to {$def['max']} characters.";
                }
                return $v;
            case 'choices':
                if (!is_array($v) || !array_is_list($v) || array_diff($v, array_keys($def['choices'])) !== []) {
                    $err = 'Choose from the list.';
                    return [];
                }
                return array_values(array_unique($v));
            case 'time':
                $v = is_string($v) ? trim($v) : '';
                if ($v !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                    $err = 'Use a 24-hour time such as 22:00, or leave it empty.';
                }
                return $v;
            case 'event_webhooks':
                return self::normaliseEventWebhooks($v, $err);
            case 'enum':
                if (!is_string($v) || !in_array($v, $def['values'], true)) {
                    $err = 'Use ' . implode(' or ', $def['values']) . '.';
                }
                return $v;
            case 'timezone':
                if (!Schedules::isTimezone($v)) {
                    $err = 'Use a time zone name such as Europe/London or UTC.';
                }
                return $v;
            case 'email':
                $v = is_string($v) ? trim($v) : '';
                if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                    $err = 'Not a valid email address.';
                }
                return $v;
            case 'emails':
                $list = is_string($v) ? preg_split('/[\s,;]+/', $v, -1, PREG_SPLIT_NO_EMPTY) : $v;
                if (!is_array($list) || count($list) > 50) {
                    $err = 'Use up to 50 email addresses.';
                    return [];
                }
                $list = array_values(array_unique(array_map('trim', array_filter($list, 'is_string'))));
                foreach ($list as $a) {
                    if (filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
                        $err = "Not a valid email address: $a";
                    }
                }
                return $list;
            case 'tags':
                try {
                    return Devices::validateTags($v, $key);
                } catch (HttpError $e) {
                    $err = 'Tags use lower-case letters, digits, - _ and ., separated by commas.';
                    return [];
                }
            case 'webhooks':
                return self::normaliseWebhooks($v, $err);
        }
        $err = 'Unsupported setting.';
        return null;
    }

    /** Event webhooks: url, events, and a secret that's kept unless a new one is typed. */
    private static function normaliseEventWebhooks(mixed $v, ?string &$err): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > 10) {
            $err = 'Use a list of up to 10 webhooks.';
            return [];
        }
        $old = array_column(array_filter((array) Config::get('events.webhooks', []), 'is_array'), null, 'url');
        $out = [];
        foreach ($v as $w) {
            $url = is_array($w) && is_string($w['url'] ?? null) ? trim($w['url']) : '';
            if (!preg_match('#^https?://\S+$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false || strlen($url) > 1000) {
                $err = "Not a valid webhook address: $url";
                return [];
            }
            $events = array_values(array_intersect((array) ($w['events'] ?? []), array_keys(Events::TYPES)));
            $secret = is_string($w['secret'] ?? null) ? trim($w['secret']) : '';
            if ($secret === '' && empty($w['clear_secret'])) {
                $secret = (string) ($old[$url]['secret'] ?? '');
            }
            if (strlen($secret) > 200) {
                $err = 'Secrets are at most 200 characters.';
                return [];
            }
            $out[] = ['url' => $url, 'events' => $events, 'secret' => $secret];
        }
        return $out;
    }

    /**
     * Webhooks from the console: type and URL. A hook kept unchanged from config.php
     * keeps its headers (the console never sees them).
     */
    private static function normaliseWebhooks(mixed $v, ?string &$err): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > 20) {
            $err = 'Use a list of up to 20 webhooks.';
            return [];
        }
        $fileHooks = array_filter((array) Config::fileValue('alerts.webhooks', []), 'is_array');
        $out = [];
        foreach ($v as $w) {
            $type = is_array($w) ? ($w['type'] ?? 'json') : null;
            $url = is_array($w) && is_string($w['url'] ?? null) ? trim($w['url']) : '';
            if (!in_array($type, self::WEBHOOK_TYPES, true)) {
                $err = 'Webhook types are ' . implode(', ', self::WEBHOOK_TYPES) . '.';
                return [];
            }
            if (!preg_match('#^https?://[^\s]+$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false || strlen($url) > 1000) {
                $err = "Not a valid webhook address: $url";
                return [];
            }
            $hook = ['type' => $type, 'url' => $url];
            foreach ($fileHooks as $f) {
                if (($f['url'] ?? null) === $url && !empty($f['headers']) && is_array($f['headers'])) {
                    $hook['headers'] = $f['headers'];
                }
            }
            $out[] = $hook;
        }
        return $out;
    }
}
