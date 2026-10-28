#!/bin/bash
# PiPusher client installer for Raspberry Pi OS (Bookworm or newer, desktop edition).
#
#   sudo bash install.sh --server https://example.com/pipusher/ [--key REGISTRATION_KEY]
#                        [--name "Reception"] [--user pi] [--no-autologin] [--no-kiosk]
#
# Installs:
#   /usr/local/bin/pipusher          agent (syncs + caches content, serves the local player)
#   /usr/local/bin/pipusher-kiosk    starts Chromium full screen on the local player
#   /etc/pipusher/pipusher.json      configuration
#   /var/lib/pipusher                cache (works offline from here)
#   pipusher.service                 systemd service for the agent
#   desktop autostart entries        for the kiosk (labwc, wayfire and LXDE/X11)
set -euo pipefail

SERVER=""
KEY=""
NAME=""
RUN_USER="${SUDO_USER:-}"
AUTOLOGIN=1
KIOSK=1
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

while [ $# -gt 0 ]; do
  case "$1" in
    --server) SERVER="$2"; shift 2 ;;
    --key) KEY="$2"; shift 2 ;;
    --name) NAME="$2"; shift 2 ;;
    --user) RUN_USER="$2"; shift 2 ;;
    --no-autologin) AUTOLOGIN=0; shift ;;
    --no-kiosk) KIOSK=0; shift ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root: sudo bash $0 $*" >&2
  exit 1
fi
if [ -z "$RUN_USER" ] || [ "$RUN_USER" = "root" ]; then
  RUN_USER="$(getent passwd 1000 | cut -d: -f1 || true)"
fi
if [ -z "$RUN_USER" ] || ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "Could not determine the desktop user; pass --user <name>" >&2
  exit 1
fi
RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6)"

CODENAME="$(. /etc/os-release 2>/dev/null && echo "${VERSION_CODENAME:-}")"
case "$CODENAME" in
  stretch|buster|bullseye)
    echo "WARNING: this is Raspberry Pi OS / Debian '$CODENAME'; PiPusher needs Bookworm or newer." >&2
    echo "         Package installs may fail and the kiosk may not start. Reinstalling the Pi is recommended." >&2 ;;
esac

# ---------------------------------------------------------------- binary
case "$(dpkg --print-architecture 2>/dev/null || uname -m)" in
  arm64|aarch64) BIN="pipusher-linux-arm64" ;;
  armhf|armv7l|armv7*) BIN="pipusher-linux-armv7" ;;
  amd64|x86_64) BIN="pipusher-linux-amd64" ;;
  *) echo "Unsupported architecture: $(uname -m). Pi Zero/Pi 1 (armv6) are not supported." >&2; exit 1 ;;
esac
if [ ! -f "$HERE/$BIN" ]; then
  echo "Missing $HERE/$BIN" >&2
  exit 1
fi
echo "==> Installing $BIN for user $RUN_USER"
systemctl stop pipusher 2>/dev/null || true
install -m 0755 "$HERE/$BIN" /usr/local/bin/pipusher
install -m 0755 "$HERE/pipusher-kiosk.sh" /usr/local/bin/pipusher-kiosk

# ---------------------------------------------------------------- config
mkdir -p /etc/pipusher /var/lib/pipusher
chown "$RUN_USER": /var/lib/pipusher
CONF=/etc/pipusher/pipusher.json
if [ -f "$CONF" ] && [ -z "$SERVER" ]; then
  echo "==> Keeping existing $CONF"
else
  if [ -z "$SERVER" ]; then
    read -r -p "PiPusher server URL (e.g. https://example.com/pipusher/): " SERVER
  fi
  json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
  cat > "$CONF" <<EOF
{
  "server_url": "$(json_escape "$SERVER")",
  "registration_key": "$(json_escape "$KEY")",
  "name": "$(json_escape "$NAME")",
  "data_dir": "/var/lib/pipusher",
  "listen": "127.0.0.1:8090",
  "poll_interval": 60,
  "insecure_tls": false
}
EOF
  chmod 0640 "$CONF"
  chgrp "$(id -gn "$RUN_USER")" "$CONF"
  echo "==> Wrote $CONF"
fi

