<#
  Downloads the tools needed to build and test PiPusher into .\tools (no admin rights needed):
    tools\go       Go toolchain (cross-compiles the Raspberry Pi client)
    tools\go1.20   Go 1.20, the last Go that runs on Windows 7/8/8.1 (builds that Windows client)
    tools\php      PHP for Windows (run the server locally:  .\tools\php\php.exe -S 127.0.0.1:8088 -t server)
#>
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$tools = Join-Path $PSScriptRoot 'tools'
$dl = Join-Path $tools 'dl'
New-Item -ItemType Directory -Force $dl | Out-Null

if (-not (Test-Path "$tools\go\bin\go.exe")) {
    $rel = (Invoke-RestMethod 'https://go.dev/dl/?mode=json')[0]
    $file = $rel.files | Where-Object { $_.os -eq 'windows' -and $_.arch -eq 'amd64' -and $_.kind -eq 'archive' }
    Write-Host "Downloading $($file.filename)"
    Invoke-WebRequest "https://go.dev/dl/$($file.filename)" -OutFile "$dl\go.zip"
    if ((Get-FileHash "$dl\go.zip" -Algorithm SHA256).Hash -ne $file.sha256.ToUpper()) { throw 'Go checksum mismatch' }
    Expand-Archive "$dl\go.zip" $tools -Force
}

# Go 1.20 is the last release that runs on Windows 7/8/8.1; build.ps1 makes that client with it.
if (-not (Test-Path "$tools\go1.20\bin\go.exe")) {
    $rel = (Invoke-RestMethod 'https://go.dev/dl/?mode=json&include=all') | Where-Object version -eq 'go1.20.14'
    $file = $rel.files | Where-Object { $_.os -eq 'windows' -and $_.arch -eq 'amd64' -and $_.kind -eq 'archive' }
    Write-Host "Downloading $($file.filename)"
    Invoke-WebRequest "https://go.dev/dl/$($file.filename)" -OutFile "$dl\go120.zip"
    if ((Get-FileHash "$dl\go120.zip" -Algorithm SHA256).Hash -ne $file.sha256.ToUpper()) { throw 'Go 1.20 checksum mismatch' }
    Expand-Archive "$dl\go120.zip" "$dl\go120" -Force
    Move-Item "$dl\go120\go" "$tools\go1.20"
}

if (-not (Test-Path "$tools\php\php.exe")) {
    $rel = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
    $zip = $rel.'8.4'.'nts-vs17-x64'.zip
    Write-Host "Downloading $($zip.path)"
    Invoke-WebRequest "https://windows.php.net/downloads/releases/$($zip.path)" -OutFile "$dl\php.zip"
    if ((Get-FileHash "$dl\php.zip" -Algorithm SHA256).Hash -ne $zip.sha256.ToUpper()) { throw 'PHP checksum mismatch' }
    Expand-Archive "$dl\php.zip" "$tools\php" -Force
    $ini = (Get-Content "$tools\php\php.ini-development") `
        -replace '^;extension_dir = "ext"', 'extension_dir = "ext"' `
        -replace '^;extension=(pdo_sqlite|zip|fileinfo|mbstring)$', 'extension=$1' `
        -replace '^upload_max_filesize = .*', 'upload_max_filesize = 512M' `
        -replace '^post_max_size = .*', 'post_max_size = 520M'
    Set-Content "$tools\php\php.ini" $ini -Encoding ascii
}

Remove-Item -Recurse -Force $dl
& "$tools\go\bin\go.exe" version
& "$tools\php\php.exe" -v | Select-Object -First 1
