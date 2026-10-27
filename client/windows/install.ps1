<#
  Installs the PiPusher client on Windows for the current user (no administrator rights needed).

    powershell -ExecutionPolicy Bypass -File install.ps1 -Server https://example.com/pipusher/ -Key KEY

  Options:
    -Name "Screen name"    initial screen name (defaults to the computer name)
    -Dir PATH              install folder (default %LOCALAPPDATA%\PiPusher)
    -Browser PATH          browser to use (default: Chrome, otherwise Edge)
    -NoAutostart           do not start the kiosk at login
    -NoReboot              the Reboot button in the admin panel does not restart this PC
    -NoStart               do not start the kiosk now

  Running it again updates the program and keeps the configuration and cache.
#>
param(
    [string]$Server = '',
    [string]$Key = '',
    [string]$Name = '',
    [string]$Dir = "$env:LOCALAPPDATA\PiPusher",
    [string]$Browser = '',
    [switch]$NoAutostart,
    [switch]$NoReboot,
    [switch]$NoStart
)
$ErrorActionPreference = 'Stop'
$src = $PSScriptRoot
$powershell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
New-Item -ItemType Directory -Force $Dir | Out-Null
$Dir = (Resolve-Path $Dir).Path
$kiosk = Join-Path $Dir 'pipusher-kiosk.ps1'

# Stop a running kiosk before replacing its files.
if (Test-Path $kiosk) {
    Write-Host 'Stopping the running kiosk...'
    & $powershell -NoProfile -ExecutionPolicy Bypass -File $kiosk -Stop
}

foreach ($f in 'pipusher.exe', 'pipusher-kiosk.ps1', 'uninstall.ps1', 'README.txt') {
    $from = Join-Path $src $f
    # The Windows 7/8.1 package also has a 32-bit exe.
    if ($f -eq 'pipusher.exe' -and -not [Environment]::Is64BitOperatingSystem -and (Test-Path (Join-Path $src 'pipusher-386.exe'))) {
        $from = Join-Path $src 'pipusher-386.exe'
    }
    Copy-Item $from (Join-Path $Dir $f) -Force
    try { Unblock-File (Join-Path $Dir $f) } catch {}
}

# Configuration: keep what is there, change only what was given.
$cfgPath = Join-Path $Dir 'pipusher.json'
$cfg = if (Test-Path $cfgPath) { Get-Content $cfgPath -Raw | ConvertFrom-Json } else { New-Object PSObject }
function Set-Cfg([string]$k, $v) { $cfg | Add-Member -NotePropertyName $k -NotePropertyValue $v -Force }
if ($Server) { Set-Cfg 'server_url' $Server }
if ($Key) { Set-Cfg 'registration_key' $Key }
if ($Name) { Set-Cfg 'name' $Name }
if (-not $cfg.data_dir) { Set-Cfg 'data_dir' (Join-Path $Dir 'cache') }
if ($NoReboot) { Set-Cfg 'reboot_command' @('cmd', '/c', 'echo reboot is disabled on this screen') }
elseif (-not $cfg.reboot_command) { Set-Cfg 'reboot_command' @('shutdown', '/r', '/t', '5') }
if (-not $cfg.server_url) { throw 'No server configured. Run again with -Server https://your-site/pipusher/' }
[IO.File]::WriteAllText($cfgPath, ($cfg | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))

# Shortcuts: autostart at login, plus start/stop in the Start menu.
$kioskArgs = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$kiosk`""
if ($Browser) { $kioskArgs += " -Browser `"$Browser`"" }
function New-Shortcut([string]$path, [string]$arguments, [string]$description) {
    $s = (New-Object -ComObject WScript.Shell).CreateShortcut($path)
    $s.TargetPath = $powershell
    $s.Arguments = $arguments
    $s.WorkingDirectory = $Dir
    $s.WindowStyle = 7
    $s.Description = $description
    $s.Save()
}
$startup = Join-Path ([Environment]::GetFolderPath('Startup')) 'PiPusher.lnk'
$menu = Join-Path ([Environment]::GetFolderPath('Programs')) 'PiPusher'
New-Item -ItemType Directory -Force $menu | Out-Null
New-Shortcut (Join-Path $menu 'PiPusher.lnk') $kioskArgs 'Start the PiPusher screen'
New-Shortcut (Join-Path $menu 'Stop PiPusher.lnk') "$kioskArgs -Stop" 'Stop the PiPusher screen'
if ($NoAutostart) { Remove-Item $startup -ErrorAction SilentlyContinue }
else { New-Shortcut $startup $kioskArgs 'Start the PiPusher screen at login' }

Write-Host "`n$(& (Join-Path $Dir 'pipusher.exe') -version) installed in $Dir" -ForegroundColor Green
Write-Host "Server: $($cfg.server_url)"
if (-not $cfg.registration_key) { Write-Host 'No registration key: approve the pairing code shown on the screen under Screens.' }
if (-not $NoAutostart) { Write-Host 'Starts automatically at login.' }
Write-Host 'Stop it with Start menu > PiPusher > Stop PiPusher (press the Windows key to reach the Start menu).'
if (-not $NoStart) {
    Start-Process -FilePath $powershell -ArgumentList $kioskArgs -WindowStyle Hidden
    Write-Host 'Starting the kiosk...'
}
