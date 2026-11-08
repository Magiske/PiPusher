# PiPusher

Self-hosted digital signage inspired by PiSignage and Yodeck.

- **Server**: a PHP web application, with SQLite and no other dependencies. Upload it to any PHP host. The admin panel controls every screen.
- **Client**: a single Go binary on a Raspberry Pi or Windows 10/11. It downloads and caches content from the server and serves a local player that Chromium shows full screen in kiosk mode. **If the server can't be reached, it keeps playing from its cache**, even after a reboot.
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
| **Playlist** | A queue of layouts that plays top to bottom and repeats. Each entry has a duration (empty = until the layout's content has played once, so videos play to the end) and optional rules: **weekdays**, a **time window** (may cross midnight), and a **date range**, either one-off (events) or **repeating every year** (seasons, holidays). Entries whose rules don't match are skipped, and a fallback layout plays when nothing matches. An entry's **frequency** makes it play less often than every round: `t60` (or `T60`, `t5m`, `t1h`) once 60 seconds have passed since it last played, `f10` (or `F10`, `%10`) every 10th time its turn comes, `50%` half of its turns (spread evenly); when it isn't its turn, the queue moves on (client 1.29.0 or newer; web screens get it with the server). Each screen checks the rules on its own clock, also offline. A screen shows either a playlist or a single layout. Playlists can be organised in **folders** (one level, like layouts); content pickers list them by folder, and deleting a folder moves its playlists to the top level. Playlist editors see only the folders holding playlists they may edit; administrators manage the folders. |
| **Layout** | The screen's design: **orientation** (landscape, portrait, or either one flipped), background colour, and one or more **zones** (windows), each positioned in % of the screen. Zones may overlap: the zone table lists them by layer, layer 1 on top, and **▲ / ▼** under *Layer* moves a zone up (on top of the zone above) or down. The editor shows them stacked as on the screen. Layouts can be organised in **folders**; layout pickers (playlists, screens) list them by folder, and deleting a folder moves its layouts to the top level. |
| **Zone playlist** | Each zone rotates through its own list of content, each item with a duration. A duration of 0 on a video plays it to the end. A picture can have its own size, as a percentage of its own size (50%) or in pixels (800x600, or one side like 800x to keep the aspect ratio), centred in the zone; without one it fits the zone. Tick **Timer** on a zone in the zone table to show a thin progress bar along the bottom of the zone that fills up in step with each item's duration; items with an automatic duration (a video with duration 0, played to the end) show no bar. Pi and Windows screens need client 1.20.0 or newer for the timer. **After** sets what the zone does when an item's duration runs out: **Repeat** (default) goes on to the next item and the zone loops; **Fade out** fades the item away to the background and the zone stops; **Exit** does the same without fading; **Hold** keeps the item on screen (a video stops on its last frame) and the zone stops. A stopped zone starts again the next time its layout is shown, and a playlist entry with an automatic duration counts it as finished. Pi and Windows screens need client 1.25.0 or newer for *After*. |
| **Media** | HTML pages (written in the built-in editor or uploaded), **ZIP bundles** (HTML + CSS/JS/images), images, videos, and links (external URLs). Organised in nestable **folders**; select items to move them between folders, and search across all folders. Deleting a folder moves its contents up a level and deletes nothing. |
| **Users** | **Administrators** have full control, including managing users. **Playlist editors** only see Playlists: they can add, remove, reorder and re-time layouts in playlists and preview them, either in all playlists or only in selected ones. They cannot create or delete playlists or touch screens, layouts, media or settings. Everyone can change their own password and turn on **two-factor authentication** under their account (👤). See *Two-factor authentication*. |

Portrait is handled by rotating the player, so you don't need to change the Pi's display settings. Dynamic pages are ordinary HTML/JS. The *Weather* sample shows how to fetch live data and fall back to the last values while offline. External URLs need the network. While a Pi or Windows screen is online, it takes a **snapshot** of each external page it shows: a picture of that zone after the page has loaded, at most every 10 minutes per page, kept on the screen. While offline, it shows the latest snapshot in place of the page, and switches back to the live page when the network returns. A page without a snapshot yet is skipped while offline, as are external pages on web screens. Snapshots need client 1.19.0 or newer.

**Cookie banners:** a web page that asks to accept cookies would show its banner on a kiosk screen, with nobody to click it. The page's media page has a **Cookies** field. Accept the banner once in your own browser, then copy the consent cookie into the field as `name=value` lines (F12 → *Application* → *Cookies*; a pasted `Cookie:` header works too). Every Pi, Windows and Android screen sets those cookies before showing the page, so the banner does not appear. The screens renew them daily; copy them again when the site asks again. This works for https pages, needs client 1.27.0 or newer, and on Android only with the built-in player. Some sites keep the accepted banner in local storage instead of a cookie: when the *Cookies* list stays empty after accepting, copy the entry from F12 → *Application* → *Local storage* into the **Local storage** field (for example `cookie-consent=accepted`). Screens write it for the page the same way (client 1.28.0 or newer).

Remote commands from the admin panel: **reload player**, **sync now**, **reboot**, **take screenshot**, **update client**. Screens report their status: online/offline, IP, model, CPU temperature, free disk space, cache size and last error. The screen's page also shows when the device **booted**, when the **client started** and when the **player** page last **loaded**: after a reload, the daily reload or a kiosk restart. Web screens show only the player load. Boot time on Windows and the player load time need client 1.18.0 or newer. When the player stops responding (a hung browser), a Pi or Windows screen restarts its kiosk browser by itself after the time set under **Settings → Restart the browser when the player has stopped for** (default 5 minutes, 0 = never; client 1.23.0 or newer).

**Daily player reload** (*Settings → Reload the player every day at*, e.g. 3:00 AM, or Off): every screen's player reloads itself once a day at that time on its own clock, in case a page or video hangs. The time is picked and shown in your browser's own format: 12-hour with AM/PM where that is the custom (such as the US), 24-hour elsewhere. **Settings → Time format** can force 24-hour or 12-hour for everyone using the console, and **Settings → Date format** the order of dates (day month year, month day year or year-month-day); both also apply to the playlist rules' times and dates. Each screen's page has a **Daily reload** card to override it: *Same as Settings* (the default), *Off on this screen*, or *At this screen's own time*. Pi and Windows screens reload from their local cache, so this also works offline. A web screen only reloads when the server can be reached, otherwise it keeps playing. Pi and Windows screens need client 1.17.0 or newer.

**Screen alignment**: each screen has X, Y, W, H (in % of the display; default 0, 0, 100, 100) that move and size the whole picture, to line up a display that crops or shifts it. Layout zones are placed inside this area, so the same layout fits every screen.

**Sound**: each screen has a **Mute sound** button, and a group can mute or unmute all its screens. It applies on the screen's next sync, without reloading, and is remembered offline.
- Muting covers videos and media in uploaded HTML content, including media the page adds later.
- Web pages from other sites (URL media) keep their own sound.

**Screenshots**: each screen uploads a screenshot every *N* minutes (Settings, default 10; 0 = on demand only). The **Take screenshot now** button on a screen's page requests one immediately, and the page updates by itself when it arrives. Thumbnails appear in the Screens list. The agent captures the page through the kiosk Chromium's DevTools port, which listens on `127.0.0.1:9222` only. Portrait captures are rotated back upright and scaled to at most 1280 px.

**Layout previews** (Settings, on by default): the first time a screen plays a layout, it waits a few seconds and takes a screenshot of the player area. The wait is set under **Settings → Layout preview after (seconds)** (default 8, clients 1.14.2 and newer), so slow pictures, videos and pages can load first. That picture becomes the layout's preview on the Layouts page and on the layout's own page. When the layout's zones, items or media change, the next screen that plays it takes a new one. A rename does not. **Retake preview** on the layout page asks for a fresh picture. Web screens cannot take previews. Pi and Windows screens need client 1.14.0 or newer (use the *Update client* command).

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
- `pipusher-server-<ver>.zip` is the PHP application. It also contains the three client packages in `downloads/`, so screens can download the client straight from your server (see *Settings*).
- `pipusher-windows-amd64-<ver>.exe` is the client for local testing.

**Updating:** upload the new server files over the old ones; `data/` and `config.php` are not in the zip, so your content stays.

Then update the screens. Open a screen and choose **Update client to <ver>**, or use **Update client** on a group to update all of its screens.

- The screen downloads the client package bundled with the server.
- It checks that the new program runs and that its version matches the server's.
- It then replaces its own program and restarts. Its configuration and cache stay, and content keeps playing.
- If an update fails, the reason is shown on the screen's page.

Clients older than 1.9.0 can't update themselves. Install them once by hand: on a Pi, unpack the new client package and run `sudo bash install.sh` again; on Windows, run `install.ps1` again. On Linux, the installer adds a sudo rule that lets the agent run exactly one command, `/usr/bin/install -m 0755 /var/lib/pipusher/update/pipusher /usr/local/bin/pipusher`.

## Server installation

Requirements: PHP **8.1+** with `pdo_sqlite` (and `zip` for ZIP bundles).

1. Unzip `pipusher-server-<ver>.zip` and upload the `pipusher` folder, e.g. to `https://example.com/pipusher/`.
2. Make `pipusher/data/` writable by PHP.
3. Open the URL and create the admin account. Optionally add the sample content.

### Webserver

Runs an Apache-compatible setup with `.htaccess` support, so the included protection files work as they are.

1. Select **PHP 8.1 or newer** for the site.
2. Upload the `pipusher` folder by FTP or the file manager, e.g. into `public_html/pipusher`.
3. Open `https://yourdomain.dk/pipusher/`. If something is missing (PHP version, SQLite, write access), PiPusher shows exactly what.
4. After setup, open **Settings → Server diagnostics**. It shows the web server, PHP version, upload limit, whether `data/` is writable, and a link to check that the database is *not* downloadable (you must get 403/404).

If the upload limit shown is low, the `php_value` lines in `.htaccess` were not applied. FAQ explains how to change PHP settings for your plan.

Notes:
- **Apache**: the included `.htaccess` files block `inc/`, `data/` and `downloads/`.
- **Client downloads**: `download.php?file=pi|windows|windows7` serves the bundled client packages to logged-in administrators, or to anyone with the registration key, which is how a screen downloads them. Without a registration key, only administrators can download them.
- **nginx**: deny `/pipusher/(inc|data|downloads)/`, or better, move the data directory outside the web root with `config.php` (copy `config.sample.php`). `media.php/...` needs PATH_INFO (`fastcgi_split_path_info`).
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

### Backup and restore

*Settings → Backup and restore* (administrators):

- **Download backup** gives one zip with everything: the database (settings, users, screens, groups, layouts, playlists, schedules) as a consistent snapshot, all media and the layout previews. Screenshots are left out because screens send new ones. The PHP `zip` extension is required.
- **Restore backup** uploads such a zip and replaces all data on the server with it. You then log in again with an account from the backup. The zip is checked first: it must be a PiPusher backup, not made by a newer server version, have a readable database and contain only expected files. If anything is wrong, nothing is changed. A backup from an older version is migrated automatically.
- The data that was replaced is kept in `data/before-restore/` until the next restore. Delete it to free the space.
- The upload is limited by PHP's `upload_max_filesize` and `post_max_size`, and the Settings card shows the limit. For a large media library, raise both limits in `php.ini` or `.user.ini`.
- Screens keep working after a restore when the new server has the same address, because they authenticate with the device keys stored in the database. Use this to move PiPusher to another host.

## Raspberry Pi installation

Raspberry Pi OS Bookworm or newer, **desktop** edition. Pi 3, 4, 5 and Zero 2 W are supported (armv6 Pi 1/Zero are not).

**Settings → Install a Raspberry Pi screen** shows these commands with your server address and key already filled in. With a registration key set, the Pi downloads the client straight from the server:

```bash
curl -fLo pipusher-client.tar.gz "https://example.com/pipusher/download.php?file=pi&key=<registration key>"
tar xzf pipusher-client.tar.gz && cd pipusher-client-<ver>
sudo bash install.sh --server https://example.com/pipusher/ --key <registration key>
sudo reboot
```

Or copy the package over yourself:

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

## Raspberry Pi image (DietPi)

A ready-to-flash SD card image that does nothing but show PiPusher. It is built on DietPi (minimal Debian) and set up for low memory, so that Chromium gets as much of the RAM as possible, even on a 512 MB Zero 2 W. It runs only:

- X11 with Chromium in kiosk mode. There is no desktop or window manager.
- The PiPusher agent.
- The network (cable or Wi-Fi).
- Dropbear, the smallest SSH server.
- zram swap (compressed RAM, no SD card wear) and RAM logging.

Bluetooth and the serial console are off. Chromium runs with low-memory flags; on boards with 1 GB of RAM or less it also uses low-end device mode.

```powershell
.\build.ps1                          # first: the image takes the agent from dist\pipusher-client-<ver>.tar.gz
.\build-image.ps1                    # generic images for Pi 2 v1.2/3/4/400/Zero 2 W (RPi234) and Pi 5 (RPi5)
.\build-image.ps1 -Model RPi234 -Server https://example.com/pipusher/ -Key <key> -WifiSsid Office -WifiKey secret
                                     # personal image with the settings filled in (keep it private)
```

Output: `dist\pipusher-dietpi-<model>-<ver>.img.xz` (+ `.sha256`). The DietPi base image is downloaded once into `tools\dietpi\`, checksum verified. `image\mkimage` edits the FAT boot partition directly, so nothing needs to be mounted.

**Using it:**
1. Flash the `.img.xz` with Raspberry Pi Imager ("Use custom") or balenaEtcher.
2. Open the SD card's boot drive on a PC and edit `pipusher.txt`. It holds `SERVER`, `KEY`, `NAME`, `PASSWORD`, `WIFI_SSID`/`WIFI_KEY`/`WIFI_COUNTRY` and `RESOLUTION`.
3. Boot the Pi. The first start needs internet: DietPi installs X11 and Chromium (5-15 minutes), then the Pi restarts into the kiosk.

`SERVER`, `KEY`, `NAME` and `RESOLUTION` are applied at every boot, so they can be changed later on the boot drive or in `/boot/firmware/pipusher.txt`. The password and Wi-Fi settings are used on the first start and then blanked in the file. SSH: `ssh dietpi@<host>` (default password `pipusher`; set your own in `pipusher.txt`).

Performance settings in `pipusher.txt`, also applied at every boot:
- `LOW_MEMORY=auto|on|off`: Chromium's low-memory mode. It saves memory but draws with 16-bit colours (visible banding); `auto` uses it only on 512 MB boards (Zero 2 W).
- `GPU_MEM=auto|16-512`: GPU memory in MB, needed for hardware video decoding. `auto` = 128, or 64 on 512 MB boards (DietPi's own default of 16 leaves the Pi without video decoding).
- `TEMP_LIMIT=65-85`: the temperature (°C) at which the CPU slows down; default 80 (DietPi's is 65).

After a change to `GPU_MEM` or `TEMP_LIMIT` the Pi restarts once by itself, since the firmware only reads `config.txt` at boot. On a Pi 2/3 use H.264 MP4 videos at 1080p or less.

The player shows each picture, video or page only once it has fully loaded in the browser, keeping the previous one on screen until then (at most 15 s). An item's duration, its timer bar and a playlist entry's time start when it appears.

The kit is in `image\dietpi\`:

| File | Role |
|---|---|
| `Automation_Custom_PreScript.sh` | Moves the pipusher.txt settings into DietPi's settings |
| `Automation_Custom_Script.sh` | Installs the agent and its services |
| `pipusher/pipusher-bootconfig.sh` | Every boot: writes the agent config and the screen size |
| `pipusher/chromium-flags.sh` | Kiosk and memory flags; waits for the agent |

## Windows screen

A Windows 10/11 PC with Google Chrome (or Edge) can act as a full screen, just like a Pi. Content is cached for offline playback, and screenshots, status and remote commands all work. Unpack `pipusher-client-windows-<ver>.zip` (it is also in the `windows\` folder of the client package) and run, as a normal user. For Windows 7, 8 or 8.1 use `pipusher-client-windows7-8-<ver>.zip` instead (Chrome 109 is the last Chrome for those):

```powershell
powershell -ExecutionPolicy Bypass -File install.ps1 -Server https://example.com/pipusher/ -Key <registration key>
```

**Settings → Install a Windows screen** has download buttons for both Windows packages. With a registration key set, it also shows a PowerShell command that downloads the Windows 10/11 client from the server, unpacks it and installs it in one go.

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

## Android screen (experimental)

An Android phone, tablet or TV box (Android 5.0 or newer) can act as a screen with the PiPusher app,
`pipusher-android-experimental-<ver>.apk`. **Experimental:** a first version to see whether Android screens are worth it, and it has not yet been
tested on a real device. The APK is only built when asked for: `.\build-android.ps1` (after `build.ps1`).

The app runs the same Go agent as a Pi (built into the APK), so content is cached for offline playback and the
screen registers, syncs and takes commands like any other. Everything is set in the app; there are no files to edit:

| Setting | |
|---|---|
| Server address | `https://example.com/pipusher/` |
| Screen name | defaults to the device name |
| Registration key | optional: approves the screen at once |
| Self-signed certificate | accept one (`insecure_tls`) |
| Browser | **Built-in fullscreen player** (default), **System default browser**, or any installed browser |
| Screen orientation | landscape, portrait, either upside down, or follow the device |
| Start on boot | starts the agent and opens the player when the device starts |
| Use as home app | Home and start-up always return to the player (the device asks once which home app to use) |
| PIN | protects the settings |

Install: copy the APK to the device and open it (allow installs from that source), or `adb install pipusher-android-experimental-<ver>.apk`.
The first start opens the settings; **Save and start player** starts it, and the settings show the device code
while the screen waits for approval. Later starts go straight to the player.

- **Leaving the player:** 5 taps in the top-left corner, 5 presses of OK on a remote, Menu, or holding Back, then
  the PIN when one is set. Back alone does nothing.
- **Start on boot, Android 10 and newer:** Android only lets an app open on screen at start-up when it may *Display
  over other apps*. The settings have a button for it. On TV boxes without that screen:
  `adb shell appops set com.pipusher.client SYSTEM_ALERT_WINDOW allow`. *Use as home app* is an alternative.
- **Phones and tablets:** allow *Keep running in the background* (battery optimisation) in the settings, or the
  system may stop the player.
- **Built-in player:** a fullscreen WebView (Chrome's engine, updated through Android System WebView). Videos play
  with sound, the screen stays on, and a stuck player is restarted (Settings → browser restart time). Screenshots,
  layout previews and page snapshots work. Pages are laid out in screen pixels, as on a Pi: a 1920×1080 TV shows an
  HTML page in a zone the same size as a 1080p Pi does, whatever the TV's display density.
- **Another browser:** shows the player at `http://127.0.0.1:8090/` with its own address bar. It may block video
  sound until the screen is touched, and screenshots are not possible. A stuck player is opened again.
- **Reboot** works on rooted devices only; on others it restarts the agent and the player.
- **Update client** is not available: install the new APK over the old one (settings and cache are kept). After an
  update the player comes back by itself only if it was on screen, so an update does not interrupt another app. The APK
  must be signed with the same key, `tools\android-release.jks`, which the first build created. Keep a copy of it.

Inside the app, `libpipusher.so` is the Go agent. `arm64-v8a` is built for Android; `armeabi-v7a`, `x86_64` and `x86` are
static Linux builds with the `pipusher_android` tag. The app writes the agent's `pipusher.json` and runs the agent
as a foreground service. That file also sets `control_url`, the app's control port `127.0.0.1:8091` for screenshots,
restarts and reboots, and `dns_file`, because Android has no `/etc/resolv.conf`. **Show log** in the settings shows
the agent's log.

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

- `POST api.php?action=sync`: heartbeat and status. Returns `status` (`pending` / `nolayout` / `ready`), `version`, and the full `config` only when the version changed, plus a pending `command` and `previews` (layout ids that need a preview screenshot).
- `GET api.php?action=file&media=<id>&path=<path>`: downloads one media file (approved screens only).
- `POST api.php?action=screenshot` (body: JPEG, max 5 MB): stores the screen's latest screenshot.
- `POST api.php?action=preview&layout=<id>` (body: JPEG, max 5 MB): stores a layout preview. Only accepted for layouts listed in the sync response's `previews`.
- `GET api.php?action=client&os=<goos>&arch=<goarch>&go=<go version>`: the server's client package for this screen, with an `X-PiPusher-Version` header. Used by the `update` command (approved screens only).

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
  download.php       client packages from downloads/ (admin session or registration key)
  backup.php         backup zip download (admin); restore is on the Settings page (inc/backup.php)
  assets/player/     player.js/css: shared with the client (source of truth)
client/            Go agent (stdlib only)
  web/               embedded player page (player.js/css copied by build.ps1)
  install/           install.sh, pipusher-kiosk.sh, uninstall.sh
android/           Android app (Java, no libraries): manifest, res/, src/com/pipusher/client/
image/             Raspberry Pi DietPi image
  dietpi/            first-boot scripts and pipusher.txt (copied to the boot partition)
  mkimage/           FAT boot partition editor (Go, go-diskfs)
build.ps1          cross-compile + package
build-image.ps1    DietPi SD card images (after build.ps1)
build-android.ps1  Android APK, only when asked (downloads Java 17 + the Android SDK into tools/)
setup-tools.ps1    download Go + PHP into tools/
```
