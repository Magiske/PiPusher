<#
  PiPusher kiosk for Windows: keeps the agent running and shows the player full screen in
  Chrome (or Edge) kiosk mode, restarting either one if it exits.
  Started at login by the shortcut install.ps1 creates; a mutex prevents duplicate instances.

    pipusher-kiosk.ps1                 run the kiosk
    pipusher-kiosk.ps1 -Browser PATH   use this browser instead of the detected Chrome/Edge
    pipusher-kiosk.ps1 -Stop           stop the kiosk, the browser and the agent
#>
param([switch]$Stop, [string]$Browser = '')
$ErrorActionPreference = 'Stop'
$dir = $PSScriptRoot
$exe = Join-Path $dir 'pipusher.exe'
$config = Join-Path $dir 'pipusher.json'
$profileDir = Join-Path $dir 'browser-profile'
$logDir = Join-Path $dir 'logs'
$stopFlag = Join-Path $dir 'stop.flag'
$mutexName = 'Local\PiPusherKiosk'

function Write-Log([string]$msg) {
    New-Item -ItemType Directory -Force $logDir | Out-Null
    Add-Content -Path (Join-Path $logDir 'kiosk.log') -Value ('{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), $msg)
}

# Browser processes started with our profile (the player), not the user's own browser windows.
function Get-KioskBrowser {
    Get-CimInstance Win32_Process -Filter "Name='chrome.exe' OR Name='msedge.exe'" |
        Where-Object { $_.CommandLine -and $_.CommandLine.IndexOf($profileDir, [StringComparison]::OrdinalIgnoreCase) -ge 0 }
}

function Stop-Children {
    Get-KioskBrowser | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force } catch {} }
    Get-Process pipusher -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe } |
        ForEach-Object { try { $_ | Stop-Process -Force } catch {} }
}

if ($Stop) {
    Set-Content -Path $stopFlag -Value 'stop'
    Stop-Children
    # Wait for a running kiosk loop to notice the flag and exit, then clean up anything it restarted.
    $m = New-Object System.Threading.Mutex($false, $mutexName)
    try { [void]$m.WaitOne(20000) } catch [System.Threading.AbandonedMutexException] {}
    Stop-Children
    try { $m.ReleaseMutex() } catch {}
    Remove-Item $stopFlag -ErrorAction SilentlyContinue
    exit 0
}

$mutex = New-Object System.Threading.Mutex($false, $mutexName)
try {
    if (-not $mutex.WaitOne(0)) { exit 0 }
} catch [System.Threading.AbandonedMutexException] {}
Remove-Item $stopFlag -ErrorAction SilentlyContinue

if (-not $Browser) {
    $candidates = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe")
    $Browser = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
}
if (-not $Browser -or -not (Test-Path $Browser)) {
    Write-Log 'no browser found: install Google Chrome or pass -Browser'
    exit 1
}

$listen = '127.0.0.1:8090'
try {
    $c = Get-Content $config -Raw | ConvertFrom-Json
    if ($c.listen) { $listen = $c.listen }
} catch {}
$listen = $listen -replace '^(0\.0\.0\.0)?:', '127.0.0.1:'
$url = "http://$listen/"

$browserArgs = @(
    "--kiosk", $url,
    "--user-data-dir=`"$profileDir`"",
    '--remote-debugging-port=9222',
    '--remote-debugging-address=127.0.0.1',
    '--autoplay-policy=no-user-gesture-required',
    '--no-first-run',
    '--no-default-browser-check',
    '--noerrdialogs',
    '--disable-infobars',
    '--disable-session-crashed-bubble',
    '--disable-features=Translate,TranslateUI',
    '--disable-extensions',
    '--overscroll-history-navigation=0',
    '--disable-pinch',
    '--check-for-update-interval=31536000')
if ($Browser -like '*msedge.exe') { $browserArgs += '--edge-kiosk-type=fullscreen' }

# Keep the display on and the PC awake while the kiosk runs (no power settings are changed).
Add-Type -Namespace PiPusher -Name Power -MemberDefinition '[DllImport("kernel32.dll")] public static extern uint SetThreadExecutionState(uint esFlags);'
[void][PiPusher.Power]::SetThreadExecutionState([uint32]'0x80000003')   # ES_CONTINUOUS | ES_DISPLAY_REQUIRED | ES_SYSTEM_REQUIRED

function Start-Agent {
    $p = Start-Process -FilePath $exe -WorkingDirectory $dir -WindowStyle Hidden -PassThru `
        -ArgumentList @('-config', "`"$config`"", '-log', "`"$(Join-Path $logDir 'agent.log')`"")
    for ($i = 0; $i -lt 30 -and -not $p.HasExited; $i++) {
        try {
            Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 "${url}api/health" | Out-Null
            break
        } catch { Start-Sleep -Seconds 1 }
    }
    return $p
}

# Avoid the "didn't shut down correctly" bar after a power cut.
function Repair-Preferences {
    $prefs = Join-Path $profileDir 'Default\Preferences'
    if (Test-Path $prefs) {
        $text = [IO.File]::ReadAllText($prefs)
        $text = $text.Replace('"exited_cleanly":false', '"exited_cleanly":true') -replace '"exit_type":"[^"]*"', '"exit_type":"Normal"'
        [IO.File]::WriteAllText($prefs, $text)
    }
}

Write-Log "kiosk started: $Browser -> $url"
Stop-Children   # leftovers from a kiosk that did not exit cleanly
$agent = $null
try {
    while (-not (Test-Path $stopFlag)) {
        if (-not $agent -or $agent.HasExited) {
            if ($agent) { Write-Log 'agent exited, restarting (see logs\agent.log)' }
            $agent = Start-Agent
        }
        if (-not (Test-Path $stopFlag) -and -not (Get-KioskBrowser)) {
            Repair-Preferences
            Start-Process -FilePath $Browser -ArgumentList $browserArgs | Out-Null
        }
        Start-Sleep -Seconds 5
    }
} finally {
    Write-Log 'kiosk stopped'
    Stop-Children
    $mutex.ReleaseMutex()
}
