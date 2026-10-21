param(
  [Parameter(Mandatory = $true)] [string] $PayloadDirectory,
  [Parameter(Mandatory = $true)] [string] $Version,
  [Parameter(Mandatory = $true)] [string] $WorkDirectory,
  [Parameter(Mandatory = $true)] [string] $Output,
  # With both, the build also packs the engine executable on the same runtime.
  [string] $EnginePayloadDirectory,
  [string] $EngineOutput
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true

# Push-Location changes the working directory further down, and the packer
# resolves a relative path against its own, so the application resolves here.
$payload = (Resolve-Path $PayloadDirectory).Path
if ($EnginePayloadDirectory) {
  $enginePayload = (Resolve-Path $EnginePayloadDirectory).Path
  $engineDirectory = Split-Path -Parent $EngineOutput
  New-Item -ItemType Directory -Force -Path $engineDirectory | Out-Null
  $engineOutputPath = Join-Path (Resolve-Path $engineDirectory).Path (Split-Path -Leaf $EngineOutput)
}

$frankenphpVersion = '1.13.1'
$frankenphpCommit = '99d4e119ca2e5a90a69d8dbee5d5c9a8016416c5'
$phpVersion = '8.5.11'
$phpToolset = 'vs17-x64'
# The newest PHP release lives under releases/, older ones under releases/archives/.
$phpDownloadBases = @('https://downloads.php.net/~windows/releases', 'https://downloads.php.net/~windows/releases/archives')
$vcpkgCommit = '9e593bb18ea69cc5095e012465dcd675a822ed0d'
$downloads = [ordered]@{
  'php.zip' = @{
    Urls = $phpDownloadBases | ForEach-Object { "$_/php-$phpVersion-Win32-$phpToolset.zip" }
    Sha256 = 'c83d5a1e0d760fb026ce695d8a70a73a4c81ac224b3e5c425406b23a7d53e1de'
  }
  'php-devel.zip' = @{
    Urls = $phpDownloadBases | ForEach-Object { "$_/php-devel-pack-$phpVersion-Win32-$phpToolset.zip" }
    Sha256 = '43f610861ced7b943437fe2808cfd720fde3341f461b1d441ea18bcd49ff711d'
  }
  'watcher.tar' = @{
    Urls = @('https://github.com/e-dant/watcher/releases/download/0.14.5/x86_64-pc-windows-msvc.tar')
    Sha256 = '43034cbb07252751246afab8880da74c0340429f1865fd5bd6ce7c17e83c104f'
  }
  'vcpkg.exe' = @{
    Urls = @('https://github.com/microsoft/vcpkg-tool/releases/download/2026-07-27/vcpkg.exe')
    Sha256 = '13b8175e99a884c5ad34249218754b45541a1a63f216e92603aee57a285ac741'
  }
}
# The php.net zip leaves these out. PECL publishes a DLL per PHP minor release
# and thread-safety build.
$phpMinor = ($phpVersion -split '\.')[0..1] -join '.'
$peclExtensions = [ordered]@{
  'apcu' = @{ Version = '5.1.28'; Sha256 = 'ade68cbc6ace5002a2ecac3fda9ab38ec188d4322dd83d6867213ab8411951f0' }
  'brotli' = @{ Version = '0.21.0'; Sha256 = '5615f4e6b808dc09cd54799f068ac9ff27763317306f03a35cafef77d01a541f' }
  'yaml' = @{ Version = '2.3.0'; Sha256 = 'def9671417f65c830d0c2475012f37a1866edbfbe2c754befb32fdec6b41b666' }
}
foreach ($name in $peclExtensions.Keys) {
  $release = $peclExtensions[$name].Version
  $downloads["php_$name.zip"] = @{
    Urls = @("https://downloads.php.net/~windows/pecl/releases/$name/$release/php_$name-$release-$phpMinor-ts-$phpToolset.zip")
    Sha256 = $peclExtensions[$name].Sha256
  }
}
$extensions = Get-Content (Join-Path $PSScriptRoot '..\..\runtime\php-extensions.txt') |
  ForEach-Object { ($_ -split '#')[0].Trim() } |
  Where-Object { $_ }

function Get-PinnedFile([string] $Name, [hashtable] $Source) {
  $path = Join-Path $downloadDirectory $Name
  if ((Test-Path $path) -and (Get-FileHash $path -Algorithm SHA256).Hash -eq $Source.Sha256) {
    return $path
  }
  foreach ($url in $Source.Urls) {
    try {
      Invoke-WebRequest -Uri $url -OutFile $path
      break
    } catch {
      if ($url -eq $Source.Urls[-1]) { throw }
    }
  }
  $hash = (Get-FileHash $path -Algorithm SHA256).Hash
  if ($hash -ne $Source.Sha256) {
    Remove-Item $path
    throw "Checksum mismatch for ${Name}: $hash"
  }
  return $path
}

function Reset-Directory([string] $Path) {
  if (Test-Path $Path) { Remove-Item -Recurse -Force $Path }
  New-Item -ItemType Directory -Path $Path | Out-Null
}

$buildTools = & "${env:ProgramFiles(x86)}\Microsoft Visual Studio\Installer\vswhere.exe" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Llvm.Clang Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if (-not $buildTools) { throw 'Visual Studio Build Tools with the C++ Clang and x64 build tools components are required' }

$work = New-Item -ItemType Directory -Force -Path $WorkDirectory
$downloadDirectory = New-Item -ItemType Directory -Force -Path (Join-Path $work 'downloads')
$files = @{}
foreach ($name in $downloads.Keys) { $files[$name] = Get-PinnedFile $name $downloads[$name] }

$php = Join-Path $work 'php'
$phpDevel = Join-Path $work 'php-devel'
$watcher = Join-Path $work 'watcher'
$frankenphp = Join-Path $work 'frankenphp'
$runtime = Join-Path $work 'runtime'
$frankenphpExecutable = Join-Path $runtime 'frankenphp.exe'
foreach ($directory in $php, $phpDevel, $watcher, $runtime) { Reset-Directory $directory }
Expand-Archive -Path $files['php.zip'] -DestinationPath $php
Expand-Archive -Path $files['php-devel.zip'] -DestinationPath $phpDevel
# Only the extension DLL joins ext. brotli's zip also carries brotlienc.dll, and
# php_brotli loads the vcpkg brotli libraries the runtime ships instead.
foreach ($name in $peclExtensions.Keys) {
  $pecl = Join-Path $work "pecl-$name"
  Reset-Directory $pecl
  Expand-Archive -Path $files["php_$name.zip"] -DestinationPath $pecl
  Copy-Item (Join-Path $pecl "php_$name.dll") (Join-Path $php 'ext')
}
tar -xf $files['watcher.tar'] -C $watcher
$phpDevelRoot = Join-Path $phpDevel "php-$phpVersion-devel-$phpToolset"
$watcherRoot = Join-Path $watcher 'x86_64-pc-windows-msvc'

$vcpkg = Join-Path $work 'vcpkg'
if (-not (Test-Path (Join-Path $vcpkg '.git'))) {
  Reset-Directory $vcpkg
  git -C $vcpkg init --quiet
  git -C $vcpkg remote add origin https://github.com/microsoft/vcpkg.git
}
git -C $vcpkg fetch --quiet --depth 1 origin $vcpkgCommit
git -C $vcpkg checkout --quiet --force $vcpkgCommit
Copy-Item $files['vcpkg.exe'] (Join-Path $vcpkg 'vcpkg.exe') -Force

if (Test-Path $frankenphp) { Remove-Item -Recurse -Force $frankenphp }
git -c core.autocrlf=false clone --quiet --depth 1 --branch "v$frankenphpVersion" https://github.com/php/frankenphp.git $frankenphp
# A tag can move, so the checkout must match the pinned commit.
$frankenphpHead = git -C $frankenphp rev-parse HEAD
if ($frankenphpHead -ne $frankenphpCommit) { throw "FrankenPHP v$frankenphpVersion resolved to $frankenphpHead, expected $frankenphpCommit" }
$vcpkgInstalled = Join-Path $frankenphp 'vcpkg_installed'
& (Join-Path $vcpkg 'vcpkg.exe') install --vcpkg-root=$vcpkg --x-manifest-root=$frankenphp --x-install-root=$vcpkgInstalled --triplet=x64-windows --disable-metrics
$vcpkgRoot = Join-Path $vcpkgInstalled 'x64-windows'

# The launcher carries the application, so the server embeds an empty archive,
# which leaves frankenphp's own extraction unused. Its embed directive still
# needs both files to exist.
Set-Content -Path (Join-Path $frankenphp 'app.tar') -Value $null -NoNewline
Copy-Item (Join-Path $payload 'app_checksum.txt') (Join-Path $frankenphp 'app_checksum.txt')
Copy-Item (Join-Path $PSScriptRoot '..\..\runtime\entrypoint.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack.go')
Copy-Item (Join-Path $PSScriptRoot '..\..\runtime\watch\watch.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack_watch.go')
Copy-Item (Join-Path $PSScriptRoot '..\..\runtime\tls\tls.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack_tls.go')

# php.net publishes x64 builds alone, so a Windows on Arm host builds the amd64
# runtime too, with its own clang. cmd/pack builds the launcher for its own
# architecture, which GOARCH also sets for go run below.
$clangHost = if ([System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture -eq 'Arm64') { 'ARM64' } else { 'x64' }
$env:PATH = @("$buildTools\VC\Tools\Llvm\$clangHost\bin", "$vcpkgRoot\bin", $watcherRoot, $php, $env:PATH) -join ';'
$env:GOARCH = 'amd64'
# Go turns cgo off by default when GOARCH differs from the host's.
$env:CGO_ENABLED = '1'
$env:CC = 'clang --target=x86_64-pc-windows-msvc'
$env:CXX = 'clang++ --target=x86_64-pc-windows-msvc'
$env:CGO_CFLAGS = "-DFRANKENPHP_VERSION=$frankenphpVersion -I$vcpkgRoot\include -I$watcherRoot -I$phpDevelRoot\include -I$phpDevelRoot\include\main -I$phpDevelRoot\include\TSRM -I$phpDevelRoot\include\Zend -I$phpDevelRoot\include\ext"
$env:CGO_LDFLAGS = "-L$vcpkgRoot\lib -lbrotlienc -L$watcherRoot -llibwatcher-c -L$php -L$phpDevelRoot\lib -lphp8ts -lphp8embed"
# The SQLite release the zip's PHP loads, for --version.
$sqliteVersion = & (Join-Path $php 'php.exe') -n -d "extension_dir=$php\ext" -d extension=sqlite3 -r 'echo current(SQLite3::version());'
if (-not $sqliteVersion) { throw 'php.exe named no SQLite release' }
Push-Location (Join-Path $frankenphp 'caddy\frankenphp')
try {
  go build '-tags=nobadger,nomysql,nopgx' -ldflags="-extldflags=-fuse-ld=lld -X 'main.version=$Version' -X 'main.frankenphpVersion=$frankenphpVersion' -X 'main.sqliteVersion=$sqliteVersion' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphpVersion PHP $phpVersion Caddy'" -o $frankenphpExecutable
} finally {
  Pop-Location
}

Get-ChildItem $php -File | Where-Object Extension -ne '.exe' | Copy-Item -Destination $runtime
Copy-Item -Recurse (Join-Path $php 'ext') (Join-Path $runtime 'ext')
Copy-Item (Join-Path $watcherRoot 'libwatcher-c.dll') $runtime
foreach ($library in 'brotlienc.dll', 'brotlidec.dll', 'brotlicommon.dll', 'pthreadVC3.dll') {
  Copy-Item (Join-Path $vcpkgRoot "bin\$library") $runtime
}

# The repository's php.ini is the only source of PHP settings; this build only adds
# what only it can supply: where this PHP puts its extensions, and which to load. The
# launcher sets PHPRC to the extracted runtime directory.
Copy-Item (Join-Path $PSScriptRoot '..\..\application\php.ini') (Join-Path $runtime 'php.ini')
# A listed extension the zip carries as a DLL gets a php.ini line. The rest are
# compiled into php8ts.dll or absent on Windows. The conformance suite checks the
# loaded set against the allowlist on the packed executable.
$dllExtensions = $extensions | Where-Object { Test-Path (Join-Path $php "ext\php_$_.dll") }
Add-Content -Path (Join-Path $runtime 'php.ini') -Value (@('extension_dir = "${PHPRC}\ext"') + ($dllExtensions | ForEach-Object { "extension=$_" }))
Copy-Item (Join-Path $PSScriptRoot '..\..\application\cacert.pem') $runtime

# The packer resolves a relative -output against its own working directory,
# which Push-Location is about to change, so $Output must resolve here.
$outputDirectory = Split-Path -Parent $Output
if (-not $outputDirectory) { $outputDirectory = '.' }
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
$outputPath = Join-Path (Resolve-Path $outputDirectory).Path (Split-Path -Leaf $Output)
$launcherSource = (Resolve-Path (Join-Path $PSScriptRoot '..\..\launcher')).Path
# The payload of a site asking for Node holds each target's verified archive.
$nodeArchive = Join-Path $payload 'node\windows-amd64.zip'
$node = if (Test-Path $nodeArchive) { @('-node', $nodeArchive) } else { @() }
$env:CGO_ENABLED = '0'
Push-Location $launcherSource
try {
  go run ./cmd/pack -runtime $runtime -entry frankenphp.exe -version $Version -source $launcherSource -output $outputPath -app (Join-Path $payload 'app-payload.tar') -app-checksum (Join-Path $payload 'app_checksum.txt') -site (Join-Path $payload 'site.json') -site-version $Version @node
  if ($EnginePayloadDirectory) {
    go run ./cmd/pack -engine -runtime $runtime -entry frankenphp.exe -version $Version -source $launcherSource -output $engineOutputPath -app (Join-Path $enginePayload 'app-payload.tar') -app-checksum (Join-Path $enginePayload 'app_checksum.txt')
  }
} finally {
  Pop-Location
}
