<#
  Builds the PiPusher Android app (phones, tablets and TV boxes) into .\dist. Run it only when an Android build is
  wanted; build.ps1 does not call it. Uses the version in the VERSION file (bump it with build.ps1 first).

    .\build-android.ps1

  The first run downloads Java 17 and the Android SDK (platform 34, build tools 34.0.0) into tools\, which accepts
  Google's Android SDK license, and creates the signing key tools\android-release.jks. Keep a copy of that key:
  an installed app only takes updates signed with the same key (otherwise uninstall first, losing its settings).

  The app is plain Java with no libraries, built without Gradle: aapt2 (resources), javac, d8, zipalign, apksigner.
  It carries the Go agent as lib/<abi>/libpipusher.so:
    arm64-v8a     GOOS=android (no cgo needed)
    armeabi-v7a   GOOS=linux, GOARM=7  } Android builds for these need cgo (the NDK); static Linux builds run on
    x86_64        GOOS=linux           } Android too, with the pipusher_android tag (time zones, reports "android")
    x86           GOOS=linux, 386      } (x86: 32-bit x86 boxes and the Android TV emulator images)

  Output:
    dist\pipusher-android-experimental-<ver>.apk   (the app is experimental)
#>
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$root = $PSScriptRoot
$tools = Join-Path $root 'tools'

# Runs a tool, printing its output; stderr (javac notes, d8 warnings) must not stop the script in PowerShell 5.1.
function Invoke-Tool([string]$exe, [string[]]$arguments, [string]$what) {
    $ErrorActionPreference = 'Continue'
    & $exe @arguments 2>&1 | ForEach-Object { "$_" } | Where-Object { $_ -ne '' } | Write-Host
    if ($LASTEXITCODE) { throw "$what failed" }
}

$Version = (Get-Content (Join-Path $root 'VERSION') -Raw).Trim()
if ($Version -notmatch '^(\d+)\.(\d+)\.(\d+)$') { throw "VERSION must look like 1.2.3, got '$Version'" }
$versionCode = [int]$Matches[1] * 10000 + [int]$Matches[2] * 100 + [int]$Matches[3]
Write-Host "PiPusher for Android $Version ($versionCode)" -ForegroundColor Cyan

# ---------------------------------------------------------------- tools
$jdk = Join-Path $tools 'jdk17'
$sdk = Join-Path $tools 'android-sdk'
$buildTools = Join-Path $sdk 'build-tools\34.0.0'
$androidJar = Join-Path $sdk 'platforms\android-34\android.jar'
$dl = Join-Path $tools 'dl'
if (-not (Test-Path "$jdk\bin\javac.exe")) {
    New-Item -ItemType Directory -Force $dl | Out-Null
    Write-Host 'Downloading Java 17 (Eclipse Temurin)'
    Invoke-WebRequest 'https://api.adoptium.net/v3/binary/latest/17/ga/windows/x64/jdk/hotspot/normal/eclipse' -OutFile "$dl\jdk17.zip"
    Expand-Archive "$dl\jdk17.zip" "$dl\jdk" -Force
    Move-Item (Get-ChildItem "$dl\jdk" -Directory | Select-Object -First 1).FullName $jdk
}
$env:JAVA_HOME = $jdk
$env:PATH = "$jdk\bin;$env:PATH"
if (-not (Test-Path $androidJar) -or -not (Test-Path "$buildTools\aapt2.exe")) {
    $sdkmanager = Join-Path $sdk 'cmdline-tools\latest\bin\sdkmanager.bat'
    if (-not (Test-Path $sdkmanager)) {
        New-Item -ItemType Directory -Force $dl | Out-Null
        Write-Host 'Downloading the Android SDK command-line tools'
        Invoke-WebRequest 'https://dl.google.com/android/repository/commandlinetools-win-11076708_latest.zip' -OutFile "$dl\cmdline-tools.zip"
        Expand-Archive "$dl\cmdline-tools.zip" "$dl\clt" -Force
        New-Item -ItemType Directory -Force "$sdk\cmdline-tools" | Out-Null
        Move-Item "$dl\clt\cmdline-tools" "$sdk\cmdline-tools\latest"
    }
    Write-Host 'Installing Android platform 34 and build tools 34.0.0 (accepting the Android SDK license)'
    $ErrorActionPreference = 'Continue'
    ("y`n" * 20) | & $sdkmanager "--sdk_root=$sdk" --licenses 2>&1 | Out-Null
    & $sdkmanager "--sdk_root=$sdk" 'platforms;android-34' 'build-tools;34.0.0' 2>&1 | Out-Null
    $ErrorActionPreference = 'Stop'
    if (-not (Test-Path $androidJar)) { throw 'Android SDK install failed' }
}
if (Test-Path $dl) { Remove-Item -Recurse -Force $dl }

$go = Join-Path $tools 'go\bin\go.exe'
if (-not (Test-Path $go)) { throw 'Go not found. Run .\setup-tools.ps1 first.' }

