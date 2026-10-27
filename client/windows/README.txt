PiPusher client for Windows 10/11

Works like a Raspberry Pi screen: content is cached and keeps playing when the server can't be
reached, screenshots, status, and the remote commands (reload, sync, screenshot, reboot) all work.
Needs Google Chrome (or Microsoft Edge). No administrator rights are needed.

Install (in this folder, from a PowerShell window):

  powershell -ExecutionPolicy Bypass -File install.ps1 -Server https://your-site.example/pipusher/ -Key YOUR_REGISTRATION_KEY

  Options: -Name "Screen name"  -NoAutostart  -NoReboot  -NoStart  -Browser "C:\path\to\chrome.exe"
           -Dir PATH (default %LOCALAPPDATA%\PiPusher)

The player opens full screen and starts again at every login. If the browser or the agent
closes, it is restarted within a few seconds. While it runs, the display stays on.

Stop:      press the Windows key, then Start menu > PiPusher > Stop PiPusher
Start:     Start menu > PiPusher > PiPusher
Updating:  run install.ps1 from the new version again (keeps the config and cache)
Remove:    powershell -ExecutionPolicy Bypass -File uninstall.ps1 [-Purge]

Config:    %LOCALAPPDATA%\PiPusher\pipusher.json
Cache:     %LOCALAPPDATA%\PiPusher\cache   (identity.json is the screen's credentials)
Logs:      %LOCALAPPDATA%\PiPusher\logs\agent.log and kiosk.log

For a dedicated screen PC, also turn on automatic sign-in for the Windows account, so the
player starts by itself after a power cut.
