# Kiosky management protocol

This is the contract between Kiosky Shepherd and the Kiosky Android app
(`uk.lp0.ha`). It describes what the app must do to be **managed**: enroll with
a server, take its settings only from that server, lock local controls, run
remote commands and install server-chosen updates.

Version: **1**. All endpoints are under `/api/v1/device/`.

## 1. Transport

- HTTPS only. Kiosky already sets `usesCleartextTraffic="false"`.
- Request and response bodies are UTF-8 JSON (`Content-Type: application/json`).
- After enrollment every request carries
  `Authorization: Bearer <device_token>`.
- Send `User-Agent: Kiosky/<versionName>` as `Updater` already does.
- Errors always look like this:

  ```json
  { "error": { "code": "device_unauthorized", "message": "Human readable text", "details": {} } }
  ```

| HTTP | `error.code` | What the app does |
| --- | --- | --- |
| 400, 422 | `bad_request`, `validation_failed` | Bug or bad input. Log it, keep going. |
| 401 | `device_unauthorized` | Credentials not accepted. **Stay locked down** with the cached policy and keep retrying with backoff. Show "Can't reach management server" in the PIN-unlocked toolbar. Never unlock because of a 401. |
| 403 | `enrollment_invalid` | Only from `/enroll`: the code is wrong, used up, revoked or expired. |
| 410 | `device_retired` | An admin removed this device. **Leave managed mode** (see §7). |
| 429 | `rate_limited` | Wait at least 60 s before retrying. Each device may make 120 check-ins, 300 command results and 700 live-view frames per 10 minutes, and 30 release downloads per hour. |
| 5xx / network error | | Keep the cached policy, retry with backoff (§4.4). |

## 2. Stored state on the device

Keep this in a private `SharedPreferences` file (for example `managed`), separate
from the existing `kiosk` file:

| Key | Meaning |
| --- | --- |
| `server` | Base URL, e.g. `https://kiosks.example.com` (no trailing slash) |
| `device_token` | `<uid>.<secret>` from enrollment. Treat as a password. |
| `device_id` | The `uid` part, for display |
| `name` | Device name from the server, for display |
| `policy` | The last policy JSON received, verbatim |
| `done_commands` | The ids of the last ~100 commands executed (for de-duplication) |

**Managed mode is on whenever `device_token` is set.** Set `android:allowBackup="false"`
(already the case) so credentials never leave the device.

## 3. Enrollment

### 3.1 Getting the server and code

Admins create enrollment codes in the console. Each code is shown three ways:

1. A **QR code** containing an enrollment URI
2. The same URI as a link:
   `kiosky://enroll?server=https%3A%2F%2Fkiosks.example.com&token=ABCD2EFGH3JK`
3. The **server address** and the **code** (`ABCD-2EFG-H3JK`) to type by hand

The app should:

- Register an intent filter for the `kiosky://enroll` scheme so links and QR
  scanner apps can open it.
- Add an **Enroll** button to the toolbar while unmanaged. It opens a small form
  with *Server* and *Code* fields and, optionally, a scan button that launches
  any installed QR scanner (`com.google.zxing.client.android.SCAN` intent) and
  accepts its `kiosky://enroll…` result.
- Prefix `https://` when the typed server has no scheme. Refuse `http://`.
- Codes are case-insensitive. The server ignores spaces and dashes, and reads
  `I`/`L` as `1` and `O` as `0`.
