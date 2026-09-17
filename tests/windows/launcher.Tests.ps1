$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '../..')
$temporary = Join-Path ([System.IO.Path]::GetTempPath()) ("drupack-windows-test-" + [guid]::NewGuid())
$payload = Join-Path $temporary 'payload'
$data = Join-Path $temporary 'data'
New-Item -ItemType Directory -Path $payload | Out-Null
# os.UserCacheDir reads LOCALAPPDATA, so the launcher extracts under the test directory.
$env:LOCALAPPDATA = Join-Path $temporary 'cache'
$runtimeRoot = Join-Path $env:LOCALAPPDATA 'Drupack/runtime'

try {
  @'
package main
import ("fmt"; "os")
func main() { fmt.Printf("args=%q env=%s phprc=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE"), os.Getenv("PHPRC")) }
'@ | Set-Content (Join-Path $temporary 'runtime.go')
  go build -o (Join-Path $payload 'frankenphp.exe') (Join-Path $temporary 'runtime.go')
  & (Join-Path $root 'packaging/windows/package.ps1') -RuntimeDirectory $payload -Version test-v1 -Output (Join-Path $temporary 'drupack.exe')

  $env:DRUPACK_TEST_VALUE = 'forwarded'
  $first = & (Join-Path $temporary 'drupack.exe') --data-dir $data alpha beta
  # The stub prints arguments with Go's %q, which doubles backslashes.
  $expected = 'args=["--data-dir" "' + ($data -replace '\\', '\\') + '" "alpha" "beta"] env=forwarded phprc=' + (Join-Path $env:LOCALAPPDATA 'Drupack\runtime\test-v1')
  if ($first -ne $expected) { throw "first launch did not forward arguments and environment: $first" }
  if (Test-Path $data) { throw 'first launch wrote Site data' }
  if (-not (Test-Path (Join-Path $runtimeRoot 'test-v1/frankenphp.exe'))) { throw 'first launch did not extract the runtime' }
  if ((Get-Content (Join-Path $runtimeRoot 'test-v1/runtime-manifest.json') | ConvertFrom-Json).version -ne 'test-v1') { throw 'first launch did not persist the runtime manifest' }
  if ((Get-Content (Join-Path $runtimeRoot 'active')).Trim() -ne 'test-v1') { throw 'first launch did not activate the runtime' }

  $second = & (Join-Path $temporary 'drupack.exe') restart
  if ($second -notmatch 'restart') { throw 'restart did not use the active runtime' }

  Add-Content -Path (Join-Path $runtimeRoot 'test-v1/frankenphp.exe') -Value 'x' -NoNewline
  $resized = & (Join-Path $temporary 'drupack.exe') resized
  if ($resized -notmatch 'resized') { throw 'a runtime with a changed file size did not run' }
  if (-not (Get-ChildItem $runtimeRoot -Directory -Filter 'test-v1.invalid-*')) { throw 'a runtime with a changed file size was not reinstalled' }

  $brokenSource = Join-Path $temporary 'broken-source'
  New-Item -ItemType Directory -Path $brokenSource | Out-Null
  Copy-Item (Join-Path $root 'packaging/windows/main.go') $brokenSource
  @{ version = 'test-v2'; files = @(@{ path = 'frankenphp.exe'; sha256 = '00' }) } | ConvertTo-Json -Compress | Set-Content (Join-Path $brokenSource 'runtime-manifest.json') -NoNewline
  Set-Content (Join-Path $brokenSource 'runtime.zip') 'not a zip' -NoNewline
  go build -o (Join-Path $temporary 'broken.exe') (Join-Path $brokenSource 'main.go')
  $warning = (& (Join-Path $temporary 'broken.exe') retained 2>&1) -join "`n"
  if ($warning -notmatch 'Using the previous runtime') { throw 'failed extraction did not warn about the previous runtime' }
  if ($warning -notmatch 'retained') { throw 'failed extraction did not run the previous runtime' }
  if ((Get-Content (Join-Path $runtimeRoot 'active')).Trim() -ne 'test-v1') { throw 'failed extraction replaced the active runtime' }

  Set-Content (Join-Path $runtimeRoot 'test-v1/runtime-manifest.json') '{}'
  $untrusted = (& (Join-Path $temporary 'broken.exe') untrusted 2>&1) -join "`n"
  if ($LASTEXITCODE -eq 0) { throw 'an invalid stored manifest ran the active runtime' }
  if ($untrusted -match 'untrusted') { throw 'an invalid stored manifest forwarded arguments to the active runtime' }
} finally {
  Remove-Item -Recurse -Force $temporary
}

# The last launcher call exits non-zero on purpose, and a caller such as GitHub's pwsh step reports $LASTEXITCODE.
exit 0
