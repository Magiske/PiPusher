<#
  Removes the PiPusher client from Windows.

    powershell -ExecutionPolicy Bypass -File uninstall.ps1           keeps pipusher.json and the cache
    powershell -ExecutionPolicy Bypass -File uninstall.ps1 -Purge    removes everything
#>
param([switch]$Purge, [string]$Dir = "$env:LOCALAPPDATA\PiPusher")
$ErrorActionPreference = 'Stop'
$kiosk = Join-Path $Dir 'pipusher-kiosk.ps1'
if (Test-Path $kiosk) {
    & "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File $kiosk -Stop
}
# rd with a \\?\ path also removes browser profile files deeper than 260 characters.
function Remove-Tree([string]$path) {
    if (Test-Path $path -PathType Container) { cmd /c rd /s /q "\\?\$path" }
    elseif (Test-Path $path) { Remove-Item $path -Force }
}

Remove-Item (Join-Path ([Environment]::GetFolderPath('Startup')) 'PiPusher.lnk') -ErrorAction SilentlyContinue
Remove-Item (Join-Path ([Environment]::GetFolderPath('Programs')) 'PiPusher') -Recurse -ErrorAction SilentlyContinue

if ($Purge) {
    Remove-Tree $Dir
    Write-Host "PiPusher removed, including $Dir"
} else {
    foreach ($f in 'pipusher.exe', 'pipusher-kiosk.ps1', 'uninstall.ps1', 'README.txt', 'browser-profile', 'logs') {
        Remove-Tree (Join-Path $Dir $f)
    }
    Write-Host "PiPusher removed. The configuration and cache are kept in $Dir (use -Purge to remove them)."
}
