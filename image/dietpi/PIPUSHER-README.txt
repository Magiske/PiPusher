PiPusher screen for Raspberry Pi (DietPi)

1. Write this image to an SD card (Raspberry Pi Imager: "Use custom", or balenaEtcher).
2. Open the SD card's boot drive on your PC and edit pipusher.txt:
   SERVER (your PiPusher address), KEY (registration key), NAME, PASSWORD and, for Wi-Fi, WIFI_SSID/WIFI_KEY.
3. Put the card in the Pi, connect the screen (and network cable) and power it on.

The first start installs everything and takes about 5-15 minutes (needs internet), then the Pi
restarts and shows PiPusher full screen. Later starts take well under a minute.

What runs: DietPi (Debian), X11 with only Chromium (no desktop), the PiPusher agent, and the Dropbear
SSH server. Content is cached on the SD card, so the screen keeps playing when the server is offline.

SSH:      ssh dietpi@<ip or host name>   (password from pipusher.txt, default "pipusher")
Logs:     journalctl -u pipusher -f
Settings: edit pipusher.txt on the boot drive at any time (SERVER, KEY, NAME, RESOLUTION),
          or on the Pi: /boot/firmware/pipusher.txt, then reboot.
