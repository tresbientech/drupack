# Compiles PHP and links the Windows runtime into WorkDirectory\runtime.
param(
  [Parameter(Mandatory = $true)] [string] $Version,
  [Parameter(Mandatory = $true)] [string] $WorkDirectory
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true

$repository = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
# The FrankenPHP and PHP pins every builder compiles.
$pins = Get-Content -Raw (Join-Path $repository 'runtime\builder\builder-inputs.sh') | ConvertFrom-StringData
$frankenphpVersion = $pins.frankenphp_version
$phpVersion = $pins.php_version
# Git for Windows carries the bash that runs the builder's shell files. A bare
# bash can name the WSL launcher.
$bash = Join-Path (git --exec-path) '..\..\..\bin\bash.exe'
$listScript = Join-Path $repository 'runtime\extensions\extensions-list.sh'
# The allowlist every builder compiles. static-php-cli has no pcntl, posix or
# password-argon2 on Windows, and builds no static watcher there.
$extensions = ((& $bash $listScript (Join-Path $repository 'runtime\extensions\php-extensions.txt')) -split ',' |
  Where-Object { $_ -notin 'pcntl', 'posix', 'password-argon2' }) -join ','
# FrankenPHP needs pthreads on Windows, which static-php-cli adds through the
# FrankenPHP target this build leaves out.
$extensionLibs = (@((& $bash $listScript (Join-Path $repository 'runtime\extensions\php-extension-libs.txt')) -split ',' |
  Where-Object { $_ -ne 'watcher' }) + 'pthreads4w') -join ','

# build/release/upstream.py moves both with the watcher line of library-versions.txt.
$watcherUrl = 'https://github.com/e-dant/watcher/releases/download/0.14.5/x86_64-pc-windows-msvc.tar'
$watcherSha256 = '43034cbb07252751246afab8880da74c0340429f1865fd5bd6ce7c17e83c104f'
# static-php-cli links ICU from a zip static-php.dev replaces in place, and checks
# no hash. This one holds ICU 79.1.
$icuSha256 = 'bc367673c2dc0ab9df3eddd01e06ca3ca06a7b6eee5c9e248ed25d438dad617b'

function Assert-Sha256([string] $Path, [string] $Sha256) {
  $hash = (Get-FileHash $Path -Algorithm SHA256).Hash
  if ($hash -ne $Sha256) {
    Remove-Item $Path
    throw "Checksum mismatch for $(Split-Path -Leaf $Path): $hash"
  }
}

function Reset-Directory([string] $Path) {
  if (Test-Path $Path) { Remove-Item -Recurse -Force $Path }
  New-Item -ItemType Directory -Path $Path | Out-Null
}

# spc-config prints MSVC switches, and cgo hands its flags to clang.
function ConvertTo-ClangFlags([string] $Flags) {
  $Flags -replace '/I"([^"]+)"', '-I$1' -replace '/LIBPATH:"([^"]+)"', '-L$1' -replace '(?<=^| )([\w.+-]+)\.lib(?= |$)', '-l$1'
}

$buildTools = & "${env:ProgramFiles(x86)}\Microsoft Visual Studio\Installer\vswhere.exe" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Llvm.Clang Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if (-not $buildTools) { throw 'Visual Studio Build Tools with the C++ Clang and x64 build tools components are required' }

$work = (New-Item -ItemType Directory -Force -Path $WorkDirectory).FullName
$watcher = Join-Path $work 'watcher'
$frankenphp = Join-Path $work 'frankenphp'
$runtime = Join-Path $work 'runtime'
$frankenphpExecutable = Join-Path $runtime 'frankenphp.exe'
$compiled = Join-Path $work 'buildroot\compiled'
foreach ($directory in $watcher, $runtime) { Reset-Directory $directory }
$watcherArchive = Join-Path $work 'watcher.tar'
Invoke-WebRequest -Uri $watcherUrl -OutFile $watcherArchive
Assert-Sha256 $watcherArchive $watcherSha256
tar -xf $watcherArchive -C $watcher
$watcherRoot = Join-Path $watcher 'x86_64-pc-windows-msvc'

# static-php-cli runs from the pinned checkout, on the host's PHP and Composer. It
# keeps its downloads, its sources and its buildroot in the directory it runs from.
Push-Location $work
try {
  & $bash (Join-Path $repository 'runtime\builder\fetch-spc.sh') static-php-cli
  # static-php-cli is third-party code, so none of its Composer scripts or plugins run.
  composer install --working-dir=static-php-cli --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins
  $spc = 'static-php-cli\bin\spc'
  # Installs the build tools static-php-cli needs.
  php $spc doctor --auto-fix
  # A buildroot that holds the marker came from the release job's cache, whose key
  # hashes every input of this compile.
  if (-not (Test-Path $compiled)) {
    # static-php-cli builds each artifact of branch-pins.txt from its checkout. spc build
    # downloads again with its own options, and without these it clones the branch head.
    $branchPins = & $bash (Join-Path $repository 'runtime\builder\fetch-branch-pins.sh') windows ((Join-Path $work 'branch-pins') -replace '\\', '/')
    # static-php-cli's pre-built archives lag the libraries' own releases, so each
    # library compiles from its source release. It builds no ICU on Windows. The set is
    # the one upstream.py records, less what Windows leaves out.
    $packages = (@('php-embed') + ($extensions -split ',' | ForEach-Object { "ext-$_" }) + $extensionLibs) -join ','
    php $spc download --with-php=$phpVersion --for-packages=$packages --prefer-binary=icu --no-alt --retry 5 @($branchPins | ForEach-Object { "--custom-local=$_" })
    Assert-Sha256 'downloads\icu-static-windows-x64.zip' $icuSha256
    $checkDownloads = Join-Path $repository 'runtime\builder\check-downloads.php'
    php $checkDownloads downloads
    php $spc build $extensions --with-libs=$extensionLibs --enable-zts --build-embed --disable-opcache-jit @($branchPins | ForEach-Object { "--dl-custom-local=$_" })
    # The build downloads what its own options miss.
    php $checkDownloads downloads
    # build/release/sbom.py reads the library releases from static-php-cli's download record.
    Copy-Item downloads\.cache.json (Split-Path $compiled)
    # php8embed.lib exists before static-php-cli installs PHP's headers, so the
    # marker names a finished compile. The release job saves its cache on it.
    New-Item -ItemType File $compiled | Out-Null
  }
  $phpIncludes = ConvertTo-ClangFlags (php $spc spc-config $extensions --with-libs=$extensionLibs --includes)
  $phpLibraries = ConvertTo-ClangFlags (php $spc spc-config $extensions --with-libs=$extensionLibs --libs)
} finally {
  Pop-Location
}
$buildroot = Join-Path $work 'buildroot'
# FrankenPHP includes headers static-php-cli leaves in PHP's source tree: ext,
# sapi/cli and win32.
$phpSource = Join-Path $work 'source\php-src'
# --version names the SQLite release static-php-cli compiled into PHP.
$sqliteVersion = [regex]::Match((Get-Content -Raw "$buildroot\include\sqlite3.h"), '(?m)^#define SQLITE_VERSION\s+"(.+)"').Groups[1].Value
if (-not $sqliteVersion) { throw 'sqlite3.h names no SQLITE_VERSION' }

if (Test-Path $frankenphp) { Remove-Item -Recurse -Force $frankenphp }
git -c core.autocrlf=false clone --quiet --depth 1 --branch "v$frankenphpVersion" https://github.com/php/frankenphp.git $frankenphp
# A tag can move, so the checkout must match the pinned commit.
$frankenphpHead = git -C $frankenphp rev-parse HEAD
if ($frankenphpHead -ne $pins.frankenphp_commit) { throw "FrankenPHP v$frankenphpVersion resolved to $frankenphpHead, expected $($pins.frankenphp_commit)" }

# The launcher carries the application, so the server embeds an empty archive,
# which leaves frankenphp's own extraction unused.
Set-Content -Path (Join-Path $frankenphp 'app.tar') -Value $null -NoNewline
Copy-Item (Join-Path $PSScriptRoot '..\..\runtime\frankenphp\entrypoint.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack.go')
Copy-Item (Join-Path $PSScriptRoot '..\..\runtime\frankenphp\watch\watch.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack_watch.go')
Copy-Item (Join-Path $PSScriptRoot '..\..\runtime\frankenphp\crtenv_windows.go') (Join-Path $frankenphp 'caddy\frankenphp\drupack_crtenv_windows.go')

# static-php-cli builds x64 alone on Windows, so a Windows on Arm host builds the
# amd64 runtime too, with its own clang.
$clangHost = if ([System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture -eq 'Arm64') { 'ARM64' } else { 'x64' }
$env:PATH = @("$buildTools\VC\Tools\Llvm\$clangHost\bin", $watcherRoot, $env:PATH) -join ';'
$env:GOARCH = 'amd64'
# Go turns cgo off by default when GOARCH differs from the host's.
$env:CGO_ENABLED = '1'
$env:CC = 'clang --target=x86_64-pc-windows-msvc'
$env:CXX = 'clang++ --target=x86_64-pc-windows-msvc'
$env:CGO_CFLAGS = "$phpIncludes -I$phpSource -I$phpSource\main -I$phpSource\ext -I$phpSource\Zend -I$phpSource\TSRM -I$watcherRoot -DFRANKENPHP_VERSION=$frankenphpVersion -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1"
# The flags after the libraries are the ones static-php-cli's own FrankenPHP link
# passes on Windows, in src/Package/Target/php/frankenphp.php. The /INCLUDE symbols
# pull in the objects FrankenPHP reaches through dllimport, and libcmt is the
# static C runtime PHP was compiled against.
$env:CGO_LDFLAGS = "$phpLibraries -lpathcch -lsecur32 -lcrypt32 -lgdi32 -Wl,/INCLUDE:zend_atomic_bool_store -Wl,/INCLUDE:lxb_url_parse -Wl,/INCLUDE:lxb_unicode_idna_init -llibcmt -Wl,/NODEFAULTLIB:msvcrt -Wl,/NODEFAULTLIB:msvcrtd -Wl,/FORCE:MULTIPLE -L$watcherRoot -llibwatcher-c"
# github.com/google/brotli/go/cbrotli finds static-php-cli's brotli through pkg-config.
$env:PKG_CONFIG_PATH = "$buildroot\lib\pkgconfig"
Push-Location (Join-Path $frankenphp 'caddy\frankenphp')
try {
  go build '-tags=nobadger,nomysql,nopgx' -ldflags="-extldflags=-fuse-ld=lld -X 'main.version=$Version' -X 'main.frankenphpVersion=$frankenphpVersion' -X 'main.sqliteVersion=$sqliteVersion' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphpVersion PHP $phpVersion Caddy'" -o $frankenphpExecutable
} finally {
  Pop-Location
}

Copy-Item (Join-Path $watcherRoot 'libwatcher-c.dll') $runtime
# php.ini and the trust bundle ride beside frankenphp.exe, where PHPRC names them.
Copy-Item (Join-Path $repository 'runtime\php.ini'), (Join-Path $repository 'runtime\cacert.pem') $runtime

$env:CGO_ENABLED = '0'
Push-Location (Join-Path $repository 'launcher')
try {
  # Composer runs PHP_BINARY, which names php.exe beside frankenphp.exe.
  go build -o (Join-Path $runtime 'php.exe') ./cmd/php
} finally {
  Pop-Location
}
