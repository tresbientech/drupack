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

  # A later install must clear a directory an earlier install renamed out of
  # the way, so invalid directories from repeated reinstalls do not pile up.
  $firstInvalid = (Get-ChildItem $runtimeRoot -Directory -Filter 'test-v1.invalid-*').Name
  Add-Content -Path (Join-Path $runtimeRoot 'test-v1/frankenphp.exe') -Value 'y' -NoNewline
  $resizedAgain = & (Join-Path $temporary 'drupack.exe') resized-again
  if ($resizedAgain -notmatch 'resized-again') { throw 'a second changed file size did not run' }
  $remainingInvalid = Get-ChildItem $runtimeRoot -Directory -Filter 'test-v1.invalid-*'
  if ($remainingInvalid.Name -contains $firstInvalid) { throw 'installing a runtime kept its previous invalid directory' }
  if (-not $remainingInvalid) { throw 'installing a runtime left no invalid directory of its own' }

  # removeInvalidDirectories has no liveness check of its own: it relies on
  # Windows refusing to delete a file that is still open elsewhere. A file
  # held open inside an invalid directory must survive a reinstall, and the
  # reinstall must still succeed.
  $heldInvalid = (Get-ChildItem $runtimeRoot -Directory -Filter 'test-v1.invalid-*').Name
  $heldHandle = [System.IO.File]::Open((Join-Path $runtimeRoot "$heldInvalid/frankenphp.exe"), [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::None)
  try {
    Add-Content -Path (Join-Path $runtimeRoot 'test-v1/frankenphp.exe') -Value 'z' -NoNewline
    $resizedThird = & (Join-Path $temporary 'drupack.exe') resized-third
    if ($resizedThird -notmatch 'resized-third') { throw 'a reinstall with an open file in an invalid directory did not run' }
  } finally {
    $heldHandle.Dispose()
  }
  if (-not (Test-Path (Join-Path $runtimeRoot "$heldInvalid/frankenphp.exe"))) { throw 'a reinstall removed an invalid directory that still had an open file' }

  # Two simultaneous starts of one release that is not yet installed: only one
  # process stages it, the cache lock makes the other wait, and both find a
  # complete runtime once the lock releases.
  $concurrentPayload = Join-Path $temporary 'concurrent-payload'
  New-Item -ItemType Directory -Path $concurrentPayload | Out-Null
  @'
package main
import ("fmt"; "os")
func main() { fmt.Printf("args=%q env=%s phprc=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE"), os.Getenv("PHPRC")) }
'@ | Set-Content (Join-Path $temporary 'concurrent-runtime.go')
  go build -o (Join-Path $concurrentPayload 'frankenphp.exe') (Join-Path $temporary 'concurrent-runtime.go')
  $concurrentExe = Join-Path $temporary 'drupack-concurrent.exe'
  & (Join-Path $root 'packaging/windows/package.ps1') -RuntimeDirectory $concurrentPayload -Version test-v3 -Output $concurrentExe

  $outA = Join-Path $temporary 'concurrent-a.out'
  $outB = Join-Path $temporary 'concurrent-b.out'
  $errA = Join-Path $temporary 'concurrent-a.err'
  $errB = Join-Path $temporary 'concurrent-b.err'
  $procA = Start-Process -FilePath $concurrentExe -ArgumentList 'one' -RedirectStandardOutput $outA -RedirectStandardError $errA -PassThru -NoNewWindow
  $procB = Start-Process -FilePath $concurrentExe -ArgumentList 'two' -RedirectStandardOutput $outB -RedirectStandardError $errB -PassThru -NoNewWindow
  $procA.WaitForExit()
  $procB.WaitForExit()
  if ($procA.ExitCode -ne 0) { throw "first concurrent start of one release failed: $(Get-Content $errA -Raw)" }
  if ($procB.ExitCode -ne 0) { throw "second concurrent start of one release failed: $(Get-Content $errB -Raw)" }
  # The pre-fix loser of this race reached exit 0 but warned on stderr that
  # it fell back to the previous runtime, so a bare exit-code check would
  # pass against the old code.
  if (Get-Content $errA -Raw) { throw "first concurrent start of one release wrote to stderr: $(Get-Content $errA -Raw)" }
  if (Get-Content $errB -Raw) { throw "second concurrent start of one release wrote to stderr: $(Get-Content $errB -Raw)" }
  if ((Get-Content $outA -Raw) -notmatch '"one"') { throw 'first concurrent start did not forward its own arguments' }
  if ((Get-Content $outB -Raw) -notmatch '"two"') { throw 'second concurrent start did not forward its own arguments' }
  if (-not (Test-Path (Join-Path $runtimeRoot 'test-v3/frankenphp.exe'))) { throw 'concurrent starts of one release did not activate a complete runtime' }
  if ((Get-Content (Join-Path $runtimeRoot 'test-v3/runtime-manifest.json') | ConvertFrom-Json).version -ne 'test-v3') { throw 'concurrent starts of one release left an inconsistent stored manifest' }
  if ((Get-Content (Join-Path $runtimeRoot 'active')).Trim() -ne 'test-v3') { throw 'concurrent starts of one release did not activate it' }
  if (Get-ChildItem $runtimeRoot -Directory -Filter 'test-v3.staging-*') { throw 'a concurrent start left a staging directory behind' }

  # Two simultaneous starts of different releases: each activates its own
  # complete directory, and 'active' ends up naming one of the two.
  $payloadC = Join-Path $temporary 'payload-c'
  $payloadD = Join-Path $temporary 'payload-d'
  New-Item -ItemType Directory -Path $payloadC | Out-Null
  New-Item -ItemType Directory -Path $payloadD | Out-Null
  @'
