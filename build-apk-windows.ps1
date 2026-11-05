# Builds BoholHealthWatch-debug.apk on Windows. Run it with build-apk-windows.bat.
# First run downloads a JDK and the Android SDK (about 1 GB) into .buildtools next to this file.
# Nothing is installed system-wide. Delete .buildtools to remove them.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$tools = Join-Path $root '.buildtools'
$sdk = Join-Path $tools 'android-sdk'
New-Item -ItemType Directory -Force -Path $tools | Out-Null

# JDK 17
$jdk = Get-ChildItem $tools -Directory -Filter 'jdk-17*' -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $jdk) {
    Write-Host 'Downloading JDK 17...'
    $zip = Join-Path $tools 'jdk.zip'
    Invoke-WebRequest 'https://api.adoptium.net/v3/binary/latest/17/ga/windows/x64/jdk/hotspot/normal/eclipse' -OutFile $zip
    Expand-Archive $zip -DestinationPath $tools -Force
    Remove-Item $zip
    $jdk = Get-ChildItem $tools -Directory -Filter 'jdk-17*' | Select-Object -First 1
}
$env:JAVA_HOME = $jdk.FullName
$env:Path = (Join-Path $jdk.FullName 'bin') + ';' + $env:Path

# Android command-line tools
$sdkmanager = Join-Path $sdk 'cmdline-tools\latest\bin\sdkmanager.bat'
if (-not (Test-Path $sdkmanager)) {
    Write-Host 'Downloading Android command-line tools...'
    $zip = Join-Path $tools 'cmdline.zip'
    Invoke-WebRequest 'https://dl.google.com/android/repository/commandlinetools-win-11076708_latest.zip' -OutFile $zip
    $dest = Join-Path $sdk 'cmdline-tools'
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    Expand-Archive $zip -DestinationPath $dest -Force
    Rename-Item (Join-Path $dest 'cmdline-tools') 'latest'
    Remove-Item $zip
}
$env:ANDROID_HOME = $sdk
$env:ANDROID_SDK_ROOT = $sdk

# Running this script is your acceptance of the Android SDK licenses, which this command prints.
Write-Host 'Installing Android SDK packages...'
$yes = (1..30 | ForEach-Object { 'y' }) -join "`n"
$yes | & $sdkmanager --sdk_root=$sdk --licenses | Out-Host
& $sdkmanager --sdk_root=$sdk 'platform-tools' 'platforms;android-35' 'build-tools;35.0.0' | Out-Host

# Build
Write-Host 'Building the APK...'
Push-Location (Join-Path $root 'android')
try {
    & .\gradlew.bat --no-daemon assembleDebug
    if ($LASTEXITCODE -ne 0) { throw "Gradle failed with exit code $LASTEXITCODE. Send this window's text to Claude." }
} finally {
    Pop-Location
}

$apk = Join-Path $root 'BoholHealthWatch-debug.apk'
Copy-Item (Join-Path $root 'android\app\build\outputs\apk\debug\app-debug.apk') $apk -Force
Write-Host ''
Write-Host "Done: $apk"
