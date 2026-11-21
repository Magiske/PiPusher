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

# Translation off with a managed policy: newer Chromium ignores --disable-features=Translate, and a translate
# bar over a screen's web page cannot be closed without a keyboard.
install -d /etc/chromium/policies/managed
echo '{"TranslateEnabled": false}' > /etc/chromium/policies/managed/pipusher.json

# Wi-Fi power saving off whenever a Wi-Fi interface comes up (DietPi uses ifupdown): with power saving the
# Pi's Wi-Fi sleeps between packets, which slows streams and syncs and can drop the connection after a while.
install -d /etc/network/if-up.d
cat > /etc/network/if-up.d/pipusher-wifi-powersave <<'EOF'
#!/bin/sh
# PiPusher: no Wi-Fi power saving (slow or dropped connections on a screen).
[ -d "/sys/class/net/$IFACE/wireless" ] || exit 0
command -v iw > /dev/null && iw dev "$IFACE" set power_save off
exit 0
EOF
chmod 0755 /etc/network/if-up.d/pipusher-wifi-powersave
for d in /sys/class/net/*/wireless; do   # already up now (Wi-Fi set up on the first start)
  [ -d "$d" ] && IFACE=$(basename "$(dirname "$d")") /etc/network/if-up.d/pipusher-wifi-powersave
done

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
/usr/local/bin/pipusher-bootconfig --no-reboot   # DietPi restarts after the first start anyway
# Start the agent now: DietPi may start the kiosk without rebooting after this script.
systemctl start pipusher.service
echo "PiPusher: agent $(/usr/local/bin/pipusher -version) installed and started"
