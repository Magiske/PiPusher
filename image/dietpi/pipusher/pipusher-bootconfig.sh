#!/bin/sh
# PiPusher DietPi image: applies /boot/firmware/pipusher.txt at every boot (edit it on any PC).
# Writes the agent configuration and sets the kiosk window size before the agent and the kiosk start.
TXT=/boot/firmware/pipusher.txt
CONF=/etc/pipusher/pipusher.json

get() { sed -n "s/^[[:blank:]]*$1=//p" "$TXT" 2>/dev/null | tail -n1 | tr -d '\r'; }
json() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

if [ -f "$TXT" ]; then
  mkdir -p /etc/pipusher
  cat > "$CONF.tmp" <<EOF
{
  "server_url": "$(json "$(get SERVER)")",
  "registration_key": "$(json "$(get KEY)")",
  "name": "$(json "$(get NAME)")",
  "data_dir": "/var/lib/pipusher",
  "listen": "127.0.0.1:8090",
  "poll_interval": 60
}
EOF
  chmod 0640 "$CONF.tmp"
  chgrp dietpi "$CONF.tmp"
  mv "$CONF.tmp" "$CONF"
fi

# Kiosk window size (there is no window manager, so Chromium needs the screen size):
# RESOLUTION=WxH from pipusher.txt, else the preferred mode of the connected screen.
RES=$(get RESOLUTION)
case "$RES" in
  [0-9]*x[0-9]*) ;;
  *)
    RES=
    for s in /sys/class/drm/card*-*/status; do
      [ "$(cat "$s" 2>/dev/null)" = connected ] || continue
      RES=$(head -n1 "${s%/status}/modes" 2>/dev/null)
      [ -n "$RES" ] && break
    done
    # Without the KMS driver: the framebuffer size ("1920,1080").
    [ -n "$RES" ] || RES=$(tr ',' 'x' < /sys/class/graphics/fb0/virtual_size 2>/dev/null) ;;
esac
RES=${RES%%[!0-9x]*}
W=${RES%x*}
H=${RES#*x}
if [ -n "$W" ] && [ -n "$H" ] && [ "$W" != "$RES" ]; then
  grep -q "^SOFTWARE_CHROMIUM_RES_X=$W\$" /boot/dietpi.txt || sed -i "s/^SOFTWARE_CHROMIUM_RES_X=.*/SOFTWARE_CHROMIUM_RES_X=$W/" /boot/dietpi.txt
  grep -q "^SOFTWARE_CHROMIUM_RES_Y=$H\$" /boot/dietpi.txt || sed -i "s/^SOFTWARE_CHROMIUM_RES_Y=.*/SOFTWARE_CHROMIUM_RES_Y=$H/" /boot/dietpi.txt
fi

# Raspberry Pi's /usr/bin/chromium asks "not recommended ... less than 1GB of RAM" on 512 MB boards.
# Without a window manager the dialog gets no keyboard, so skip it. The launcher only reads
# --no-memcheck from its command line (not /etc/chromium.d), and DietPi may rewrite its kiosk script.
AUTOSTART=/var/lib/dietpi/dietpi-software/installed/chromium-autostart.sh
if [ -f "$AUTOSTART" ] && ! grep -q -- '--no-memcheck' "$AUTOSTART"; then
  sed -i 's/^CHROMIUM_OPTS="--kiosk/CHROMIUM_OPTS="--no-memcheck --kiosk/' "$AUTOSTART"
fi
# No mouse pointer: start the X server with -nocursor (startx/xinit pass arguments after "--" to X).
if [ -f "$AUTOSTART" ] && ! grep -q -- '-nocursor' "$AUTOSTART"; then
  sed -i 's|^\(exec "\$STARTX" /usr/bin/chromium .*\)$|\1 -- -nocursor|' "$AUTOSTART"
fi

# GPU memory (GPU_MEM, needed for hardware video decoding) and the temperature at which the CPU slows down
# (TEMP_LIMIT), in config.txt. Only values in a safe range are used. The firmware reads config.txt at boot, so
# the Pi restarts once after a change (not on the first start, which restarts anyway; option --no-reboot).
CFG=/boot/firmware/config.txt
[ -f "$CFG" ] || CFG=/boot/config.txt
if [ -f "$CFG" ]; then
  # Board memory in MB from the revision code (bits 20-22), else from what Linux sees.
  REV=$(awk '/^Revision/ { print $3 }' /proc/cpuinfo)
  case "$REV" in
    [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]*) ;;
    *) REV=0 ;;
  esac
  if [ $(((0x$REV >> 23) & 1)) = 1 ]; then   # new-style revision code
    RAM=$((256 << ((0x$REV >> 20) & 7)))
  else
    RAM=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)
  fi
  GPU=$(get GPU_MEM | tr -d ' ')
  case "$GPU" in
    ''|*[!0-9]*) if [ "$RAM" -le 512 ]; then GPU=64; else GPU=128; fi ;;   # auto
  esac
  [ "$GPU" -lt 16 ] && GPU=16
  [ "$GPU" -gt 512 ] && GPU=512
  [ "$RAM" -le 512 ] && [ "$GPU" -gt 128 ] && GPU=128   # leave a 512 MB board enough for Chromium
  TEMP=$(get TEMP_LIMIT | tr -d ' ')
  case "$TEMP" in
    ''|*[!0-9]*) TEMP=80 ;;
  esac
  [ "$TEMP" -lt 65 ] && TEMP=65
  [ "$TEMP" -gt 85 ] && TEMP=85

  set_cfg() {
    if grep -q "^$1=" "$CFG"; then
      grep -q "^$1=$2\$" "$CFG" || sed -i "s/^$1=.*/$1=$2/" "$CFG"
    else
      # New lines at the end, for every board (not inside a [pi4]-style section).
      [ "$(grep '^\[' "$CFG" | tail -n1 | tr -d '\r')" = "" ] || [ "$(grep '^\[' "$CFG" | tail -n1 | tr -d '\r')" = "[all]" ] ||
        echo "[all]" >> "$CFG"
      echo "$1=$2" >> "$CFG"
    fi
  }
  BEFORE=$(cksum < "$CFG")
  for k in gpu_mem gpu_mem_256 gpu_mem_512 gpu_mem_1024; do set_cfg "$k" "$GPU"; done
  set_cfg temp_limit "$TEMP"
  if [ "$(cksum < "$CFG")" != "$BEFORE" ] && [ "$1" != "--no-reboot" ]; then
    echo "PiPusher: config.txt changed (gpu_mem=$GPU, temp_limit=$TEMP), restarting"
    sync
    systemctl --no-block reboot
  fi
fi
exit 0
