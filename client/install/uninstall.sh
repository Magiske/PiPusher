#!/bin/bash
# Removes the PiPusher client. Pass --purge to also delete the configuration and cache.
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root: sudo bash $0 $*" >&2
  exit 1
fi
systemctl disable --now pipusher 2>/dev/null || true
pkill -f pipusher-kiosk 2>/dev/null || true
rm -f /etc/systemd/system/pipusher.service /usr/local/bin/pipusher /usr/local/bin/pipusher-kiosk /etc/sudoers.d/pipusher
systemctl daemon-reload
for home in /home/*; do
  rm -f "$home/.config/autostart/pipusher-kiosk.desktop"
  [ -f "$home/.config/labwc/autostart" ] && sed -i '/pipusher-kiosk/d' "$home/.config/labwc/autostart"
  [ -f "$home/.config/wayfire.ini" ] && sed -i '/pipusher-kiosk/d' "$home/.config/wayfire.ini"
done
if [ "${1:-}" = "--purge" ]; then
  rm -rf /etc/pipusher /var/lib/pipusher
  echo "Configuration and cache removed."
fi
echo "PiPusher client removed."
