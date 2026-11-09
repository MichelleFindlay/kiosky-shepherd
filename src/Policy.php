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
        'idle_home_minutes' => [
            'type' => 'int', 'min' => 0, 'max' => 1440, 'default' => 0, 'group' => 'Page',
            'label' => 'Back to the start page after (minutes untouched)',
            'help' => 'If someone taps into another page and walks away, the tablet returns to the start page. 0 turns this off. Needs Kiosky 1.7.0 or later.',
        ],
        'watchdog_minutes' => [
            'type' => 'int', 'min' => 0, 'max' => 60, 'default' => 2, 'group' => 'Page',
            'label' => 'Restart a frozen page after (minutes)',
            'help' => 'Kiosky checks the page still responds. If it doesn\'t for this long, it reloads it, then rebuilds it if that doesn\'t help. 0 turns the check off. Needs Kiosky 1.7.0 or later.',
        ],

        'file_uploads' => [
            'type' => 'bool', 'default' => true, 'group' => 'Page',
            'label' => 'Pages may upload files',
            'help' => 'Lets "choose file" buttons open Android\'s file picker. The picker is another app, so turn this off on locked-down public tablets that don\'t need it. Needs Kiosky 1.7.0 or later.',
        ],

        'session.clear_on_idle' => [
            'type' => 'bool', 'default' => false, 'group' => 'Page',
            'label' => 'Shared tablet: sign out when it goes back to the start page',
            'help' => 'For tablets the public uses, such as visitor check-in. When the tablet returns to the start page after "Back to the start page after", it also clears cookies, saved site data, cache and history, so the next person isn\'t signed in as the last. Only if someone touched it since the last time. Needs Back to the start page set, and Kiosky 1.8.0 or later.',
        ],

        'offline.last_page' => [
            'type' => 'bool', 'default' => true, 'group' => 'Page',
            'label' => 'While reconnecting, show the last page greyed out',
            'help' => 'Instead of a full "Reconnecting…" screen, a greyed-out picture of the last page that loaded properly, with a small banner. Never kept of a shared tablet\'s other pages. Needs Kiosky 1.8.0 or later.',
        ],

        // --- Touch and scanners (Kiosky 1.8.0+) -------------------------------------
        'touch.zoom' => [
            'type' => 'bool', 'default' => false, 'group' => 'Touch and scanners', 'label' => 'Pinch to zoom',
            'help' => 'Off, a stray two-finger touch can\'t leave the page zoomed in. Needs Kiosky 1.8.0 or later.',
        ],
        'touch.long_press' => [
            'type' => 'bool', 'default' => true, 'group' => 'Touch and scanners', 'label' => 'Long press',
            'help' => 'Off, pressing and holding does nothing: no text selection with its Copy/Share/Search menu (which can lead to other apps), no link previews, and no paste menu in text boxes.',
        ],
        'touch.text_selection' => [
            'type' => 'bool', 'default' => true, 'group' => 'Touch and scanners', 'label' => 'Selecting the page\'s text',
            'help' => 'Off, the page\'s text can\'t be highlighted. Text boxes still work.',
        ],
        'input.nfc' => [
            'type' => 'bool', 'default' => false, 'group' => 'Touch and scanners', 'label' => 'Read NFC tags',
            'help' => 'While Kiosky is in front, tags held to the tablet go to the page (kiosky:nfc events) and to MQTT, and can\'t open other apps. For check-in and attendance pages.',
        ],
        'input.barcode' => [
            'type' => 'bool', 'default' => true, 'group' => 'Touch and scanners', 'label' => 'Barcode scanners',
            'help' => 'USB and Bluetooth scanners that type like a keyboard: each scan also goes to the page (kiosky:barcode events) and to MQTT.',
        ],

        // --- Sign-in and certificates (Kiosky 1.7.0+) ------------------------------
        'http_auth.host' => [
            'type' => 'string', 'max' => 253, 'default' => '', 'group' => 'Sign-in and certificates',
            'label' => 'HTTP sign-in: site',
            'help' => 'For pages behind a browser username/password prompt (HTTP basic auth). Empty: the start page\'s site.',
        ],
        'http_auth.username' => [
            'type' => 'string', 'max' => 200, 'default' => '', 'group' => 'Sign-in and certificates',
            'label' => 'HTTP sign-in: username', 'help' => '',
        ],
        'http_auth.password' => [
            'type' => 'secret', 'max' => 200, 'default' => '', 'group' => 'Sign-in and certificates',
            'label' => 'HTTP sign-in: password',
            'help' => 'Sent to the tablets with their policy, so use an account that can only view the kiosk page. Never shown again here once saved.',
        ],
        'trusted_certificates' => [
            'type' => 'fingerprints', 'default' => [], 'group' => 'Sign-in and certificates',
            'label' => 'Trusted certificates (SHA-256)',
            'help' => 'For servers with a self-signed or private certificate, such as Home Assistant on your network. One SHA-256 fingerprint per line (AB:12:… or plain hex). Trusted only on the start page\'s site and Allowed sites. A tablet that meets an untrusted certificate logs certificate_untrusted with its fingerprint, ready to copy here.',
        ],

        // --- Camera and microphone (Kiosky 1.7.0+) ---------------------------------
        'media.camera' => [
            'type' => 'bool', 'default' => true, 'group' => 'Camera and microphone',
            'label' => 'Pages may use the camera',
            'help' => 'For video doorbells and intercoms. Only the start page\'s site and sites listed by name under Allowed sites get it (an empty list allows no others). Android asks once on the tablet unless Kiosky is the device owner.',
        ],
        'media.microphone' => [
            'type' => 'bool', 'default' => true, 'group' => 'Camera and microphone',
            'label' => 'Pages may use the microphone', 'help' => 'For two-way audio. Same sites as the camera.',
        ],

        // --- Home Assistant (MQTT) (Kiosky 1.8.0+) ---------------------------------
        'mqtt.host' => [
            'type' => 'host', 'default' => '', 'group' => 'Home Assistant (MQTT)', 'label' => 'MQTT broker',
            'help' => 'Host name or IP address of an MQTT broker (such as Home Assistant\'s Mosquitto add-on), so a home server can see and control the tablet directly: screen, brightness, volume, page, announcements, motion and battery. Empty: off. Needs Kiosky 1.8.0 or later.',
        ],
        'mqtt.port' => [
            'type' => 'int', 'min' => 1, 'max' => 65535, 'default' => 1883, 'group' => 'Home Assistant (MQTT)',
            'label' => 'Port', 'help' => 'Usually 1883, or 8883 with TLS.',
        ],
        'mqtt.tls' => [
            'type' => 'bool', 'default' => false, 'group' => 'Home Assistant (MQTT)', 'label' => 'Use TLS', 'help' => 'Encrypts the connection. Set the port too (usually 8883).',
        ],
        'mqtt.fingerprint' => [
            'type' => 'fingerprint', 'default' => '', 'group' => 'Home Assistant (MQTT)', 'label' => 'Broker certificate (SHA-256)',
            'help' => 'For a broker with a self-signed certificate: its SHA-256 fingerprint, trusted instead of the usual certificate checks. Empty: the certificate must be valid for the broker\'s name.',
        ],
        'mqtt.username' => [
            'type' => 'string', 'max' => 200, 'default' => '', 'group' => 'Home Assistant (MQTT)', 'label' => 'Username', 'help' => '',
        ],
        'mqtt.password' => [
            'type' => 'secret', 'max' => 200, 'default' => '', 'group' => 'Home Assistant (MQTT)', 'label' => 'Password',
            'help' => 'Anyone who can publish to the broker can control the tablets, so give it a password. Never shown again here once saved.',
        ],
        'mqtt.topic' => [
            'type' => 'topic', 'default' => '', 'group' => 'Home Assistant (MQTT)', 'label' => 'Base topic',
            'help' => 'Empty: kiosky/ and the start of the tablet\'s Android ID. Set it per device (as an override) if you want readable topics; two tablets must not share one.',
        ],
        'mqtt.name' => [
            'type' => 'string', 'max' => 100, 'default' => '', 'group' => 'Home Assistant (MQTT)', 'label' => 'Name in Home Assistant',
            'help' => 'Empty: the device\'s name here.',
        ],
        'mqtt.discovery' => [
            'type' => 'bool', 'default' => true, 'group' => 'Home Assistant (MQTT)', 'label' => 'Home Assistant discovery',
            'help' => 'The tablet adds itself to Home Assistant as a device with its entities.',
        ],
        'mqtt.discovery_prefix' => [
            'type' => 'topic', 'default' => 'homeassistant', 'group' => 'Home Assistant (MQTT)', 'label' => 'Discovery prefix',
            'help' => 'Only change it if you changed it in Home Assistant.',
        ],

        // --- Wi-Fi (Kiosky 1.8.0+) -------------------------------------------------
        'wifi.networks' => [
            'type' => 'wifi', 'default' => [], 'group' => 'Wi-Fi', 'label' => 'Wi-Fi networks',
            'help' => 'Saved on the tablets, so a new router or password doesn\'t need a visit. One per line: name | password | wpa2, wpa3 or open | hidden (the last two optional), the first preferred, at most 10. A changed password that doesn\'t connect within 10 minutes is put back. Leaving the list empty changes nothing on the tablets. Needs Kiosky 1.8.0 or later, as device owner on Android 10 and later; the device page shows wifi_config. Passwords are sent to the tablets and never shown here again: (saved) keeps one.',
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
        'reboot_at' => [
            'type' => 'time', 'default' => '', 'group' => 'Device', 'label' => 'Restart every day at',
            'help' => 'Tablet\'s local time, e.g. 04:00. A full reboot when Kiosky is the device owner (Android 7+), otherwise a fresh restart of Kiosky. Clears slow memory leaks. Empty: never. Needs Kiosky 1.7.0 or later.',
        ],

        // --- Screensaver and sound (Kiosky 1.7.0+) ---------------------------------
        'screensaver.minutes' => [
            'type' => 'int', 'min' => 0, 'max' => 1440, 'default' => 0, 'group' => 'Screensaver and sound',
            'label' => 'Screensaver after (minutes untouched)',
            'help' => 'A drifting clock and date over the page; a touch closes it without pressing anything. 0 turns it off.',
        ],
        'screensaver.url' => [
            'type' => 'url', 'default' => '', 'group' => 'Screensaver and sound', 'label' => 'Screensaver page',
            'help' => 'An https:// page to show instead of the clock, such as a photo frame. It can\'t navigate to other sites.',
        ],
        'audio.volume' => [
            'type' => 'int', 'min' => -1, 'max' => 100, 'default' => -1, 'group' => 'Screensaver and sound',
            'label' => 'Volume (%)', 'help' => '-1 leaves the tablet\'s volume as it is. Applied when it changes, so someone can still adjust it until then.',
        ],
        'audio.quiet_at' => [
            'type' => 'time', 'default' => '', 'group' => 'Screensaver and sound', 'label' => 'Quiet hours from',
            'help' => 'e.g. 22:00. Leave empty for no quiet hours.',
        ],
        'audio.quiet_until' => [
            'type' => 'time', 'default' => '', 'group' => 'Screensaver and sound', 'label' => 'Quiet hours until', 'help' => 'e.g. 07:00.',
        ],
        'audio.quiet_volume' => [
            'type' => 'int', 'min' => 0, 'max' => 100, 'default' => 0, 'group' => 'Screensaver and sound',
            'label' => 'Volume during quiet hours (%)', 'help' => '0 mutes it.',
        ],

        // --- Page playlist (needs Kiosky support, see docs/PROTOCOL.md §5.2) -------
        'playlist.urls' => [
            'type' => 'urls', 'default' => [], 'group' => 'Page playlist', 'label' => 'Pages to rotate through',
            'help' => 'One https:// address per line, shown in turn after the start page. Empty: just the start page. Their hosts are always allowed. Needs Kiosky 1.7.0 or later.',
        ],
        'playlist.seconds' => [
            'type' => 'int', 'min' => 10, 'max' => 86400, 'default' => 60, 'group' => 'Page playlist',
            'label' => 'Show each page for (seconds)', 'help' => '',
        ],
        'playlist.pause_on_touch_seconds' => [
            'type' => 'int', 'min' => 0, 'max' => 3600, 'default' => 120, 'group' => 'Page playlist',
            'label' => 'After a touch, pause the rotation for (seconds)', 'help' => '0 keeps rotating while someone uses the screen.',
        ],

        // --- Screen schedule (needs Kiosky support, see docs/PROTOCOL.md §5.2) -----
        'screen.off_at' => [
            'type' => 'time', 'default' => '', 'group' => 'Screen schedule', 'label' => 'Turn the screen off at',
            'help' => 'Tablet\'s local time, e.g. 22:00. Leave empty to never turn it off. Needs Kiosky 1.7.0 or later.',
        ],
        'screen.on_at' => [
            'type' => 'time', 'default' => '', 'group' => 'Screen schedule', 'label' => 'Turn the screen back on at',
            'help' => 'e.g. 07:00. Used only when "off at" is set; it may be earlier than the off time (overnight).',
        ],
        'screen.wake_on_touch' => [
            'type' => 'bool', 'default' => true, 'group' => 'Screen schedule', 'label' => 'A touch wakes the screen',
            'help' => 'While the screen is off for the schedule, touching it turns it on for a few minutes. The touch doesn\'t reach the page. With no way to wake it, the screen switches off fully; five quick taps still wake it.',
        ],
        'screen.wake_on_motion' => [
            'type' => 'bool', 'default' => false, 'group' => 'Screen schedule', 'label' => 'Someone close by wakes the screen',
            'help' => 'Uses the tablet\'s proximity or light sensor (a hand near the top edge, a room light switched on), during the off hours and after idle dimming. Not every tablet has one.',
        ],
        'screen.wake_minutes' => [
            'type' => 'int', 'min' => 1, 'max' => 120, 'default' => 5, 'group' => 'Screen schedule',
            'label' => 'Stay on after a touch (minutes)', 'help' => '',
        ],
        'screen.dim_at' => [
            'type' => 'time', 'default' => '', 'group' => 'Screen schedule', 'label' => 'Dim the screen from',
            'help' => 'e.g. 20:00. Leave empty for no dimming. Needs Kiosky 1.7.0 or later.',
        ],
        'screen.dim_until' => [
            'type' => 'time', 'default' => '', 'group' => 'Screen schedule', 'label' => 'Dim the screen until',
            'help' => 'e.g. 07:00. May be earlier than the start (overnight).',
        ],
        'screen.idle_dim_minutes' => [
            'type' => 'int', 'min' => 0, 'max' => 1440, 'default' => 0, 'group' => 'Screen schedule',
            'label' => 'Also dim after (minutes untouched)', 'help' => 'The first touch brightens it again without pressing anything. 0 turns this off.',
        ],
        'screen.pixel_shift' => [
            'type' => 'bool', 'default' => false, 'group' => 'Screen schedule', 'label' => 'Shift the page against burn-in',
            'help' => 'Every few minutes the page moves a pixel or two along a tiny circle, so a dashboard shown all day doesn\'t burn into cheaper LCD and OLED screens. Needs Kiosky 1.8.0 or later.',
        ],
        'screen.dim_brightness' => [
            'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 15, 'group' => 'Screen schedule',
            'label' => 'Dimmed brightness (%)', 'help' => '',
        ],

        // --- Battery (Kiosky 1.7.0+) --------------------------------------------
        'battery.protect' => [
            'type' => 'bool', 'default' => false, 'group' => 'Battery',
            'label' => 'Limit charging to protect the battery',
            'help' => 'Tablets left on charge all the time wear out and can swell. Experimental: works on Samsung tablets with "Protect battery" (charging stops at about 85%), and only after running adb shell pm grant uk.lp0.ha android.permission.WRITE_SECURE_SETTINGS once. The device page shows battery_protect: on, no_permission or unsupported.',
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
            'label' => 'Back button can close Kiosky', 'help' => 'When off, Back on the first page does nothing. Kiosky 1.7.0+ never closes on Back while the tablet is locked to Kiosky.',
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
        'updates.channel' => [
            'type' => 'enum', 'values' => ['stable', 'dev'], 'default' => 'stable', 'group' => 'Updates',
            'label' => 'Release channel',
            'help' => 'stable: "latest" means the newest release marked stable. dev: the newest release of any kind, so these devices get development builds first. With the github source, dev also takes GitHub pre-releases (needs Kiosky support).',
        ],
        'updates.release' => [
            'type' => 'release', 'default' => 'latest', 'group' => 'Updates', 'label' => 'Release',
            'help' => '"latest" or a version uploaded under Releases, e.g. 1.2.0. Devices only ever move up; Android refuses downgrades.',
        ],
        'updates.auto_install' => [
            'type' => 'bool', 'default' => true, 'group' => 'Updates', 'label' => 'Install without waiting',
            'help' => 'Start installing as soon as an update is found. Android may still show its own confirmation.',
        ],
        'updates.rollout_hours' => [
            'type' => 'int', 'min' => 0, 'max' => 336, 'default' => 0, 'group' => 'Updates',
            'label' => 'GitHub rollout spread (hours)',
            'help' => 'Only used when the source is github: each tablet installs a new GitHub release at its own fixed point within this many hours after it\'s published, so a bad release can be pulled before most tablets have it. For the server source, set the spread on each release under Releases instead. 0: straight away. Needs Kiosky 1.8.0 or later.',
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
            if (in_array($def['type'], ['pin', 'secret'], true) && is_array($value) && ($value['set'] ?? null) === true) {
                continue; // the redacted placeholder from a GET: leave the stored value alone
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

            case 'secret':
                if (!is_string($v)) {
                    $err = 'Must be text.';
                    return null;
                }
                if (strlen($v) > $def['max']) {
                    $err = "Use at most {$def['max']} characters.";
                    return null;
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
                    $err = 'Control characters are not allowed.';
                    return null;
                }
                return $v; // not trimmed: spaces can be part of a password

            case 'fingerprints':
                if (is_string($v)) {
                    $v = preg_split('/[\r\n,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                if (!is_array($v) || !array_is_list($v) || count($v) > 50) {
                    $err = 'Use up to 50 SHA-256 fingerprints, one per line.';
                    return null;
                }
                $fps = [];
                foreach ($v as $f) {
                    $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', is_string($f) ? $f : ''));
                    if (strlen($hex) !== 64) {
                        $err = 'Not a SHA-256 fingerprint: ' . Util::clip(is_string($f) ? $f : '', 60) . '. It has 64 hex digits, e.g. AB:12:….';
                        return null;
                    }
                    $fps[implode(':', str_split(strtoupper($hex), 2))] = true;
                }
                return array_keys($fps);

            case 'host':
                if (!is_string($v)) {
                    $err = 'Must be text.';
                    return null;
                }
                $v = trim($v);
                if ($v !== '' && (strlen($v) > 253 || !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$|^[0-9A-Fa-f:]+$/', $v))) {
                    $err = 'Enter just the host name or IP address, like 192.168.1.10 or mqtt.example.com.';
                    return null;
                }
                return $v;

            case 'fingerprint':
                if (!is_string($v)) {
                    $err = 'Must be text.';
                    return null;
                }
                $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $v));
                if (trim($v) === '') {
                    return '';
                }
                if (strlen($hex) !== 64) {
                    $err = 'A SHA-256 fingerprint has 64 hex digits, e.g. AB:12:….';
                    return null;
                }
                return implode(':', str_split(strtoupper($hex), 2));

            case 'topic':
                if (!is_string($v)) {
                    $err = 'Must be text.';
                    return null;
                }
                $v = trim(trim($v), '/');
                if (strlen($v) > 200 || preg_match('/[+#\x00-\x1F\x7F]/', $v)) {
                    $err = 'Use at most 200 characters, without + or #.';
                    return null;
                }
                return $v;

            case 'wifi':
                return self::normaliseWifi($v, $err);

            case 'time':
                if (!is_string($v) || !preg_match('/^(([01]\d|2[0-3]):[0-5]\d)?$/', trim($v))) {
                    $err = 'Use a 24-hour time such as 22:00, or leave it empty.';
                    return null;
                }
                return trim($v);

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

            case 'urls':
                if (is_string($v)) {
                    $v = preg_split('/[\s]+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                if (!is_array($v) || !array_is_list($v) || count($v) > 20) {
                    $err = 'Use up to 20 https:// addresses.';
                    return null;
                }
                $urls = [];
                foreach ($v as $u) {
                    $e = null;
                    $n = self::normalise(['type' => 'url'], $u, $e);
                    if ($e !== null || $n === '') {
                        $err = 'Not an https:// address: ' . Util::clip(is_string($u) ? $u : '', 80);
                        return null;
                    }
                    $urls[] = $n;
                }
                return $urls;

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

    /** Marks a Wi-Fi password to keep from the stored settings (the console only ever sees "(saved)"). */
    private const KEEP = ['keep' => true];

    /**
     * Wi-Fi networks, from a list of {ssid, password, security, hidden} or the console's
     * text ("name | password | security | hidden", one per line).
     *
     * @return list<array{ssid: string, password: string|array{keep: true}, security: string, hidden: bool}>|null
     */
    private static function normaliseWifi(mixed $v, ?string &$err): ?array
    {
        if (is_string($v)) {
            $rows = [];
            foreach (preg_split('/\r\n|\r|\n/', $v) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line));
                $rows[] = ['ssid' => $parts[0], 'password' => $parts[1] ?? '', 'security' => $parts[2] ?? '',
                    'hidden' => in_array(strtolower($parts[3] ?? ''), ['hidden', 'yes', 'true', '1'], true)];
            }
            $v = $rows;
        }
        if (!is_array($v) || !array_is_list($v) || count($v) > 10) {
            $err = 'Use up to 10 networks, one per line: name | password | security | hidden.';
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($v as $n) {
            $ssid = is_array($n) && is_string($n['ssid'] ?? null) ? $n['ssid'] : '';
            if ($ssid === '' || strlen($ssid) > 32 || preg_match('/[\x00-\x1F\x7F|]/', $ssid)) {
                $err = 'Each network needs a name of 1 to 32 bytes, without |: ' . Util::clip($ssid, 40);
                return null;
            }
            if (isset($seen[$ssid])) {
                $err = "$ssid is listed twice.";
                return null;
            }
            $seen[$ssid] = true;
            $pw = $n['password'] ?? '';
            $keep = (is_array($pw) && (($pw['set'] ?? null) === true || ($pw['keep'] ?? null) === true)) || $pw === '(saved)';
            if (!$keep && !is_string($pw)) {
                $err = "The password for $ssid must be text.";
                return null;
            }
            $security = strtolower(is_string($n['security'] ?? null) ? trim($n['security']) : '');
            if ($security === '') {
                $security = !$keep && $pw === '' ? 'open' : 'wpa2';
            }
            if (!in_array($security, ['open', 'wpa2', 'wpa3'], true)) {
                $err = "Security for $ssid must be wpa2, wpa3 or open.";
                return null;
            }
            if ($security === 'open') {
                $pw = '';
            } elseif (!$keep && !(strlen($pw) >= 8 && strlen($pw) <= 63) && !preg_match('/^[0-9A-Fa-f]{64}$/', $pw)) {
                $err = "The password for $ssid must be 8 to 63 characters (or 64 hex digits).";
                return null;
            }
            $hidden = $n['hidden'] ?? false;
            $out[] = ['ssid' => $ssid, 'password' => $keep && $security !== 'open' ? self::KEEP : $pw,
                'security' => $security, 'hidden' => $hidden === true];
        }
        return $out;
    }

    /**
     * Fills in Wi-Fi passwords marked "keep" from the stored list (same network name).
     *
     * @throws Http\HttpError when there's no stored password to keep
     */
    private static function keepWifiPasswords(string $key, array $networks, mixed $stored): array
    {
        $old = [];
        foreach (is_array($stored) ? $stored : [] as $n) {
            if (is_array($n) && is_string($n['ssid'] ?? null) && is_string($n['password'] ?? null)) {
                $old[$n['ssid']] = $n['password'];
            }
        }
        foreach ($networks as $i => $n) {
            if ($n['password'] === self::KEEP) {
                if (!isset($old[$n['ssid']]) || $old[$n['ssid']] === '') {
                    throw Http\HttpError::validation([$key => "Enter the password for {$n['ssid']} (there's no saved one to keep here)."]);
                }
                $networks[$i]['password'] = $old[$n['ssid']];
            }
        }
        return $networks;
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
            if (is_array($v) && (self::SCHEMA[$k]['type'] ?? null) === 'wifi') {
                $v = self::keepWifiPasswords((string) $k, $v, $stored[$k] ?? null);
            }
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
        return self::toDevice(self::resolveForDevice($device)['values']);
    }

    /**
     * resolve() for a device row, plus release rings: unless the device itself
     * overrides updates.release, a tag with a ring (Releases::rings) sets it, and
     * its source reads "tag:<name>".
     *
     * @return array{values: array<string, mixed>, sources: array<string, string>}
     */
    public static function resolveForDevice(array $device): array
    {
        $resolved = self::resolve(
            self::profileSettings((int) $device['profile_id']),
            Db::decode($device['overrides'] ?? null)
        );
        if ($resolved['sources']['updates.release'] !== 'device' && Releases::rings() !== []) {
            $ring = Releases::ringFor(Devices::tagsOf($device));
            if ($ring !== null) {
                $resolved['values']['updates.release'] = $ring['release'];
                $resolved['sources']['updates.release'] = 'tag:' . $ring['tag'];
            }
        }
        return $resolved;
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

    /** Hides PIN hashes and secrets in admin output: they are replaced with {"set": true}. */
    public static function redact(array $flat): array
    {
        foreach (self::SCHEMA as $key => $def) {
            if ($def['type'] === 'pin' && isset($flat[$key]) && is_array($flat[$key])) {
                $flat[$key] = ['set' => true];
            } elseif ($def['type'] === 'secret' && isset($flat[$key]) && is_string($flat[$key]) && $flat[$key] !== '') {
                $flat[$key] = ['set' => true];
            } elseif ($def['type'] === 'wifi' && isset($flat[$key]) && is_array($flat[$key])) {
                foreach ($flat[$key] as $i => $n) {
                    if (is_array($n) && is_string($n['password'] ?? null) && $n['password'] !== '') {
                        $flat[$key][$i]['password'] = ['set' => true];
                    }
                }
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
