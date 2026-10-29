#!/bin/bash
# PiPusher DietPi image, first boot (late): DietPi runs this as root after it has installed X11 and
# Chromium. Installs the PiPusher agent and the kiosk settings. The files come from /boot/firmware/pipusher/.
#
#   /usr/local/bin/pipusher             agent: syncs and caches content, serves the player on 127.0.0.1:8090
#   /usr/local/bin/pipusher-bootconfig  applies /boot/firmware/pipusher.txt at every boot
#   /etc/chromium.d/pipusher            kiosk + low-memory Chromium flags
#   /var/lib/pipusher                   content cache (keeps playing offline)
set -e
SRC=/boot/firmware/pipusher

case "$(dpkg --print-architecture)" in
  arm64) BIN=pipusher-linux-arm64 ;;
  armhf) BIN=pipusher-linux-armv7 ;;
  *) echo "PiPusher: unsupported architecture $(dpkg --print-architecture)" >&2; exit 1 ;;
esac
install -m 0755 "$SRC/$BIN" /usr/local/bin/pipusher
install -m 0755 "$SRC/pipusher-bootconfig.sh" /usr/local/bin/pipusher-bootconfig
install -D -m 0644 "$SRC/chromium-flags.sh" /etc/chromium.d/pipusher

# The agent and the kiosk run as the normal user "dietpi"; it may reboot for the remote Reboot command
# and put a downloaded new version of itself in place for the remote Update client command.
install -d -o dietpi -g dietpi /var/lib/pipusher
usermod -aG systemd-journal dietpi   # "journalctl -u pipusher" without sudo
echo 'dietpi ALL=(root) NOPASSWD: /sbin/reboot, /usr/sbin/reboot, /usr/bin/install -m 0755 /var/lib/pipusher/update/pipusher /usr/local/bin/pipusher' > /etc/sudoers.d/pipusher
chmod 0440 /etc/sudoers.d/pipusher

cat > /etc/systemd/system/pipusher-bootconfig.service <<'EOF'
[Unit]
Description=Apply PiPusher settings from /boot/firmware/pipusher.txt
After=local-fs.target
Before=pipusher.service getty@tty1.service

[Service]
Type=oneshot
ExecStart=/usr/local/bin/pipusher-bootconfig

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/pipusher.service <<'EOF'
[Unit]
Description=PiPusher digital signage agent
Wants=network-online.target pipusher-bootconfig.service
After=network-online.target pipusher-bootconfig.service

[Service]
User=dietpi
ExecStart=/usr/local/bin/pipusher -config /etc/pipusher/pipusher.json
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable pipusher-bootconfig.service pipusher.service
/usr/local/bin/pipusher-bootconfig
# Start the agent now: DietPi may start the kiosk without rebooting after this script.
systemctl start pipusher.service
echo "PiPusher: agent $(/usr/local/bin/pipusher -version) installed and started"
