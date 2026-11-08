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
| 429 | `rate_limited` | Wait at least 60 s before retrying. |
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
- `events`: anything that happened since the last successful check-in, oldest
  first, at most 50. Keep them queued locally until a check-in succeeds.
  Suggested types: `page_error`, `page_loaded`, `policy_applied`,
  `update_started`, `update_failed`, `app_started`, `crash`.

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
5. Schedule the next check-in after `checkin_seconds`.

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

  "toolbar": {
    "mode": "hidden",
    "timeout_seconds": 15,
    "pin": { "algo": "pbkdf2-sha256", "iterations": 120000, "salt": "base64…", "hash": "base64…" }
  },

  "updates": {
    "source": "server",
    "release": "latest",
    "auto_install": true,
    "check_hours": 12
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
| `allow_exit` | If `false`, `onBackPressed()` on the first page does nothing instead of calling `super.onBackPressed()`. |
| `toolbar.mode` | `hidden`: an edge swipe still reveals the system bars (Android always allows that) but the Kiosky toolbar stays hidden. `limited`: show only **Reload** and **Help**. `full`: also the address box and **Go**. Addresses typed in `full` mode are **temporary**: open them, but never save them; restart, `go_home` or a policy change returns to `url`. The **Update** and **Start on boot** buttons and the unmanaged **Enroll** button are never shown while managed. |
| `toolbar.timeout_seconds` | Replaces `BAR_TIMEOUT_MS` (currently 15 s). |
| `toolbar.pin` | `null`: no local unlock. Otherwise a technician can unlock the full toolbar (§5.3). |
| `updates.*` | See §6. |
| `checkin_seconds` | Interval of the check-in loop. |

### 5.3 Technician unlock (PIN)

When `toolbar.pin` is not null:

1. **Five taps in the top-left corner** (a 48 dp square) within 3 seconds open a
   PIN pad. Use a transparent view above the WebView that only consumes taps in
   that corner.
2. Check the entered digits with
   `PBKDF2WithHmacSHA256(pin, base64decode(salt), iterations, 256 bits)` and
   compare to `base64decode(hash)` in constant time. `javax.crypto.SecretKeyFactory`
   provides this on every Android version Kiosky supports.
3. If they match, show the **full** toolbar plus a **Device info** button (server,
   device name and id, policy version, last check-in, permission status) and,
   where permissions are missing, buttons to open the relevant settings screens.
   It locks again when the toolbar hides.
4. After 5 wrong PINs, ignore PIN entry for 5 minutes and send a `pin_failed` event.

The PIN deters passers-by. Anyone who can root the tablet can brute-force a
short PIN from the stored hash, so admins should treat it accordingly.

### 5.4 Things the app cannot lock

Without being the *device owner* (Android Enterprise), an app can't block the
system bars, the Home and Recents buttons, or Settings. Recommend Android
**app pinning**, as the help page already does. A future protocol version may add
`lock_task` for device-owner installs.

## 6. Updates

| `updates.source` | Behaviour |
| --- | --- |
| `server` | Use the `update` object from check-in. Do **not** contact GitHub. |
| `github` | Kiosky's existing `Updater.check()` against GitHub, every `check_hours`. |
| `off` | Never check, never install. |

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
| `restart_app` | — | Report the result first, then restart the activity (`recreate()` or relaunch via `AlarmManager`/`startActivity` + `finish()`). |
| `clear_cache` | — | `web.clearCache(true)`, then reload |
| `clear_web_data` | — | `CookieManager.removeAllCookies()`, `WebStorage.getInstance().deleteAllData()`, `clearCache(true)`, `clearHistory()`, then load `url`. Signs the kiosk out of its sites. |
| `check_update` | — | Run a check now with the current `updates.source` |
| `show_message` | `text`, `seconds` | Show `text` large and centred over the page for `seconds`, with a dismiss button |
| `identify` | `seconds` | Show the device name and id full-screen for `seconds` |
| `screenshot` | `max_width`, `quality` | Capture the WebView (`web.draw()` into a `Bitmap`), scale it to at most `max_width`, encode as JPEG at `quality`, and send it in the result |

Unknown command types must be reported as `failed` with message
`"unsupported command"`.

### `POST /api/v1/device/commands/{id}/result`

```json
{ "status": "done", "message": "optional text", "data": { "optional": "object, ≤ 8 KB" } }
```

For `screenshot`, add `"screenshot": "<base64 JPEG, PNG or WebP>"` (at most
4 MB decoded by default). The response is `{"ok": true, "status": "done"}`.
Reporting a finished command again is harmless.

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