# ---------------------------------------------------------------- the agent (Go) for each ABI
Copy-Item "$root\server\assets\player\player.js", "$root\server\assets\player\player.css" "$root\client\web\" -Force
$dist = Join-Path $root 'dist'
$work = Join-Path $dist '_android'
if (Test-Path $work) { Remove-Item -Recurse -Force $work }
New-Item -ItemType Directory -Force "$work\stage", "$work\gen", "$work\classes", "$work\dex" | Out-Null
$abis = @(
    @{ Abi = 'arm64-v8a'; GOOS = 'android'; GOARCH = 'arm64'; GOARM = '' },
    @{ Abi = 'armeabi-v7a'; GOOS = 'linux'; GOARCH = 'arm'; GOARM = '7' },
    @{ Abi = 'x86_64'; GOOS = 'linux'; GOARCH = 'amd64'; GOARM = '' },
    @{ Abi = 'x86'; GOOS = 'linux'; GOARCH = '386'; GOARM = '' }
)
$saved = @{ GOOS = $env:GOOS; GOARCH = $env:GOARCH; GOARM = $env:GOARM; CGO_ENABLED = $env:CGO_ENABLED }
Push-Location "$root\client"
try {
    $env:CGO_ENABLED = '0'
    foreach ($t in $abis) {
        $env:GOOS = $t.GOOS; $env:GOARCH = $t.GOARCH; $env:GOARM = $t.GOARM
        Write-Host "Building the agent for $($t.Abi) ($($t.GOOS)/$($t.GOARCH))"
        New-Item -ItemType Directory -Force "$work\stage\lib\$($t.Abi)" | Out-Null
        Invoke-Tool $go @('build', '-trimpath', '-tags', 'pipusher_android', '-ldflags', "-s -w -X main.version=$Version",
            '-o', "$work\stage\lib\$($t.Abi)\libpipusher.so", '.') "agent build for $($t.Abi)"
    }
} finally {
    Pop-Location
    foreach ($k in $saved.Keys) { Set-Item "env:$k" $saved[$k] }
}

# ---------------------------------------------------------------- the app
$aapt2 = Join-Path $buildTools 'aapt2.exe'
Write-Host 'Building the app'
Invoke-Tool $aapt2 @('compile', '--dir', "$root\android\res", '-o', "$work\res.zip") 'aapt2 compile'
Invoke-Tool $aapt2 @('link', '-o', "$work\base.apk", '-I', $androidJar, '--manifest', "$root\android\AndroidManifest.xml",
    '--min-sdk-version', '21', '--target-sdk-version', '28', '--version-code', "$versionCode", '--version-name', $Version,
    '--java', "$work\gen", "$work\res.zip") 'aapt2 link'

# Lambdas compile against the build tools' LambdaMetafactory stub; d8 turns them into plain classes.
$sources = @(Get-ChildItem "$root\android\src", "$work\gen" -Recurse -Filter *.java | ForEach-Object FullName)
Invoke-Tool "$jdk\bin\javac.exe" (@('-source', '8', '-target', '8', '-Xlint:-options', '-encoding', 'UTF-8',
        '-bootclasspath', "$androidJar;$buildTools\core-lambda-stubs.jar", '-d', "$work\classes") + $sources) 'javac'
$classes = @(Get-ChildItem "$work\classes" -Recurse -Filter *.class | ForEach-Object FullName)
Invoke-Tool "$buildTools\d8.bat" (@('--release', '--min-api', '21', '--lib', $androidJar, '--output', "$work\dex") + $classes) 'd8'
Copy-Item "$work\dex\classes.dex" "$work\stage\classes.dex"

# classes.dex and the agent go into the APK next to the resources.
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::Open("$work\base.apk", 'Update')
try {
    $stage = (Resolve-Path "$work\stage").Path
    foreach ($f in Get-ChildItem $stage -Recurse -File) {
        $name = $f.FullName.Substring($stage.Length + 1).Replace('\', '/')
        [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f.FullName, $name, 'Optimal')
    }
} finally {
    $zip.Dispose()
}
Invoke-Tool "$buildTools\zipalign.exe" @('-p', '-f', '4', "$work\base.apk", "$work\aligned.apk") 'zipalign'

# ---------------------------------------------------------------- signing
$ks = Join-Path $tools 'android-release.jks'
if (-not (Test-Path $ks)) {
    Write-Host "Creating the signing key $ks (keep a copy: updates must be signed with it)" -ForegroundColor Yellow
    Invoke-Tool "$jdk\bin\keytool.exe" @('-genkeypair', '-keystore', $ks, '-alias', 'pipusher', '-keyalg', 'RSA', '-keysize', '2048',
        '-validity', '10000', '-dname', 'CN=PiPusher', '-storepass', 'pipusher', '-keypass', 'pipusher') 'keytool'
}
New-Item -ItemType Directory -Force $dist | Out-Null
$apk = Join-Path $dist "pipusher-android-experimental-$Version.apk"
Invoke-Tool "$buildTools\apksigner.bat" @('sign', '--ks', $ks, '--ks-key-alias', 'pipusher', '--ks-pass', 'pass:pipusher',
    '--key-pass', 'pass:pipusher', '--out', $apk, "$work\aligned.apk") 'apksigner'
Invoke-Tool "$buildTools\apksigner.bat" @('verify', $apk) 'APK verification'
Remove-Item -Recurse -Force $work
Get-Item $apk | ForEach-Object { Write-Host ("`nDone:`n  {0,-40} {1,12:N0} bytes" -f $_.Name, $_.Length) }
