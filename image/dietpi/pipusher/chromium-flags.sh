# PiPusher kiosk settings for Chromium. Installed as /etc/chromium.d/pipusher, which the chromium start
# script reads before every start. DietPi-Autostart (Chromium kiosk) then opens the local PiPusher player.
# Only applies inside the kiosk's X session; Chromium restarts with the session if it ever exits.
if [ -n "$DISPLAY" ]; then
  PROFILE="$HOME/.config/pipusher-chromium"

  # No "Chromium didn't shut down correctly" bar after a power cut.
  if [ -f "$PROFILE/Default/Preferences" ]; then
    sed -i 's/"exited_cleanly":false/"exited_cleanly":true/; s/"exit_type":"[^"]*"/"exit_type":"Normal"/' "$PROFILE/Default/Preferences"
  fi

  # Wait (at most a minute) for the PiPusher agent that serves the player.
  i=0
  while [ $i -lt 60 ] && ! curl -fs -o /dev/null http://127.0.0.1:8090/api/health; do
    sleep 1
    i=$((i + 1))
  done

  # Kiosk behaviour. Remote debugging (local only) is how the agent takes screenshots; it needs its own profile.
  CHROMIUM_FLAGS="$CHROMIUM_FLAGS --user-data-dir=$PROFILE --noerrdialogs --disable-infobars
    --disable-session-crashed-bubble --no-first-run --no-default-browser-check
    --autoplay-policy=no-user-gesture-required --overscroll-history-navigation=0 --disable-pinch
    --password-store=basic --remote-debugging-port=9222 --remote-debugging-address=127.0.0.1
    --ignore-gpu-blocklist --enable-gpu-rasterization"

  # Less memory and no background traffic: no sync, extensions, translation, updates or casting,
  # a small disk cache (content is cached by the agent) and at most two page processes.
  CHROMIUM_FLAGS="$CHROMIUM_FLAGS --disable-sync --disable-extensions --disable-default-apps
    --disable-background-networking --disable-component-update --check-for-update-interval=31536000
    --disable-features=Translate,TranslateUI,MediaRouter,OptimizationHints,AutofillServerCommunication
    --disk-cache-size=33554432 --renderer-process-limit=2"

  # 1 GB RAM or less (Pi 3, Zero 2 W): Chromium's low-memory mode, and no extra processes per site.
  if [ "$(awk '/^MemTotal:/ { print $2 }' /proc/meminfo)" -le 1100000 ]; then
    CHROMIUM_FLAGS="$CHROMIUM_FLAGS --enable-low-end-device-mode --disable-site-isolation-trials"
  fi
fi
