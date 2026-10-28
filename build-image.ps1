<#
  Builds ready-to-flash PiPusher images for Raspberry Pi, based on DietPi (minimal Debian).

    .\build-image.ps1                     # generic images for all models (run .\build.ps1 first)
    .\build-image.ps1 -Model RPi234       # only Pi 2 v1.2 / 3 / 4 / 400 / Zero 2 W
    .\build-image.ps1 -Server https://example.com/pipusher/ -Key KEY -WifiSsid Office -WifiKey secret -Password ...
                                          # personal image with the settings filled in (keep it private)

  The image only runs what a screen needs: X11 with Chromium (no desktop), the PiPusher agent, the
  network and the Dropbear SSH server, with zram swap and RAM logging. DietPi installs X11 and Chromium
  on the first start (needs internet), then the Pi restarts into the PiPusher kiosk.
  Settings are in pipusher.txt on the SD card's boot drive (image\dietpi\pipusher.txt).

  Output:  dist\pipusher-dietpi-<model>-<ver>.img.xz (+ .sha256)
  The DietPi base images are downloaded once (checksum verified) into tools\dietpi\.
#>
param(
    [ValidateSet('RPi234', 'RPi5', 'All')][string]$Model = 'All',
    [string]$Server = '',
    [string]$Key = '',
    [string]$Name = '',
    [string]$Password = '',
    [string]$WifiSsid = '',
    [string]$WifiKey = '',
    [string]$WifiCountry = 'DK',
    [string]$Timezone = 'Europe/Copenhagen',
    [string]$Keyboard = 'dk',
    [string]$SshKey = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest is very slow with a progress bar
$root = $PSScriptRoot
$Version = (Get-Content "$root\VERSION" -Raw).Trim()
$dist = Join-Path $root 'dist'
$kit = Join-Path $root 'image\dietpi'
$cache = Join-Path $root 'tools\dietpi'
$work = Join-Path $dist '_image'
$go = Join-Path $root 'tools\go\bin\go.exe'
$tar = "$env:SystemRoot\System32\tar.exe"
$xz = (Get-Command xz -ErrorAction SilentlyContinue).Source
if (-not $xz) {
    $xz = @("$env:ProgramFiles\Git\mingw64\bin\xz.exe", "$env:ProgramFiles\Git\usr\bin\xz.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
}
if (-not $xz) { throw 'xz.exe not found. It comes with Git for Windows.' }
$clientTar = Join-Path $dist "pipusher-client-$Version.tar.gz"
if (-not (Test-Path $clientTar)) { throw "Missing $clientTar. Run .\build.ps1 first." }
$custom = [bool]($Server -or $Key -or $Name -or $Password -or $WifiSsid -or $SshKey)
if (-not $Password) { $Password = 'pipusher' }

if (Test-Path $work) { Remove-Item -Recurse -Force $work }
New-Item -ItemType Directory -Force $work, $cache | Out-Null

# The agent from the client package of this version.
& $tar -xzf $clientTar -C $work "pipusher-client-$Version/pipusher-linux-arm64"
if ($LASTEXITCODE) { throw 'could not extract the agent from the client package' }
$agent = Join-Path $work "pipusher-client-$Version\pipusher-linux-arm64"

# The boot partition editor (image\mkimage).
Push-Location (Join-Path $root 'image\mkimage')
try {
    & $go build -o "$work\mkimage.exe" .
    if ($LASTEXITCODE) { throw 'mkimage build failed' }
} finally { Pop-Location }

# Kit files with LF line endings (shell scripts must have them; the text files are fine either way).
$utf8 = New-Object System.Text.UTF8Encoding($false)
$kitOut = Join-Path $work 'kit'
New-Item -ItemType Directory -Force "$kitOut\pipusher" | Out-Null
foreach ($f in 'Automation_Custom_PreScript.sh', 'Automation_Custom_Script.sh', 'pipusher.txt', 'PIPUSHER-README.txt',
    'pipusher\pipusher-bootconfig.sh', 'pipusher\chromium-flags.sh') {
    $text = [IO.File]::ReadAllText((Join-Path $kit $f)) -replace "`r`n", "`n"
    [IO.File]::WriteAllText((Join-Path $kitOut $f), $text, $utf8)
}

$models = if ($Model -eq 'All') { @('RPi234', 'RPi5') } else { @($Model) }
foreach ($m in $models) {
    Write-Host "PiPusher $Version image for $m" -ForegroundColor Cyan

    # DietPi base image: downloaded once, re-downloaded when DietPi publishes a new one.
    $base = "DietPi_$m-ARMv8-Trixie.img"
    $url = "https://dietpi.com/downloads/images/$base.xz"
    $want = ((Invoke-WebRequest "$url.sha256" -UseBasicParsing).Content -split '\s+')[0].ToLower()
    $xzFile = Join-Path $cache "$base.xz"
    if (-not (Test-Path $xzFile) -or (Get-FileHash $xzFile -Algorithm SHA256).Hash.ToLower() -ne $want) {
        Write-Host "  downloading $url"
        Invoke-WebRequest $url -OutFile $xzFile -UseBasicParsing
        if ((Get-FileHash $xzFile -Algorithm SHA256).Hash.ToLower() -ne $want) { throw "checksum mismatch for $base.xz" }
    }
    $img = Join-Path $work "pipusher-dietpi-$($m.ToLower())-$Version$(if ($custom) { '-custom' }).img"
    Write-Host '  unpacking'
    & $xz -dkf -T0 $xzFile
    if ($LASTEXITCODE) { throw 'xz unpack failed' }
    Move-Item -Force (Join-Path $cache $base) $img

    $edits = @(
        "-put", "$kitOut\Automation_Custom_PreScript.sh:/Automation_Custom_PreScript.sh",
        "-put", "$kitOut\Automation_Custom_Script.sh:/Automation_Custom_Script.sh",
        "-put", "$kitOut\pipusher.txt:/pipusher.txt",
        "-put", "$kitOut\PIPUSHER-README.txt:/PIPUSHER-README.txt",
        "-put", "$kitOut\pipusher\pipusher-bootconfig.sh:/pipusher/pipusher-bootconfig.sh",
        "-put", "$kitOut\pipusher\chromium-flags.sh:/pipusher/chromium-flags.sh",
        "-put", "${agent}:/pipusher/pipusher-linux-arm64"
    )
    # DietPi first boot: fully automatic, only Chromium (+ X11, ALSA) on top of the base system.
    $dietpi = [ordered]@{
        AUTO_SETUP_AUTOMATED = 1
        AUTO_SETUP_GLOBAL_PASSWORD = $Password
        AUTO_SETUP_NET_HOSTNAME = 'PiPusher'
        AUTO_SETUP_TIMEZONE = $Timezone
        AUTO_SETUP_KEYBOARD_LAYOUT = $Keyboard
        AUTO_SETUP_NET_WIFI_COUNTRY_CODE = $WifiCountry
        AUTO_SETUP_SWAPFILE_SIZE = 1                 # automatic size ...
        AUTO_SETUP_SWAPFILE_LOCATION = 'zram'        # ... compressed in RAM, no SD card wear
        AUTO_SETUP_SSH_SERVER_INDEX = -1             # Dropbear: the smallest SSH server
        AUTO_SETUP_LOGGING_INDEX = -1                # logs in RAM, cleared hourly
        AUTO_SETUP_BROWSER_INDEX = -2
        AUTO_SETUP_INSTALL_SOFTWARE_ID = 'chromium'
        AUTO_SETUP_AUTOSTART_TARGET_INDEX = 11       # Chromium kiosk, no desktop
        AUTO_SETUP_AUTOSTART_LOGIN_USER = 'dietpi'
        SOFTWARE_CHROMIUM_AUTOSTART_URL = 'http://127.0.0.1:8090/'
        SOFTWARE_CHROMIUM_RES_X = 1920               # replaced by the screen's size at every boot
        SOFTWARE_CHROMIUM_RES_Y = 1080
        CONFIG_SERIAL_CONSOLE_ENABLE = 0             # one login process less
        SURVEY_OPTED_IN = 0
    }
    if ($SshKey) { $dietpi['AUTO_SETUP_SSH_PUBKEY'] = $SshKey }
    foreach ($k in $dietpi.Keys) { $edits += '-set', "/dietpi.txt:$k=$($dietpi[$k])" }
    # Graphics: the KMS driver lets Chromium draw with the GPU. Bluetooth off.
    $edits += '-append', '/config.txt:dtoverlay=vc4-kms-v3d', '-append', '/config.txt:max_framebuffers=2',
        '-append', '/config.txt:dtoverlay=disable-bt'
    # Personal image: fill in pipusher.txt.
    $txt = [ordered]@{ SERVER = $Server; KEY = $Key; NAME = $Name; PASSWORD = $Password; WIFI_SSID = $WifiSsid; WIFI_KEY = $WifiKey; WIFI_COUNTRY = $WifiCountry }
    foreach ($k in $txt.Keys) { if ($txt[$k]) { $edits += '-set', "/pipusher.txt:$k=$($txt[$k])" } }

    & "$work\mkimage.exe" -img $img @edits | ForEach-Object { "  $_" }
    if ($LASTEXITCODE) { throw 'editing the image failed' }

    Write-Host '  compressing'
    & $xz -T0 -6 -f $img
    if ($LASTEXITCODE) { throw 'xz compress failed' }
    $out = Join-Path $dist ((Split-Path $img -Leaf) + '.xz')
    Move-Item -Force "$img.xz" $out
    $hash = (Get-FileHash $out -Algorithm SHA256).Hash.ToLower()
    [IO.File]::WriteAllText("$out.sha256", "$hash  $(Split-Path $out -Leaf)`n", $utf8)
    '  {0}  {1:N0} bytes' -f (Split-Path $out -Leaf), (Get-Item $out).Length
}
Remove-Item -Recurse -Force $work
if ($custom) {
    Write-Host "`nThis image contains your settings (server, key, Wi-Fi, password): do not share or upload it." -ForegroundColor Yellow
}