- Ask for confirmation first ("Let *kiosks.example.com* manage this tablet? Its
  settings will be locked."), because enrollment hands control to that server.

### 3.2 `POST /api/v1/device/enroll`

No `Authorization` header. Rate-limited per IP (20 tries per 10 minutes).

```json
{
  "token": "ABCD-2EFG-H3JK",
  "device": {
    "name": "Optional suggested name",
    "android_id": "9774d56d682e549c",
    "manufacturer": "samsung",
    "model": "SM-X200",
    "android_version": "14",
    "sdk_int": 34,
    "app_version": "1.1.0",
    "app_version_code": 10100
  }
}
```

`android_id` is `Settings.Secure.ANDROID_ID`. It survives reinstalling the app on
Android 8+, so the server uses it to recognise a tablet that is enrolling again
and keep its name, overrides and history.

**201 Created**

```json
{
  "device_id": "k3v9x2m8q1w7r4t6",
  "device_token": "k3v9x2m8q1w7r4t6.R2l1c3Ryb25nLXJhbmRvbS1zZWNyZXQtaGVyZQ",
  "name": "Lobby-001",
  "server_time": "2026-11-08T10:00:00+00:00",
  "policy": { "...": "see §5" },
  "update": null,
  "commands": [],
  "checkin_seconds": 60
}
```

Store `server`, `device_token`, `device_id`, `name` and `policy`, apply the
policy immediately (§5), then start the check-in loop (§4).

### 3.3 QR provisioning (Kiosky 1.7.0+)

A factory-reset tablet can be set up with no computer. On the first setup screen,
tap six times in the same spot; Android opens a QR scanner. The console's
**Provisioning QR** encodes Android's provisioning extras:

```json
{
  "android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME": "uk.lp0.ha/kiosky.AdminReceiver",
  "android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION": "https://kiosks.example.com/api/v1/provisioning/ABCD2EFGH3JK/kiosky.apk",
  "android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM": "<base64url SHA-256 of the APK>",
  "android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED": true,
  "android.app.extra.PROVISIONING_SKIP_ENCRYPTION": true,
  "android.app.extra.PROVISIONING_WIFI_SSID": "Office",
  "android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE": "WPA",
  "android.app.extra.PROVISIONING_WIFI_PASSWORD": "…",
  "android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE": { "server": "https://kiosks.example.com", "token": "ABCD2EFGH3JK" }
}
```

- The enrollment code list includes this object as `provisioning` (without Wi-Fi,
  which the console adds in the browser so the password never reaches the server),
  or `null` when there's nothing to provision with.
- `GET /api/v1/provisioning/{code}/kiosky.apk` returns the newest stable release.
  There's no device yet, so the enrollment code (active, not used up or expired)
  is the credential; requests are rate-limited per IP. `provisioning.apk_url` in
  `config.php` points the QR at another download instead.
- When this server serves the APK, the QR carries `PACKAGE_CHECKSUM`: the
  release's SHA-256, base64url without padding. It matches whichever signature a
  tablet checks, which matters because Kiosky 1.7.0+ is signed with key rotation
  (the original key for Android 5.1–8.1, the release key for 9+). With
  `provisioning.apk_url`, the QR carries `SIGNATURE_CHECKSUM` instead: the release
  key's certificate (`5D43Qjavxg_OZaPbJfD5a9QWhnhi6drJw8uro0Zl9I8`), or
  `provisioning.signature_checksum` for builds signed with another key. That only
  suits Android 9+ tablets.
- Android installs Kiosky as device owner. On Android 12+ it asks Kiosky's
  `GET_PROVISIONING_MODE` activity (answer: fully managed) and
  `ADMIN_POLICY_COMPLIANCE` activity. Kiosky stores the admin extras, turns on
  start on boot, then enrolls with `server` and `token` by itself as soon as the
  network is up (retrying every minute), without the usual confirmation.

## 4. Check-in

### 4.1 `POST /api/v1/device/checkin`

The app's heartbeat. Send one right after enrollment, every
`checkin_seconds` after that, on app start, when the network comes back, and
straight after finishing any command.

```json
{
  "app_version": "1.1.0",
  "app_version_code": 10100,
  "policy_version": "3f9a1c0d7e2b4a56",
  "status": {
    "current_url": "https://ha.example.com/lovelace/0",
    "page_title": "Home",
    "page_error": null,
    "screen_on": true,
    "battery_level": 87,
    "charging": true,
    "start_on_boot_permission": true,
    "install_permission": true,
    "uptime_seconds": 86400,
    "app_uptime_seconds": 3600,
    "webview_version": "130.0.6723.58",
    "orientation": "landscape",
    "screen": "1920x1200",
    "local_ip": "192.168.1.42",
    "wifi_rssi": -54
  },
  "events": [
    { "type": "page_error", "at": "2026-11-08T09:59:12Z", "data": { "code": -2, "url": "https://ha.example.com/" } }
  ]
}
```

- `policy_version` is the `version` of the policy the app has **applied**. The
  console uses it to show "Applied" or "Pending".
- `status` is free-form; the server stores the latest copy (up to 16 KB) and
  shows every key on the device page. The keys above are the ones the console
  understands: `start_on_boot_permission` is `Settings.canDrawOverlays()` on
  Android 10+ (`true` below 10), and `install_permission` is
  `canRequestPackageInstalls()`. When either is `false` the overview flags the
  device, because start on boot or updates won't work until someone grants it.
  Kiosky 1.2.0+ also sends the lockdown keys below (§5.4); the overview flags a
  device whose lockdown can be escaped.

  | Key | Meaning |
  | --- | --- |
  | `lockdown` | Lockdown is wanted (policy `lockdown`, or the local toggle when unmanaged) |
  | `lockdown_mode` | `owner` (Kiosky is device owner), `mdm` (an MDM allows Kiosky in lock task mode) or `partial` |
  | `lock_task` | The app is in lock task mode or screen pinning right now |
  | `device_owner` | Kiosky is the device owner |
  | `accessibility_helper` | Kiosky's accessibility helper is running (partial lockdown) |
  | `default_home` | Kiosky is the default Home app |

  Kiosky 1.7.0+ also sends these. The overview flags `battery_temperature` at or
  above `alerts.battery_hot` (45 °C by default) and a failing `battery_health`.

  | Key | Meaning |
  | --- | --- |
  | `battery_temperature` | Battery temperature in °C (one decimal) |
  | `battery_health` | `good`, `overheat`, `dead`, `over_voltage`, `failure`, `cold` or `unknown` |
  | `battery_full_hours` | Whole hours the battery has sat at 100% on charge while Kiosky ran |
  | `battery_protect` | Charging limit (`battery.protect`): `on`, `off`, `no_permission` or `unsupported` |
  | `storage_free_mb`, `storage_total_mb` | App storage volume |
  | `memory_free_mb`, `memory_total_mb`, `memory_low` | System memory (`ActivityManager.MemoryInfo`) |
  | `app_memory_mb` | Kiosky's own Java heap in use |
  | `screen_schedule` | `off` or `on`, when a screen schedule is set |
  | `screen_state` | `normal`, `dim` or `off` |
  | `camera_permission`, `microphone_permission` | Android's camera / microphone permission is granted |
  | `offline_screen` | The "Reconnecting…" screen is covering the page |
  | `screensaver` | The screensaver is showing |
  | `reboot_supported` | `reboot` and `reboot_at` reboot the tablet (device owner, Android 7+) |
  | `volume_percent` | Media volume, 0–100 |
  | `playlist_index` | With a playlist: -1 on the start page, otherwise the playlist page shown |

  Kiosky 1.8.0+ also sends these. The overview flags `safe_mode` / an unhealthy
  `update_health`, and `wifi_config` values `reverted`, `failed` and `not_device_owner`.

  | Key | Meaning |
  | --- | --- |
  | `mqtt` | `off`, `connecting`, `connected` or `error: …` |
  | `wifi_config` | `off`, `pending`, `applied`, `reverted`, `failed` or `not_device_owner` |
  | `wifi_ssid` | The Wi-Fi network in use, when Android reveals it (a device owner grants itself the location permission once the policy has networks) |
  | `update_health` | `trial`, `healthy` or `unhealthy`, after an update |
  | `safe_mode` | This version crashed right after updating; optional features are off |
- `events`: anything that happened since the last successful check-in, oldest
  first, at most 50. Keep them queued locally until a check-in succeeds.
  Suggested types: `page_error`, `page_loaded`, `policy_applied`,
  `update_started`, `update_failed`, `app_started`, `crash`. Kiosky also sends
  `navigation_blocked`, `pin_failed`, and `lockdown_on` / `lockdown_off` (with
  `mode` and, when the policy changed it, `source: "policy"`). Kiosky 1.7.0+ adds
  `renderer_crashed` (`crashed`, `url`, `looping`), `watchdog_reload`,
  `watchdog_rebuild`, `idle_home`, `screen_schedule` (`state`), `media_denied`
  (`origin`, `reason`), `js_dialog` (`type`, `message`), `battery_hot` /
  `battery_cool` (`temperature`, `level`, `charging`) and `battery_protect`
  (`state`), `crash` (`thread`, `message`, `stack`, `app_version`, `at_ms`: sent at
  the next start), `certificate_untrusted` (`url`, `host`, `fingerprint`, `error`,
  `subresource`), `screensaver` (`state`), `rebooting` / `restarting` (`reason`) and
  `reboot_failed`, and sends `page_error` for main-frame HTTP errors too, with the
  HTTP status as `code`.
  Kiosky 1.8.0+ adds `session_cleared` (`reason`: `idle` or `mqtt`, `url`),
  `update_unhealthy` / `update_healthy` (`version`, `version_code`,
  `previous_version`, `previous_version_code`, `crashes`, and `last_crash` when
  unhealthy), `wifi_changed` / `wifi_failed` (`networks`, `failed`), `wifi_applied`
  (`networks`, `removed`, `ssid`) and `wifi_reverted` (`restored`, `networks`).
  `app_started` carries `safe_mode: true` in safe mode.

**200 OK**

```json
{
  "server_time": "2026-11-08T10:00:00+00:00",
  "device": { "id": "k3v9x2m8q1w7r4t6", "name": "Lobby-001" },
  "policy": { "...": "see §5" },
  "update": {
    "version": "1.2.0",
    "version_code": 10200,
    "size": 48213,
    "sha256": "9c1e…",
    "url": "https://kiosks.example.com/api/v1/device/releases/4/download"
  },
  "commands": [
    { "id": 812, "type": "reload", "params": {}, "created_at": "…", "expires_at": "…" }
  ],
  "checkin_seconds": 60
}
```

### 4.2 Applying the response

1. If `policy.version` differs from the cached one, store the new policy and
   apply it (§5). Save it before applying, so a crash mid-apply re-applies it on
   restart.
2. If `device.name` changed, store it.
3. Handle `update` (§6).
4. Run `commands` in order (§7).
5. Schedule the next check-in after the response's top-level `checkin_seconds`,
   not `policy.checkin_seconds`. They are usually equal, but while an admin has
   the device's page open the server lowers the top-level value (to 15 s by
   default) for a couple of minutes, so commands arrive quickly. This doesn't
   change the policy `version`.

