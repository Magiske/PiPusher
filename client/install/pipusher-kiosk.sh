#!/bin/bash
# Starts Chromium full screen on the local PiPusher player and restarts it if it exits.
# Started from the desktop session's autostart; a lock prevents duplicate instances.
URL="${PIPUSHER_URL:-http://127.0.0.1:8090/}"
PROFILE="$HOME/.config/pipusher-chromium"
LOCK="${XDG_RUNTIME_DIR:-/tmp}/pipusher-kiosk.lock"

exec 9>"$LOCK"
flock -n 9 || exit 0

BROWSER="$(command -v chromium || command -v chromium-browser || true)"
if [ -z "$BROWSER" ]; then
  echo "pipusher-kiosk: chromium not found" >&2
  exit 1
fi

# Wait for the agent.
for _ in $(seq 1 60); do
  curl -fs "${URL}api/health" >/dev/null 2>&1 && break
  sleep 1
done

# Hide the mouse pointer on X11 when unclutter is available (the player also hides it via CSS).
command -v unclutter >/dev/null && [ -n "${DISPLAY:-}" ] && (unclutter -idle 0.5 -root &)
# Keep the screen on under X11.
[ -n "${DISPLAY:-}" ] && command -v xset >/dev/null && { xset s off; xset -dpms; xset s noblank; } 2>/dev/null

while true; do
  # Avoid the "Chromium didn't shut down correctly" bar after power loss.
  PREFS="$PROFILE/Default/Preferences"
  if [ -f "$PREFS" ]; then
    sed -i 's/"exited_cleanly":false/"exited_cleanly":true/; s/"exit_type":"[^"]*"/"exit_type":"Normal"/' "$PREFS"
  fi
  "$BROWSER" \
    --kiosk "$URL" \
    --user-data-dir="$PROFILE" \
    --noerrdialogs \
    --disable-infobars \
    --disable-session-crashed-bubble \
    --disable-features=Translate,TranslateUI \
    --no-first-run \
    --no-default-browser-check \
    --autoplay-policy=no-user-gesture-required \
    --check-for-update-interval=31536000 \
    --overscroll-history-navigation=0 \
    --disable-pinch \
    --password-store=basic \
    --remote-debugging-port=9222 \
    --remote-debugging-address=127.0.0.1 \
    --ignore-gpu-blocklist \
    --enable-gpu-rasterization \
    >/dev/null 2>&1
  sleep 3
done