# Allow the agent to reboot the Pi (remote "Reboot" command) and to put a downloaded new version
# of itself in place (remote "Update client" command; only this exact command).
cat > /etc/sudoers.d/pipusher <<EOF
$RUN_USER ALL=(root) NOPASSWD: /sbin/reboot, /usr/sbin/reboot, /usr/bin/install -m 0755 /var/lib/pipusher/update/pipusher /usr/local/bin/pipusher
EOF
chmod 0440 /etc/sudoers.d/pipusher

# ---------------------------------------------------------------- service
cat > /etc/systemd/system/pipusher.service <<EOF
[Unit]
Description=PiPusher digital signage agent
After=network-online.target
Wants=network-online.target

[Service]
User=$RUN_USER
ExecStart=/usr/local/bin/pipusher -config $CONF
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now pipusher
echo "==> pipusher.service started"

# ---------------------------------------------------------------- kiosk
if [ "$KIOSK" = "1" ]; then
  if ! command -v chromium >/dev/null && ! command -v chromium-browser >/dev/null; then
    echo "==> Installing Chromium"
    # One broken package source (unsigned, expired key, moved release) makes apt-get update fail;
    # the other sources are usually fine, so try the install anyway.
    if ! apt-get update; then
      echo "WARNING: apt-get update reported errors (see above). Trying to install Chromium anyway." >&2
    fi
    if ! apt-get install -y chromium && ! apt-get install -y chromium-browser; then
      cat >&2 <<EOF

ERROR: Chromium could not be installed, so the kiosk was not set up.
The agent itself is installed and running (pipusher.service).
Fix the package sources apt complained about above, then run this installer again:
  - "doesn't have a Release file" / "can't be done securely": the source is gone or unsigned.
    Remove or fix that line in /etc/apt/sources.list or /etc/apt/sources.list.d/.
    On an old OS (Buster or older) the repositories have moved; reinstall with a current
    Raspberry Pi OS (desktop edition) instead.
  - "not valid yet": the Pi's clock is wrong. Check it with: date
Or skip the kiosk with --no-kiosk.
EOF
      exit 1
    fi
  fi

  as_user() { sudo -u "$RUN_USER" "$@"; }
  # XDG autostart (LXDE/X11 and most desktops)
  as_user mkdir -p "$RUN_HOME/.config/autostart"
  as_user tee "$RUN_HOME/.config/autostart/pipusher-kiosk.desktop" >/dev/null <<EOF
[Desktop Entry]
Type=Application
Name=PiPusher kiosk
Exec=/usr/local/bin/pipusher-kiosk
X-GNOME-Autostart-enabled=true
EOF
  # labwc (Raspberry Pi OS default since late 2024)
  # A user autostart file replaces the system one, so start from a copy of it.
  as_user mkdir -p "$RUN_HOME/.config/labwc"
  if [ ! -f "$RUN_HOME/.config/labwc/autostart" ] && [ -f /etc/xdg/labwc/autostart ]; then
    cp /etc/xdg/labwc/autostart "$RUN_HOME/.config/labwc/autostart"
  fi
  touch "$RUN_HOME/.config/labwc/autostart"; chown "$RUN_USER": "$RUN_HOME/.config/labwc/autostart"
  grep -q pipusher-kiosk "$RUN_HOME/.config/labwc/autostart" || echo "/usr/local/bin/pipusher-kiosk &" >> "$RUN_HOME/.config/labwc/autostart"
  # wayfire (Bookworm 2023/2024)
  WF="$RUN_HOME/.config/wayfire.ini"
  if [ -f "$WF" ] && ! grep -q pipusher-kiosk "$WF"; then
    if grep -q '^\[autostart\]' "$WF"; then
      sed -i '/^\[autostart\]/a pipusher = /usr/local/bin/pipusher-kiosk' "$WF"
    else
      printf '\n[autostart]\npipusher = /usr/local/bin/pipusher-kiosk\n' >> "$WF"
    fi
  fi
  echo "==> Kiosk autostart configured"

  if command -v raspi-config >/dev/null; then
    raspi-config nonint do_blanking 1 || true   # disable screen blanking
    if [ "$AUTOLOGIN" = "1" ]; then
      raspi-config nonint do_boot_behaviour B4 || true   # boot to desktop, auto-login
      echo "==> Desktop auto-login enabled"
    fi
  fi
fi

echo
echo "PiPusher installed. Status:  systemctl status pipusher   Logs:  journalctl -u pipusher -f"
echo "Local player: http://127.0.0.1:8090/"
[ "$KIOSK" = "1" ] && echo "Reboot to start the kiosk:  sudo reboot"
exit 0