### 4.3 Doing this on Android

The existing `Handler` pattern in `MainActivity` (as used for `autoCheck`) works
while the activity is alive, which for a kiosk is nearly always. Run network
calls on a background thread, as `Updater` does, and post results to the main
thread.

### 4.4 Backoff

On network errors, 5xx or 401: retry after 15 s, then double each time up to
`checkin_seconds × 10` (at most 10 minutes), with ±20 % jitter. Reset after a
success.

## 5. Policy and lockdown

### 5.1 The policy object

```json
{
  "managed": true,
  "organisation": "Example Ltd",
  "version": "3f9a1c0d7e2b4a56",

  "url": "https://ha.example.com/lovelace/0",
  "allowed_hosts": ["ha.example.com", "*.example.com"],
  "reload_minutes": 0,
  "retry_seconds": 10,
  "text_zoom": 100,
  "user_agent_suffix": "",
  "autoplay_media": true,
  "third_party_cookies": true,

  "start_on_boot": true,
  "keep_screen_on": true,
  "orientation": "auto",
  "allow_exit": false,
  "lockdown": false,

  "playlist": {
    "urls": ["https://ha.example.com/cameras", "https://calendar.example.com/"],
    "seconds": 60,
    "pause_on_touch_seconds": 120
  },

  "idle_home_minutes": 0,
  "watchdog_minutes": 2,
  "media": { "camera": true, "microphone": true },

  "screen": {
    "off_at": "22:00",
    "on_at": "07:00",
    "wake_on_touch": true,
    "wake_on_motion": false,
    "wake_minutes": 5,
    "dim_at": "",
    "dim_until": "",
    "dim_brightness": 15,
    "idle_dim_minutes": 0,
    "pixel_shift": false
  },

  "battery": { "protect": false },

  "file_uploads": true,
  "reboot_at": "",
  "http_auth": { "host": "", "username": "", "password": "" },
  "trusted_certificates": [],
  "screensaver": { "minutes": 0, "url": "" },
  "audio": { "volume": -1, "quiet_at": "", "quiet_until": "", "quiet_volume": 0 },

  "session": { "clear_on_idle": false },
  "offline": { "last_page": true },
  "touch": { "zoom": false, "long_press": true, "text_selection": true },
  "input": { "nfc": false, "barcode": true },
  "mqtt": {
    "host": "", "port": 1883, "tls": false, "fingerprint": "",
    "username": "", "password": "", "topic": "", "name": "",
    "discovery": true, "discovery_prefix": "homeassistant"
  },
  "wifi": {
    "networks": [ { "ssid": "Office", "password": "…", "security": "wpa2", "hidden": false } ]
  },

  "toolbar": {
    "mode": "hidden",
    "timeout_seconds": 15,
    "pin": { "algo": "pbkdf2-sha256", "iterations": 120000, "salt": "base64…", "hash": "base64…" }
  },

  "updates": {
    "source": "server",
    "channel": "stable",
    "release": "latest",
    "auto_install": true,
    "check_hours": 12,
    "rollout_hours": 0
  },

  "checkin_seconds": 60
}
```

