# PiPusher

Self-hosted digital signage inspired by PiSignage and Yodeck.

- **Server**: a PHP web application, with SQLite and no other dependencies. Upload it to any PHP host. The admin panel controls every screen.
- **Client**: a single Go binary on a Raspberry Pi. It downloads and caches content from the server and serves a local player that Chromium shows full screen in kiosk mode. **If the server can't be reached, it keeps playing from its cache**, even after a reboot.
- **Web player** (online only): any browser can be a screen by opening a secret link. It needs no client and no login, but it has no offline cache.

```
 ┌──────────── PHP server ────────────┐          ┌──────────── Raspberry Pi ─────────────┐
 │ Admin UI: screens, layouts, media  │  sync    │ pipusher agent (systemd)              │
 │ api.php  ◄──────────────────────────┼──────────┤  • register / heartbeat / config      │
 │ SQLite + data/media                │  files   │  • download + verify (SHA-256) → cache│
 └────────────────────────────────────┘ ───────► │  • local player http://127.0.0.1:8090 │
                                                 │ Chromium --kiosk ──► player           │
                                                 └───────────────────────────────────────┘
```

## Concepts

| Thing | What it is |
|---|---|
| **Screen group** | Screens managed together. Each member either **follows the group** (shows the group's playlist or layout, so you change it once for all) or runs **its own** content. You can mix both in one group. The group page shows every member's screenshot and sends reload, sync, screenshot or reboot to all members at once. Deleting a group keeps the screens and their current content. New screens can join a default group automatically (Settings). |
| **Screen** | A Raspberry Pi running the client. It registers itself and is approved in the admin panel, or approved automatically with the registration key. A **web screen** is a browser that plays from the server instead (see *Web player*). |
| **Playlist** | A queue of layouts that plays top to bottom and repeats. Each entry has a duration and optional rules: **weekdays**, a **time window** (may cross midnight), and a **date range**, either one-off (events) or **repeating every year** (seasons, holidays). Entries whose rules don't match are skipped, and a fallback layout plays when nothing matches. Each screen checks the rules on its own clock, also offline. A screen shows either a playlist or a single layout. |
| **Layout** | The screen's design: **orientation** (landscape, portrait, or either one flipped), background colour, and one or more **zones** (windows), each positioned in % of the screen. |
| **Zone playlist** | Each zone rotates through its own list of content, each item with a duration. A duration of 0 on a video plays it to the end. |
| **Media** | HTML pages (written in the built-in editor or uploaded), **ZIP bundles** (HTML + CSS/JS/images), images, videos, and links (external URLs). Organised in nestable **folders**; select items to move them between folders, and search across all folders. Deleting a folder moves its contents up a level and deletes nothing. |
| **Users** | **Administrators** have full control, including managing users. **Playlist editors** only see Playlists: they can add, remove, reorder and re-time layouts in playlists and preview them, either in all playlists or only in selected ones. They cannot create or delete playlists or touch screens, layouts, media or settings. Everyone can change their own password and turn on **two-factor authentication** under their account (👤). See *Two-factor authentication*. |

Portrait is handled by rotating the player, so you don't need to change the Pi's display settings. Dynamic pages are ordinary HTML/JS. The *Weather* sample shows how to fetch live data and fall back to the last values while offline. External URLs are skipped while the screen is offline.

Remote commands from the admin panel: **reload player**, **sync now**, **reboot**, **take screenshot**. Screens report their status: online/offline, IP, model, CPU temperature, uptime, free disk space, cache size and last error.

**Screenshots**: each screen uploads a screenshot every *N* minutes (Settings, default 10; 0 = on demand only). The **Take screenshot now** button on a screen's page requests one immediately, and the page updates by itself when it arrives. Thumbnails appear in the Screens list. The agent captures the page through the kiosk Chromium's DevTools port, which listens on `127.0.0.1:9222` only. Portrait captures are rotated back upright and scaled to at most 1280 px.

## Building (Windows)

```powershell
.\setup-tools.ps1     # downloads Go and PHP into .\tools (already done)
.\build.ps1 -Bump patch   # bug fix:     1.1.0 -> 1.1.1
.\build.ps1 -Bump minor   # new feature: 1.1.0 -> 1.2.0
.\build.ps1               # rebuild the current version
```

The `VERSION` file is the single source of the version number. The build stamps it into the server (`server/inc/version.php`, shown in Settings), the client binaries (shown on the screen page), and every file name in `dist\`. Builds of older versions stay in `dist\`.

- `pipusher-client-<ver>.tar.gz` contains `pipusher-linux-arm64` (64-bit Pi OS), `pipusher-linux-armv7` (32-bit Pi OS) and `pipusher-linux-amd64`, plus the install scripts.
- `pipusher-client-windows-<ver>.zip` is the Windows 10/11 screen client (see *Windows screen*).
- `pipusher-client-windows7-8-<ver>.zip` is the same client for Windows 7/8/8.1, 32- and 64-bit. Go 1.21+ only builds for Windows 10/11, so it is built with Go 1.20 (`tools\go1.20`, `client\go.legacy.mod`). The client code must therefore stay Go 1.20 compatible (see `client\compat.go`).
- `pipusher-server-<ver>.zip` is the PHP application.
- `pipusher-windows-amd64-<ver>.exe` is the client for local testing.

**Updating:** upload the new server files over the old ones; `data/` and `config.php` are not in the zip, so your content stays. On each Pi, unpack the new client package and run `sudo bash install.sh` again; it keeps the configuration and cache.

## Server installation

Requirements: PHP **8.1+** with `pdo_sqlite` (and `zip` for ZIP bundles).

1. Unzip `pipusher-server-<ver>.zip` and upload the `pipusher` folder, e.g. to `https://example.com/pipusher/`.
2. Make `pipusher/data/` writable by PHP.
3. Open the URL and create the admin account. Optionally add the sample content.

### webserver

Runs an Apache-compatible setup with `.htaccess` support, so the included protection files work as they are.

1. Select **PHP 8.1 or newer** for the site.
2. Upload the `pipusher` folder by FTP or the file manager, e.g. into `public_html/pipusher`.
3. Open `https://yourdomain.dk/pipusher/`. If something is missing (PHP version, SQLite, write access), PiPusher shows exactly what.
4. After setup, open **Settings → Server diagnostics**. It shows the web server, PHP version, upload limit, whether `data/` is writable, and a link to check that the database is *not* downloadable (you must get 403/404).

If the upload limit shown is low, the `php_value` lines in `.htaccess` were not applied. FAQ explains how to change PHP settings for your plan.

Notes:
- **Apache**: the included `.htaccess` files block `inc/` and `data/`.
- **nginx**: deny `/pipusher/(inc|data)/`, or better, move the data directory outside the web root with `config.php` (copy `config.sample.php`). `media.php/...` needs PATH_INFO (`fastcgi_split_path_info`).
- Upload limits are in `.user.ini` (512 MB). Raise them for bigger videos.
- Use **HTTPS** in production, because devices send their credentials on every sync.

### Two-factor authentication

Each user can turn on two-factor authentication (2FA) under their account (👤). They scan a QR code with an authenticator app, such as Google Authenticator, Microsoft Authenticator or 1Password, and confirm it with a code. After that, logging in asks for a 6-digit code from the app after the password. The codes are standard TOTP (RFC 6238), and each code works only once.

- **Recovery codes**: turning on 2FA shows 10 single-use recovery codes, once. Each one works in place of an app code, for example after losing a phone. New codes can be created on the account page; the old ones then stop working.
- **Site name**: authenticator apps list each account as *site name* + *username*, for example "Acme Signage: admin". Set the site name under *Settings → Security*; the default is "PiPusher (your host name)". A change applies to new setups. Users who already scanned a code can turn 2FA off and on again to get the new name.
- **Require it**: *Settings → Security* can require 2FA for administrators or for all users. Users it applies to must set it up before they can open anything else, and they cannot turn it off.
- **Lost phone and recovery codes**: an administrator clicks **Reset 2FA** for the user under *Users*. If the only administrator is locked out, clear it in the database: `sqlite3 data/pipusher.sqlite "UPDATE users SET totp_secret = NULL WHERE username = 'admin'"`.
- A login waits at most 5 minutes and allows 5 tries for the code. After that, the password has to be entered again.
- The server's clock must be correct, because codes depend on the time.

## Raspberry Pi installation

Raspberry Pi OS Bookworm or newer, **desktop** edition. Pi 3, 4, 5 and Zero 2 W are supported (armv6 Pi 1/Zero are not).

```bash
scp dist/pipusher-client-<ver>.tar.gz pi@raspberrypi.local:
ssh pi@raspberrypi.local
tar xzf pipusher-client-<ver>.tar.gz && cd pipusher-client-<ver>
sudo bash install.sh --server https://example.com/pipusher/ --key <registration key from Settings>
sudo reboot
```

The installer:
- installs the agent as `pipusher.service`
- installs Chromium if it's missing
- adds the kiosk to the desktop autostart (labwc, wayfire and LXDE)
- turns off screen blanking and enables desktop auto-login
- allows the agent to run `reboot`

Without `--key`, the screen shows a pairing code. Approve it under **Screens**.

| | |
|---|---|
| Config | `/etc/pipusher/pipusher.json` (`server_url`, `registration_key`, `name`, `poll_interval`, `insecure_tls`, `listen`, `data_dir`) |
| Cache | `/var/lib/pipusher` (`identity.json` is the device's credentials; keep it to keep the screen's identity) |
| Logs | `journalctl -u pipusher -f` |
| Uninstall | `sudo bash uninstall.sh` (`--purge` also removes config and cache) |

## Windows screen

A Windows 10/11 PC with Google Chrome (or Edge) can act as a full screen, just like a Pi. Content is cached for offline playback, and screenshots, status and remote commands all work. Unpack `pipusher-client-windows-<ver>.zip` (it is also in the `windows\` folder of the client package) and run, as a normal user. For Windows 7, 8 or 8.1 use `pipusher-client-windows7-8-<ver>.zip` instead (Chrome 109 is the last Chrome for those):

```powershell
powershell -ExecutionPolicy Bypass -File install.ps1 -Server https://example.com/pipusher/ -Key <registration key>
```

The installer:
- installs into `%LOCALAPPDATA%\PiPusher`
- starts the kiosk at every login
- adds **PiPusher** and **Stop PiPusher** to the Start menu

The kiosk script restarts Chrome or the agent if either exits, keeps the display on while it runs, and uses its own browser profile with the DevTools port on `127.0.0.1:9222` for screenshots. The **Reboot** command restarts the PC (`-NoReboot` disables this).

| | |
|---|---|
| Config | `%LOCALAPPDATA%\PiPusher\pipusher.json` |
| Cache | `%LOCALAPPDATA%\PiPusher\cache` |
| Logs | `%LOCALAPPDATA%\PiPusher\logs\agent.log`, `kiosk.log` |
| Uninstall | `uninstall.ps1` (`-Purge` also removes config and cache) |

Turn on automatic sign-in for a dedicated screen PC, so it comes back after a power cut.

## Web player (online, no client)

A **web screen** plays in any browser, such as a smart TV, signage browser, PC or tablet, straight from the server, without installing the client and without logging in. It is an ordinary screen in the admin panel: groups, *follow group*, playlists and the *reload* command all work. It **needs the network all the time** because nothing is cached. It cannot take screenshots or reboot.

There are two ways to add one:

1. **Screens → Add web screen** creates a screen with its own secret link, `play.php?screen=<token>`, shown on its page. Open that link on the screen and make it full screen (F11 or the browser's kiosk mode). **New link** replaces the token, and the old link stops working.
2. **Settings → Web player → Create link** gives a self-registration link, `play.php?key=<web player key>`. Every browser that opens it adds itself as a new approved web screen with the default group and content. Add `&name=Reception` to name it. A cookie remembers the browser, so opening the link again reuses its screen. Changing or turning off the key does not affect existing web screens.

Keep the links private, because anyone with a link can see that screen's content. Use HTTPS. To revoke a web screen, remove it under Screens.

## Local test without a Pi

```powershell
.\tools\php\php.exe -S 127.0.0.1:8088 -t server            # server  → http://127.0.0.1:8088/
.\dist\pipusher-windows-amd64-<ver>.exe -server http://127.0.0.1:8088/ -data .\testdata   # client  → http://127.0.0.1:8090/
```

## Device API

All device requests send `X-Device-Auth: <uuid>:<secret>`. The device generates both on first start. The server stores a hash of the secret the first time it sees the device and requires it after that.

- `POST api.php?action=sync`: heartbeat and status. Returns `status` (`pending` / `nolayout` / `ready`), `version`, and the full `config` only when the version changed, plus a pending `command`.
- `GET api.php?action=file&media=<id>&path=<path>`: downloads one media file (approved screens only).
- `POST api.php?action=screenshot` (body: JPEG, max 5 MB): stores the screen's latest screenshot.

## Project layout

```
server/            PHP application (upload this)
  index.php          admin panel (pages in inc/pages/)
  api.php            device API
  preview.php        layout preview using the real player
  media.php          media for previews (admin session)
  inc/totp.php       two-factor authentication (TOTP + recovery codes)
  assets/qrcode.js   QR code generator for 2FA setup (qrcode-generator 1.4.4, MIT, Kazuhiko Arase)
  play.php           online web player (web screens, no login)
  assets/player/     player.js/css: shared with the client (source of truth)
client/            Go agent (stdlib only)
  web/               embedded player page (player.js/css copied by build.ps1)
  install/           install.sh, pipusher-kiosk.sh, uninstall.sh
build.ps1          cross-compile + package
setup-tools.ps1    download Go + PHP into tools/
```