package main
import ("fmt"; "os")
func main() { fmt.Printf("args=%q env=%s phprc=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE"), os.Getenv("PHPRC")) }
'@ | Set-Content (Join-Path $temporary 'payload-c.go')
  @'
package main
import ("fmt"; "os")
func main() { fmt.Printf("args=%q env=%s phprc=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE"), os.Getenv("PHPRC")) }
'@ | Set-Content (Join-Path $temporary 'payload-d.go')
  go build -o (Join-Path $payloadC 'frankenphp.exe') (Join-Path $temporary 'payload-c.go')
  go build -o (Join-Path $payloadD 'frankenphp.exe') (Join-Path $temporary 'payload-d.go')
  $exeC = Join-Path $temporary 'drupack-c.exe'
  $exeD = Join-Path $temporary 'drupack-d.exe'
  & (Join-Path $root 'packaging/windows/package.ps1') -RuntimeDirectory $payloadC -Version test-v4 -Output $exeC
  & (Join-Path $root 'packaging/windows/package.ps1') -RuntimeDirectory $payloadD -Version test-v5 -Output $exeD

  $outC = Join-Path $temporary 'release-c.out'
  $outD = Join-Path $temporary 'release-d.out'
  $errC = Join-Path $temporary 'release-c.err'
  $errD = Join-Path $temporary 'release-d.err'
  $procC = Start-Process -FilePath $exeC -ArgumentList 'from-c' -RedirectStandardOutput $outC -RedirectStandardError $errC -PassThru -NoNewWindow
  $procD = Start-Process -FilePath $exeD -ArgumentList 'from-d' -RedirectStandardOutput $outD -RedirectStandardError $errD -PassThru -NoNewWindow
  $procC.WaitForExit()
  $procD.WaitForExit()
  if ($procC.ExitCode -ne 0) { throw "a release did not start alongside a concurrent different release: $(Get-Content $errC -Raw)" }
  if ($procD.ExitCode -ne 0) { throw "a release did not start alongside a concurrent different release: $(Get-Content $errD -Raw)" }
  if ((Get-Content $outC -Raw) -notmatch 'test-v4') { throw 'a release did not run its own runtime directory' }
  if ((Get-Content $outD -Raw) -notmatch 'test-v5') { throw 'a release did not run its own runtime directory' }
  if (-not (Test-Path (Join-Path $runtimeRoot 'test-v4/frankenphp.exe'))) { throw 'a release left an incomplete runtime directory' }
  if (-not (Test-Path (Join-Path $runtimeRoot 'test-v5/frankenphp.exe'))) { throw 'a release left an incomplete runtime directory' }
  $activeAfterBoth = (Get-Content (Join-Path $runtimeRoot 'active')).Trim()
  if ($activeAfterBoth -ne 'test-v4' -and $activeAfterBoth -ne 'test-v5') { throw "active named neither concurrent release: $activeAfterBoth" }
  if (-not (Test-Path (Join-Path $runtimeRoot "$activeAfterBoth/frankenphp.exe"))) { throw 'the active release directory is incomplete' }

  # An interrupted activation - a crash between staging and the final rename -
  # leaves no 'active' file and a stray per-process pending file. A later
  # start must still find the complete target and repair 'active'.
  Remove-Item (Join-Path $runtimeRoot 'active') -ErrorAction SilentlyContinue
  Set-Content (Join-Path $runtimeRoot 'active.pending.999999') 'test-v1' -NoNewline
  $repaired = & (Join-Path $temporary 'drupack.exe') repaired
  if ($repaired -notmatch 'repaired') { throw 'a start after an interrupted activation did not run' }
  if ((Get-Content (Join-Path $runtimeRoot 'active')).Trim() -ne 'test-v1') { throw 'a start after an interrupted activation did not repair active' }

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