`version` is a hash of everything else, so it changes exactly when the effective
policy changes. Ignore unknown keys so that newer servers keep working with
older apps. If a key is missing, use the default shown above.

### 5.2 How each key maps onto Kiosky

| Key | Enforcement in the app |
| --- | --- |
| `url` | The start page. Replaces the `url` preference in the `kiosk` prefs, which the app **must not** let the user change while managed. When it changes, load it at once. Empty: show a "Waiting for configuration from *organisation*" page instead of `help.md`. |
| `allowed_hosts` | In `shouldOverrideUrlLoading` (and `onPageStarted` for redirects), block navigation to any host that is not the `url` host and doesn't match an entry. `*.example.com` matches subdomains, not `example.com` itself. `host:port` entries must match the port too. Empty list: allow any `https` host. Blocked navigations are dropped and reported as a `navigation_blocked` event. |
| `reload_minutes` | If > 0, call `web.reload()` on that interval. |
| `retry_seconds` | Replaces `RETRY_MS` (currently 10 s). |
| `text_zoom` | `WebSettings.setTextZoom()`. |
| `user_agent_suffix` | `setUserAgentString(default + " " + suffix)` when not empty. |
| `autoplay_media` | `setMediaPlaybackRequiresUserGesture(!autoplay_media)`. |
| `third_party_cookies` | `CookieManager.setAcceptThirdPartyCookies(web, value)`. |
| `start_on_boot` | Replaces the `start_on_boot` preference read by `BootReceiver`. Hide the toolbar toggle while managed. If `true` and `canDrawOverlays()` is false on Android 10+, report `start_on_boot_permission: false`. Only open the overlay-permission screen when the PIN unlocks the toolbar, never on its own. |
| `keep_screen_on` | Add or clear `FLAG_KEEP_SCREEN_ON` and `setKeepScreenOn()`. |
| `orientation` | `setRequestedOrientation()`: `auto` → `SCREEN_ORIENTATION_UNSPECIFIED` (-1), `portrait` → 1, `landscape` → 0, `reverse_portrait` → 9, `reverse_landscape` → 8. |
| `allow_exit` | If `false`, `onBackPressed()` on the first page does nothing instead of calling `super.onBackPressed()`. Kiosky 1.7.0+ also ignores Back on the first page while `lockdown` is on, since closing would end screen pinning. |
| `lockdown` | Keep the tablet locked to Kiosky (§5.4). Replaces the local Lockdown toggle while managed: the app turns lockdown on or off whenever this changes. The PIN-unlocked toolbar may show the lockdown checklist, but can't turn it off. Apps before 1.4.0 ignore it. |
| `toolbar.mode` | `hidden`: an edge swipe still reveals the system bars (Android always allows that) but the Kiosky toolbar stays hidden. `limited`: show only **Reload** and **Help**. `full`: also the address box and **Go**. Addresses typed in `full` mode are **temporary**: open them, but never save them; restart, `go_home` or a policy change returns to `url`. The **Update** and **Start on boot** buttons and the unmanaged **Enroll** button are never shown while managed. |
| `toolbar.timeout_seconds` | Replaces `BAR_TIMEOUT_MS` (currently 15 s). |
| `toolbar.pin` | `null`: no local unlock. Otherwise a technician can unlock the full toolbar (§5.3). |
| `updates.*` | See §6. |
| `checkin_seconds` | Interval of the check-in loop. |
| `playlist.urls`, `playlist.seconds`, `playlist.pause_on_touch_seconds` | Page playlist. When `urls` isn't empty, show `url` and then each playlist page in turn, `seconds` each, looping. A touch pauses the rotation for `pause_on_touch_seconds` (0: never pause), then it carries on from the current page. Playlist hosts are always allowed, like the start page's. `go_home` and policy changes restart at `url`. Implemented in Kiosky 1.7.0; older apps ignore these keys. |
| `file_uploads` | Let pages open Android's file picker for `<input type="file">`. Kiosky 1.7.0+. |
| `reboot_at` | Daily at this local time (`HH:MM`): reboot with `DevicePolicyManager.reboot()` as device owner on Android 7+, otherwise restart Kiosky fresh. Skipped within 30 minutes of starting. Kiosky 1.7.0+. |
| `http_auth.host`, `http_auth.username`, `http_auth.password` | Answer HTTP basic-auth prompts from `host` (empty: the `url` host) with these. A prompt that comes straight back (wrong details) is cancelled and reported as `page_error` code 401. The console never shows the password again (`{"set": true}`), but devices get it in clear. Kiosky 1.7.0+. |
| `trusted_certificates` | SHA-256 fingerprints (`AB:12:…`) of certificates to accept despite TLS errors (self-signed, private CA), only on the `url` host, `allowed_hosts` and admin-sent pages. Others are refused and reported as `certificate_untrusted` with the fingerprint. Kiosky 1.7.0+. |
| `screensaver.minutes`, `screensaver.url` | After this many minutes without a touch (or motion, with `wake_on_motion`), cover the page with a drifting clock, or with the https page `url` (kept to its own site). A touch closes it without reaching the page. Kiosky 1.7.0+. |
| `audio.volume`, `audio.quiet_at`, `audio.quiet_until`, `audio.quiet_volume` | Media volume in percent (-1: leave it), and a lower volume between the quiet times. Applied when the wanted level changes, so local adjustments hold until then. Kiosky 1.7.0+. |
| `screen.off_at`, `screen.on_at` | Screen schedule, in the tablet's local time (`HH:MM`, 24-hour). `off_at` empty: no schedule. Between `off_at` and `on_at` (wrapping past midnight when `on_at` is earlier) turn the screen off: clear `FLAG_KEEP_SCREEN_ON` and set the window's `screenBrightness` to 0 and show a black overlay, or call `DevicePolicyManager.lockNow()` when Kiosky is the device owner. At `on_at` restore brightness (and wake the screen with a `FULL_WAKE_LOCK`/`setTurnScreenOn` when it was locked), then reload the page. Report `screen_schedule: "off"` or `"on"` in `status`. Added in server 1.4.0; implemented in Kiosky 1.7.0. Kiosky keeps the screen on (black, brightness 0) while something can wake it; otherwise it lets it switch off and uses an alarm to wake it at `on_at`. |
| `screen.wake_on_touch`, `screen.wake_minutes` | While off for the schedule, a touch turns the screen on for `wake_minutes`, then it goes off again. The touch must not reach the page. Kiosky also wakes on five quick taps when `wake_on_touch` is off, so a technician can always get in. |
| `screen.wake_on_motion` | The proximity sensor ("near") or a clear jump on the light sensor counts like a touch for waking and for idle dimming, without touching the page. Kiosky 1.7.0+. |
| `screen.dim_at`, `screen.dim_until`, `screen.dim_brightness` | Between the two local times, set the window brightness to `dim_brightness` percent. Empty: no dimming. Kiosky 1.7.0+. |
| `screen.idle_dim_minutes` | Dim to `dim_brightness` after this many minutes without a touch (0: never). The first touch only brightens the screen. Kiosky 1.7.0+. |
| `idle_home_minutes` | After this many minutes without a touch, load `url` if the page isn't already it (0: never). "Already it" includes where `url` redirected to. Kiosky 1.7.0+. |
| `watchdog_minutes` | Every 15 s Kiosky runs `evaluateJavascript("1")`. With no answer for this long, reload; if the next check fails too, replace the WebView (0: off). Kiosky 1.7.0+. |
| `media.camera`, `media.microphone` | Grant a page's `onPermissionRequest` for video/audio capture when on, and only to https origins on the `url` host, matching an `allowed_hosts` entry, or an admin-sent `navigate` page. An empty `allowed_hosts` allows no further hosts here. Kiosky asks Android for CAMERA / RECORD_AUDIO when needed; as device owner it grants them itself. Kiosky 1.7.0+. |
| `session.clear_on_idle` | When `idle_home_minutes` returns to `url`, also clear cookies, Web Storage, the cache, form data and history, if the tablet was touched since the last clear. Reported as `session_cleared` (`reason`: `idle`). For shared, public tablets. Kiosky 1.8.0+. |
| `offline.last_page` | While the "Reconnecting…" cover is up, show a greyed-out picture of the last page that loaded properly on that host (taken 8 s after a good load and every 10 minutes, kept across restarts; not of a `session.clear_on_idle` tablet's other pages). Kiosky 1.8.0+. |
| `touch.zoom`, `touch.long_press`, `touch.text_selection` | Pinch to zoom (`setBuiltInZoomControls`); long presses (off: consumed, so no selection, its action menu or link previews); selecting page text (off: injected `user-select: none` except form fields). Kiosky 1.8.0+. |
| `screen.pixel_shift` | Every 3 minutes, translate the whole view along an 8-step circle of at most 2 px, against burn-in. Kiosky 1.8.0+. |
| `input.nfc`, `input.barcode` | NFC reader mode while in front (tag id, techs and NDEF records), and keyboard-style barcode scanners (a burst of keys under 60 ms apart ending in Enter or Tab). Scans go to trusted pages as `kiosky:nfc` / `kiosky:barcode` DOM events and to MQTT (`<base>/nfc`, `<base>/barcode`). Not reported to the server. Kiosky 1.8.0+. |
| `mqtt.*` | Local control over MQTT 3.1.1 (Kiosky 1.8.0+). `host` empty: off. `port` (1883; 8883 is usual with `tls`), `tls`, `fingerprint` (SHA-256 of a self-signed broker certificate, trusted instead of the system CAs and hostname check), `username`, `password` (redacted in the console, sent in clear to devices), `topic` (base topic; empty: `kiosky/` + the first 8 characters of `android_id`), `name` (Home Assistant device name; empty: the device's name), `discovery` and `discovery_prefix` (Home Assistant MQTT discovery). The topics and commands are listed in Kiosky's help (*Home Assistant and MQTT*). Commands that open pages still obey `allowed_hosts`. Reported as status `mqtt`. |
| `wifi.networks` | Wi-Fi networks to save on the tablet (Kiosky 1.8.0+), first preferred, at most 10: `ssid`, `password` (8–63 characters or 64 hex digits; empty for `open`), `security` (`wpa2`, `wpa3` or `open`), `hidden`. Needs device owner on Android 10+ (`WifiManager.addNetwork`). Empty list: leave Wi-Fi alone. New networks are added before old ones go; networks Kiosky saved that are no longer listed are removed once Wi-Fi works again, never the one in use. A changed password or security type is reverted if Wi-Fi isn't connected 10 minutes later (`wifi_reverted`), and that list isn't retried until it changes. The console redacts passwords as `{"set": true}`; sending that back (or `(saved)` in the text form) keeps the stored one. Reported as status `wifi_config`. |
| `battery.protect` | Turn on the tablet's own charging limit. Experimental: Samsung `Settings.Global protect_battery`, which needs `adb shell pm grant uk.lp0.ha android.permission.WRITE_SECURE_SETTINGS`. Reported as `battery_protect`. Kiosky only turns it off again if it turned it on. Kiosky 1.7.0+. |

### 5.3 Technician unlock (PIN)

When `toolbar.pin` is not null:

1. **Five taps in the top-left corner** (a 48 dp square) within 3 seconds open a
   PIN pad. Use a transparent view above the WebView that only consumes taps in
   that corner.
2. Check the entered digits with
   `PBKDF2WithHmacSHA256(pin, base64decode(salt), iterations, 256 bits)` and
   compare to `base64decode(hash)` in constant time. `SecretKeyFactory` only has
   `PBKDF2WithHmacSHA256` from Android 8, so Kiosky computes PBKDF2 itself on
   `Mac.getInstance("HmacSHA256")`, which works on every version it supports.
3. If they match, show the **full** toolbar plus a **Device info** button (server,
   device name and id, policy version, last check-in, permission status) and,
   where permissions are missing, buttons to open the relevant settings screens.
   It locks again when the toolbar hides.
4. After 5 wrong PINs, ignore PIN entry for 5 minutes and send a `pin_failed` event.

The PIN deters passers-by. Anyone who can root the tablet can brute-force a
short PIN from the stored hash, so admins should treat it accordingly.

### 5.4 Lockdown

With policy `lockdown: true`, the app keeps the tablet locked to Kiosky. How
completely depends on the device, reported as `lockdown_mode`:

- **`owner`**: Kiosky is the device owner (`adb shell dpm set-device-owner
  uk.lp0.ha/kiosky.AdminReceiver`). It whitelists itself for lock task mode with no
  features, disables the status bar and makes itself the persistent Home app.
  Nothing on the device leads out except Kiosky's own menu.
- **`mdm`**: another MDM is device owner and allows Kiosky in lock task mode
  (`isLockTaskPermitted`). Kiosky starts lock task; the MDM decides its features.
- **`partial`**: neither. Kiosky layers what Android allows: screen pinning, a
  status bar blocker (Android 5.1–7.1), closing the shade when it opens, returning
  to the front when left, Kiosky as the Home app, and an optional accessibility
  helper that keeps Kiosky in front and closes the shade on Android 12+. The power
  menu and lock screen still work, and the shade or launcher may flash briefly.

Kiosky's own menu (an edge swipe, or five taps in the top-left corner) is the only
way out. While managed, only the PIN-unlocked toolbar can open the settings screens
that strengthen a partial lockdown.

### 5.5 Page bridge (Kiosky 1.7.0+)

Kiosky gives pages a `window.kiosky` object, so a dashboard can react to its own
sensors (for example a Home Assistant motion sensor):

| Method | Who may call it | Effect |
| --- | --- | --- |
| `wake()` | Any page | As `wake_screen` |
| `isScreenOff()` | Any page | `true` while the screen is off |
| `version()` | Any page | Kiosky's version name |
| `reload()` | Trusted page | Reloads the page |
| `setBrightness(percent)` | Trusted page | 1–100 until changed; 0 or less: the tablet's own brightness |
| `screenOff()` | Trusted page | Black screen until a touch, `wake()`, `wake_screen` or motion |
| `deviceName()` | Trusted page | The Shepherd device name (`""` otherwise) |
| `speak(text)` | Trusted page | Reads the text aloud (Kiosky 1.8.0+) |
| `battery()` | Trusted page | JSON text `{level, charging, temperature}`, or `null` (1.8.0+) |
| `setUrl(url)` | Trusted page | Opens an http(s) address until the next return to `url`; `allowed_hosts` still applies (1.8.0+) |
| `watchMotion(on)` | Trusted page | Sends `kiosky:motion` events (`detail.source`: `proximity` or `light`) until the page navigates; `false` without a sensor (1.8.0+) |
| `nfcAvailable()` | Any page | `true` while `input.nfc` is on and NFC is switched on (1.8.0+) |

Kiosky 1.8.0+ also dispatches DOM events on trusted pages: `kiosky:motion` (after
`watchMotion(true)`), `kiosky:nfc` (`detail`: `id`, `techs`, `records`) and
`kiosky:barcode` (`detail.code`).

A trusted page is one whose address is on the `url` host or an `allowed_hosts`
entry, or an admin-sent `navigate` page. Methods return `false` when refused.
Frames inside a trusted page share the object, so only allow hosts you trust.

## 6. Updates

| `updates.source` | Behaviour |
| --- | --- |
| `server` | Use the `update` object from check-in. Do **not** contact GitHub. |
| `github` | Kiosky's existing `Updater.check()` against GitHub, every `check_hours`. When `channel` is `dev`, also consider GitHub releases marked as pre-releases (newest version wins); when `stable`, ignore them. |
| `off` | Never check, never install. |

`channel` (`stable` or `dev`, added in server 1.5.0) is resolved by the server for
the `server` source: the `update` object already is the right build, and its
`channel` field says which kind it is (show "dev build" next to the version in the
PIN-unlocked toolbar if you like). Apps that don't know the key can ignore it.

For `server`:

- If `update` is not null and `update.version_code` is greater than the app's own
  `versionCode`, download `update.url` **with the `Authorization` header** (the
  download needs device auth), check that the SHA-256 of the bytes equals
  `update.sha256` and abort if not, then hand the file to the existing
  `PackageInstaller` session code in `Updater.install()`.
- If `auto_install` is true, start straight away without the "Later" bar.
  Otherwise show the existing update bar.
- Android shows its own confirmation unless Kiosky is the device owner, and the
  first time it needs "Install unknown apps" (`install_permission`). Report
  `update_started` and `update_failed` events so admins can see what happened.
- Never try to downgrade. Android refuses, and the server only targets newer
  builds anyway.
- Don't retry a failed install of the same `version_code` more than once an hour.

**Staged rollouts** (server 1.8.0+). A release can have `rollout_hours`: each device
gets a fixed place in the queue (from a hash of its id), and the release is offered
to it once that share of the time has passed since the rollout started. Until then,
`update` is the newest release that has reached the device, so devices keep moving
up. A **paused** release isn't offered to anyone who doesn't have it yet.

**Update health** (Kiosky 1.8.0+). For 15 minutes after a new version code first
starts, Kiosky counts uncaught crashes. Two make it unhealthy: it sends
`update_unhealthy` and runs in safe mode (status `safe_mode: true`) until a newer
version arrives. Surviving the trial sends `update_healthy`. The server counts both
per release, and pauses the release's rollout after `rollouts.halt_after` (in
`config.php`, default 1) unhealthy reports, with an alert and a `release.halted`
event webhook. Android can't downgrade, so to roll back, upload the previous
version rebuilt with a higher `versionCode`.

For `github`, `rollout_hours` in the policy does the same on the device: with
`auto_install`, each tablet installs a release at its own point within that many
hours of its `published_at`. A `check_update` command skips the wait.

## 7. Commands

Commands arrive in check-in responses. Run them in order, then report each one.

**Commands can be delivered more than once.** If a result doesn't reach the
server, the command is re-sent after 5 minutes. Keep the ids of recent commands
(`done_commands`), and if an id comes back, re-send its result without running
it again.

| `type` | `params` | Action |
| --- | --- | --- |
| `reload` | — | `web.reload()` |
| `go_home` | — | Load the policy `url` |
| `navigate` | `url` (https) | Load `url` once. Not saved; `allowed_hosts` is **not** applied to admin-sent URLs. |
| `restart_app` | — | Report the result first, then restart the activity (`recreate()` or relaunch via `AlarmManager`/`startActivity` + `finish()`). Kiosky 1.7.0+ loads `url` fresh instead of restoring the old page. |
| `announce` | `text`, `volume` (optional) | Read `text` aloud with Android's text-to-speech, at `volume` percent if given (restored afterwards). Fails if the tablet has no text-to-speech engine. Kiosky 1.7.0+. |
| `reboot` | — | Report the result, then reboot after a few seconds. Device owner on Android 7+ only; otherwise `failed`. Kiosky 1.7.0+. |
| `wake_screen` | `minutes` (optional) | Wake the screen for `minutes` (default `screen.wake_minutes`) if it's off for the schedule. Kiosky 1.7.0+. |
| `clear_cache` | — | `web.clearCache(true)`, then reload |
| `clear_web_data` | — | `CookieManager.removeAllCookies()`, `WebStorage.getInstance().deleteAllData()`, `clearCache(true)`, `clearHistory()`, then load `url`. Signs the kiosk out of its sites. |
| `check_update` | — | Run a check now with the current `updates.source` |
| `show_message` | `text`, `seconds` | Show `text` large and centred over the page for `seconds`, with a dismiss button |
| `identify` | `seconds` | Show the device name and id full-screen for `seconds` |
| `screenshot` | `max_width`, `quality` | Capture the WebView (`web.draw()` into a `Bitmap`), scale it to at most `max_width`, encode as JPEG at `quality`, and send it in the result |
| `diagnostics` | `lines` | Collect recent troubleshooting data: the app's log (up to `lines` lines of its own logcat output), WebView console errors, the current policy version, device and app versions, storage and memory. Put it in a text file, gzip it (or zip several files), and send it as `"bundle": "<base64>"` in the result (at most 10 MB decoded). Don't include page content, cookies or the device token. |
| `live_view` | `seconds`, `interval`, `max_width`, `quality` | Report `done` straight away, then for `seconds` capture a frame as for `screenshot` every `interval` seconds and `POST /api/v1/device/live` it (below). A newer `live_view` replaces a running one; `seconds: 0` stops it. Stop early on 4xx answers. Added in server 1.4.0. |

Kiosky 1.7.0 implements `diagnostics` (a gzipped text file: versions, the status
report, page console warnings and errors, the last crash and its own log) and
`live_view`, and captures `screenshot` and live frames from the whole screen,
including anything over the page (offline screen, help, screensaver).

Unknown command types must be reported as `failed` with message
`"unsupported command"`.

### `POST /api/v1/device/commands/{id}/result`

```json
{ "status": "done", "message": "optional text", "data": { "optional": "object, ≤ 8 KB" } }
```

For `screenshot`, add `"screenshot": "<base64 JPEG, PNG or WebP>"` (at most
4 MB decoded by default). The response is `{"ok": true, "status": "done"}`.
Reporting a finished command again is harmless.

### `POST /api/v1/device/live`

```json
{ "screenshot": "<base64 JPEG, PNG or WebP>" }
```

One live-view frame. Each replaces the previous one; the server keeps only the
latest, and the console polls it every 2 s while someone watches. Limited to 700
frames per 10 minutes per device. Answers `{"ok": true}`.

## 8. Leaving managed mode

The app leaves managed mode **only** when the server answers **410
`device_retired`**. That happens after an admin retires the device. Then:

1. Delete the `managed` preferences (token, policy, queue).
2. Keep the current `url` as an ordinary local setting, so the screen doesn't change.
3. Restore the normal unmanaged toolbar.
4. Show "This tablet is no longer managed by *organisation*" for a few seconds.

No other route leads out of managed mode: not a 401, not a network failure, and
nothing on the device short of clearing the app's data. That is the point of
lockdown. Clearing app data or reinstalling does release the device, so for
tamper resistance combine Shepherd with app pinning or a device-owner setup.

## 9. Release downloads

`GET /api/v1/device/releases/{id}/download` with the device's `Authorization`
header returns the APK (`application/vnd.android.package-archive`) with
`Content-Length` and `X-Checksum-SHA256` headers. Retired devices get 410.

## 10. Example session

```text
→ POST /api/v1/device/enroll     {"token":"ABCD-2EFG-H3JK","device":{…}}
← 201 {"device_token":"k3v9….R2l1…","policy":{"version":"3f9a…",…},…}

→ POST /api/v1/device/checkin    Authorization: Bearer k3v9….R2l1…
                                 {"policy_version":"3f9a…","status":{…}}
← 200 {"policy":{"version":"3f9a…"},"commands":[{"id":812,"type":"screenshot","params":{"max_width":1280,"quality":70}}],…}

→ POST /api/v1/device/commands/812/result   {"status":"done","screenshot":"/9j/4AAQ…"}
← 200 {"ok":true,"status":"done"}

   … admin retires the device …

→ POST /api/v1/device/checkin
← 410 {"error":{"code":"device_retired",…}}     → app leaves managed mode
```
