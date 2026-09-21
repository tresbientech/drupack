param(
  [Parameter(Mandatory = $true)] [string] $ApplicationDirectory,
  [Parameter(Mandatory = $true)] [string] $Version,
  [Parameter(Mandatory = $true)] [string] $WorkDirectory,
  [Parameter(Mandatory = $true)] [string] $Output
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true

$frankenphpVersion = '1.12.7'
$frankenphpCommit = 'a765b086f5cc56f6b7753117367d56e1b0da948d'
$phpVersion = '8.5.10'
$phpToolset = 'vs17-x64'
# The newest PHP release lives under releases/, older ones under releases/archives/.
$phpDownloadBases = @('https://downloads.php.net/~windows/releases', 'https://downloads.php.net/~windows/releases/archives')
$vcpkgCommit = '9e593bb18ea69cc5095e012465dcd675a822ed0d'
$downloads = [ordered]@{
  'php.zip' = @{
    Urls = $phpDownloadBases | ForEach-Object { "$_/php-$phpVersion-Win32-$phpToolset.zip" }
    Sha256 = 'a6bc8b2f3d7bfb397ccb973db2f959e61e530e0986c9cea262dd4a317ec599d8'
  }
  'php-devel.zip' = @{
    Urls = $phpDownloadBases | ForEach-Object { "$_/php-devel-pack-$phpVersion-Win32-$phpToolset.zip" }
    Sha256 = '0031d279f13f21e81fd62f9a98e919f28b1875ba457916d60daed85586e479dd'
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
# Extensions Drupal, Drush, Local MCP Tools and MCP Server need. The Linux executable loads a larger set.
# The PHP zip ships the first list as DLLs in ext\ and compiles the second into php8ts.dll.
$dllExtensions = @('curl', 'exif', 'fileinfo', 'gd', 'intl', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'sodium', 'zip')
$builtinExtensions = @('ctype', 'dom', 'filter', 'iconv', 'mysqlnd', 'pdo', 'phar', 'session', 'simplexml', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zend opcache', 'zlib')

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

$buildTools = & "${env:ProgramFiles(x86)}\Microsoft Visual Studio\Installer\vswhere.exe" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Llvm.Clang -property installationPath
if (-not $buildTools) { throw 'Visual Studio Build Tools with the C++ Clang component are required' }

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

# The launcher carries the application, so the server embeds an empty archive. Its
# embed directive still needs the file to exist.
Set-Content -Path (Join-Path $frankenphp 'app.tar') -Value $null -NoNewline
Copy-Item (Join-Path $ApplicationDirectory 'app_checksum.txt') (Join-Path $frankenphp 'app_checksum.txt')
Copy-Item (Join-Path $PSScriptRoot '..\entrypoint.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack.go')

$env:PATH = @("$buildTools\VC\Tools\Llvm\x64\bin", "$vcpkgRoot\bin", $watcherRoot, $php, $env:PATH) -join ';'
$env:CC = 'clang'
$env:CXX = 'clang++'
$env:CGO_CFLAGS = "-DFRANKENPHP_VERSION=$frankenphpVersion -I$vcpkgRoot\include -I$watcherRoot -I$phpDevelRoot\include -I$phpDevelRoot\include\main -I$phpDevelRoot\include\TSRM -I$phpDevelRoot\include\Zend -I$phpDevelRoot\include\ext"
$env:CGO_LDFLAGS = "-L$vcpkgRoot\lib -lbrotlienc -L$watcherRoot -llibwatcher-c -L$php -L$phpDevelRoot\lib -lphp8ts -lphp8embed"
Push-Location (Join-Path $frankenphp 'caddy\frankenphp')
try {
  go build '-tags=nobadger,nomysql,nopgx' -ldflags="-extldflags=-fuse-ld=lld -X 'main.version=$Version' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphpVersion PHP $phpVersion Caddy'" -o $frankenphpExecutable
} finally {
  Pop-Location
}

Get-ChildItem $php -File | Where-Object Extension -ne '.exe' | Copy-Item -Destination $runtime
Copy-Item -Recurse (Join-Path $php 'ext') (Join-Path $runtime 'ext')
Copy-Item (Join-Path $watcherRoot 'libwatcher-c.dll') $runtime
foreach ($library in 'brotlienc.dll', 'brotlidec.dll', 'brotlicommon.dll', 'pthreadVC3.dll') {
  Copy-Item (Join-Path $vcpkgRoot "bin\$library") $runtime
}

# The launcher sets PHPRC to the extracted runtime directory.
$ini = @('extension_dir = "${PHPRC}\ext"') + ($dllExtensions | ForEach-Object { "extension=$_" })
Set-Content -Path (Join-Path $runtime 'php.ini') -Value $ini

$check = Join-Path $work 'extensions.php'
Set-Content -Path $check -Value '<?php echo implode("\n", array_map("strtolower", get_loaded_extensions()));'
$env:PHPRC = $runtime
$loaded = & $frankenphpExecutable php-cli $check
Remove-Item Env:PHPRC
$missing = $dllExtensions + $builtinExtensions | Where-Object { $loaded -notcontains $_ }
if ($missing) { throw "Runtime is missing PHP extensions: $($missing -join ', ')" }

# The packer resolves a relative -output against its own working directory,
# which Push-Location is about to change, so $Output must resolve here.
$outputDirectory = Split-Path -Parent $Output
if (-not $outputDirectory) { $outputDirectory = '.' }
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
$outputPath = Join-Path (Resolve-Path $outputDirectory).Path (Split-Path -Leaf $Output)
$launcherSource = (Resolve-Path (Join-Path $PSScriptRoot '..\launcher')).Path
$env:CGO_ENABLED = '0'
Push-Location $launcherSource
try {
  go run ./cmd/pack -runtime $runtime -entry frankenphp.exe -version $Version -source $launcherSource -output $outputPath -app (Join-Path $ApplicationDirectory 'app.tar') -app-checksum (Join-Path $ApplicationDirectory 'app_checksum.txt')
} finally {
  Pop-Location
}
