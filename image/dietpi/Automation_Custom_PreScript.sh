#!/bin/bash
# PiPusher DietPi image, first boot (early): DietPi runs this as root before it applies the password,
# host name and Wi-Fi from /boot/dietpi.txt, so the settings in pipusher.txt are copied there first.
# The password and Wi-Fi key are then removed from pipusher.txt, which stays readable on the SD card.
TXT=/boot/firmware/pipusher.txt
[ -f "$TXT" ] || exit 0

get() { sed -n "s/^[[:blank:]]*$1=//p" "$TXT" | tail -n1 | tr -d '\r'; }
# set_kv FILE KEY VALUE: replaces the first KEY= line, also a commented-out "#KEY=" one (or appends it).
# Key and value are passed through the environment and compared literally, so nothing needs escaping.
set_kv() {
  K="$2" V="$3" awk 'BEGIN { k = ENVIRON["K"] "="; v = ENVIRON["V"] }
    { line = $0; sub(/^[ \t]*(#[ \t]*)?/, "", line) }
    !done && index(line, k) == 1 { print k v; done = 1; next }
    { print } END { if (!done) print k v }' "$1" > "$1.tmp" && cat "$1.tmp" > "$1" && rm -f "$1.tmp"
}
# Single-quoted shell value for dietpi-wifi.txt: ' becomes '\''
squote() { printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"; }

PASSWORD=$(get PASSWORD)
if [ -n "$PASSWORD" ]; then
  set_kv /boot/dietpi.txt AUTO_SETUP_GLOBAL_PASSWORD "$PASSWORD"
  set_kv "$TXT" PASSWORD ''
  echo 'PiPusher: login password taken from pipusher.txt'
fi

NAME=$(get NAME)
if [ -n "$NAME" ]; then
  # Host name: letters, digits and hyphens, at most 63 characters.
  HOST=$(printf '%s' "$NAME" | tr -cs 'A-Za-z0-9-' '-' | sed 's/^-*//; s/-*$//' | cut -c1-63)
  [ -n "$HOST" ] && set_kv /boot/dietpi.txt AUTO_SETUP_NET_HOSTNAME "$HOST"
fi

SSID=$(get WIFI_SSID)
if [ -n "$SSID" ]; then
  KEY=$(get WIFI_KEY)
  COUNTRY=$(get WIFI_COUNTRY)
  set_kv /boot/dietpi-wifi.txt 'aWIFI_SSID[0]' "$(squote "$SSID")"
  set_kv /boot/dietpi-wifi.txt 'aWIFI_KEY[0]' "$(squote "$KEY")"
  [ -n "$KEY" ] || set_kv /boot/dietpi-wifi.txt 'aWIFI_KEYMGR[0]' "'NONE'"
  set_kv /boot/dietpi.txt AUTO_SETUP_NET_WIFI_ENABLED 1
  [ -n "$COUNTRY" ] && set_kv /boot/dietpi.txt AUTO_SETUP_NET_WIFI_COUNTRY_CODE "$COUNTRY"
  set_kv "$TXT" WIFI_KEY ''
  echo "PiPusher: Wi-Fi \"$SSID\" taken from pipusher.txt"
fi
exit 0
