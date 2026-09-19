$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '../..')
$launcherSource = Join-Path $root 'packaging/launcher'
$temporary = Join-Path ([System.IO.Path]::GetTempPath()) ("drupack-windows-test-" + [guid]::NewGuid())
$data = Join-Path $temporary 'data'
New-Item -ItemType Directory -Path $temporary | Out-Null
# os.UserCacheDir reads LOCALAPPDATA, so the launcher extracts under the test directory.
$env:LOCALAPPDATA = Join-Path $temporary 'cache'
$runtimeRoot = Join-Path $env:LOCALAPPDATA 'Drupack/runtime'

# New-FixtureRuntime writes a stub runtime that reports its own invocation,
# standing in for frankenphp.exe so a case never builds the real runtime.
function New-FixtureRuntime([string] $Version) {
  $name = "$Version-$([guid]::NewGuid())"
  $runtime = Join-Path $temporary "runtime-$name"
  New-Item -ItemType Directory -Path $runtime | Out-Null
  $source = @"
package main
import ("fmt"; "os")
func main() { fmt.Printf("fixture $Version args=%q env=%s phprc=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE"), os.Getenv("PHPRC")) }
"@
  # The packer collects every file under the runtime directory, so the source
  # stays outside it and only frankenphp.exe is packed.
  $sourcePath = Join-Path $temporary "runtime-$name.go"
  Set-Content $sourcePath $source
  go build -o (Join-Path $runtime 'frankenphp.exe') $sourcePath
  return $runtime
}

# Invoke-Pack builds a launcher from RuntimeDirectory, run from the launcher
# module so the packer's build lands inside it, as build.ps1 also does.
function Invoke-Pack([string] $RuntimeDirectory, [string] $Version, [string] $Output) {
  Push-Location $launcherSource
  try {
    go run ./cmd/pack -runtime $RuntimeDirectory -entry frankenphp.exe -version $Version -source . -output $Output | Out-Null
  } finally {
    Pop-Location
  }
}

function New-LauncherFixture([string] $Version, [string] $Output) {
  Invoke-Pack (New-FixtureRuntime $Version) $Version $Output
}

# New-CorruptedFixture packs a fixture like New-LauncherFixture, then flips
# one hex digit of its entry file's checksum inside the built binary, as
# tests/launcher.sh's build_corrupted_fixture does. The checksum is embedded
# as literal hex text, so the edit is unique and keeps every other offset
# unchanged.
function New-CorruptedFixture([string] $Version, [string] $Output) {
  $runtime = New-FixtureRuntime $Version
  $correct = (Get-FileHash (Join-Path $runtime 'frankenphp.exe') -Algorithm SHA256).Hash.ToLower()
  Invoke-Pack $runtime $Version $Output
  $bytes = [System.IO.File]::ReadAllBytes($Output)
  # Latin1 maps each byte to one character, so a string offset is a byte offset too.
  $text = [System.Text.Encoding]::Latin1.GetString($bytes)
  $offset = $text.IndexOf($correct)
  if ($offset -lt 0) { throw 'expected checksum not found in the fixture binary' }
  $bytes[$offset] = if ($correct[0] -ne '0') { [byte][char]'0' } else { [byte][char]'1' }
  [System.IO.File]::WriteAllBytes($Output, $bytes)
}

function Get-ActiveKey {
  (Get-Content (Join-Path $runtimeRoot 'active')).Trim()
}

# Get-EntryKey globs the cache entry a version's first stage produced. A
# caller uses it once per version, before any later case can add a second
# directory the same glob would also match.
function Get-EntryKey([string] $Version) {
  (Get-ChildItem $runtimeRoot -Directory -Filter "$Version-*").Name
}

try {
  $exe = Join-Path $temporary 'drupack.exe'
  New-LauncherFixture 'test-v1' $exe

  $env:DRUPACK_TEST_VALUE = 'forwarded'
  $first = & $exe --data-dir $data alpha beta
  $key1 = Get-EntryKey 'test-v1'
  $entry1 = Join-Path $runtimeRoot $key1
  # The stub prints arguments with Go's %q, which doubles backslashes.
  $expected = 'fixture test-v1 args=["--data-dir" "' + ($data -replace '\\', '\\') + '" "alpha" "beta"] env=forwarded phprc=' + $entry1
  if ($first -ne $expected) { throw "first start did not forward arguments, environment and PHPRC: $first" }
  if (Test-Path $data) { throw 'first start wrote Site data' }
  if (-not (Test-Path (Join-Path $entry1 'frankenphp.exe'))) { throw 'first start did not extract the runtime' }
  if ((Get-Content (Join-Path $entry1 'manifest.json') | ConvertFrom-Json).version -ne 'test-v1') { throw 'first start did not persist the stored manifest' }
  if ((Get-ActiveKey) -ne $key1) { throw 'first start did not activate the runtime' }

  $second = & $exe restart
  $expectedSecond = 'fixture test-v1 args=["restart"] env=forwarded phprc=' + $entry1
  if ($second -ne $expectedSecond) { throw "a second start did not use the same entry: $second" }
  if ((Get-ActiveKey) -ne $key1) { throw 'a second start changed the active entry' }

  # A crash between writing a pending activation file and renaming it over
  # 'active' leaves a stray per-process pending file. A warm start never
  # touches 'active' or the pending file, so the stray file must not stop it.
  $strayPending = Join-Path $runtimeRoot 'active.pending.999999'
  Set-Content $strayPending 'stale' -NoNewline
  $third = & $exe stray
  $expectedThird = 'fixture test-v1 args=["stray"] env=forwarded phprc=' + $entry1
  if ($third -ne $expectedThird) { throw "a start with a stray pending file did not use the active entry: $third" }
  if (-not (Test-Path $strayPending)) { throw 'a start removed a stray pending activation file' }
  if ((Get-ActiveKey) -ne $key1) { throw 'a start with a stray pending file changed the active entry' }

  ## A changed file size forces a re-stage. The fresh runtime takes a name of
  ## its own, and the stale entry goes once nothing holds it.
  Add-Content -Path (Join-Path $entry1 'frankenphp.exe') -Value 'x' -NoNewline
  $resized = & $exe resized
  $entry2 = Join-Path $runtimeRoot (Get-ActiveKey)
  if ($entry2 -eq $entry1) { throw 'a re-stage wrote over the entry that was there' }
  if ($resized -ne ('fixture test-v1 args=["resized"] env=forwarded phprc=' + $entry2)) { throw "a changed file size did not force a working re-stage: $resized" }
  if (Test-Path $entry1) { throw 'a re-stage left the stale entry behind' }

  # A running site holds its executable the way the Windows loader does, with
  # reads and deletes shared. Windows refuses to rename or remove a directory
  # while any file under it is open, so the re-stage claims its own name and
  # leaves the held entry alone.
  Add-Content -Path (Join-Path $entry2 'frankenphp.exe') -Value 'y' -NoNewline
  $share = [System.IO.FileShare]::Read -bor [System.IO.FileShare]::Delete
  $heldHandle = [System.IO.File]::Open((Join-Path $entry2 'frankenphp.exe'), [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, $share)
  try {
    $heldResult = & $exe held
    $entry3 = Join-Path $runtimeRoot (Get-ActiveKey)
    if ($entry3 -eq $entry2) { throw 'a re-stage wrote over the entry a handle held' }
    if ($heldResult -ne ('fixture test-v1 args=["held"] env=forwarded phprc=' + $entry3)) { throw "a re-stage with an open file did not run: $heldResult" }
  } finally {
    $heldHandle.Dispose()
  }

  # An exclusive handle shares nothing at all, which blocks a rename and a
  # delete alike. The start still serves, since it never touches that entry.
  Add-Content -Path (Join-Path $entry3 'frankenphp.exe') -Value 'z' -NoNewline
  $exclusive = [System.IO.File]::Open((Join-Path $entry3 'frankenphp.exe'), [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::None)
  try {
    $exclusiveResult = & $exe exclusive
    $entry4 = Join-Path $runtimeRoot (Get-ActiveKey)
    if ($entry4 -eq $entry3) { throw 'a re-stage wrote over the entry an exclusive handle held' }
    if ($exclusiveResult -ne ('fixture test-v1 args=["exclusive"] env=forwarded phprc=' + $entry4)) { throw "a re-stage past an exclusive handle did not run: $exclusiveResult" }
  } finally {
    $exclusive.Dispose()
  }

  ## Two simultaneous starts of one release that is not yet installed: only
  ## one process stages it, the cache lock makes the other wait, and both
  ## find a complete runtime once the lock releases.
  $concurrentExe = Join-Path $temporary 'drupack-concurrent.exe'
  New-LauncherFixture 'test-v3' $concurrentExe
  $outA = Join-Path $temporary 'concurrent-a.out'
  $outB = Join-Path $temporary 'concurrent-b.out'
  $errA = Join-Path $temporary 'concurrent-a.err'
  $errB = Join-Path $temporary 'concurrent-b.err'
  $procA = Start-Process -FilePath $concurrentExe -ArgumentList 'one' -RedirectStandardOutput $outA -RedirectStandardError $errA -PassThru -NoNewWindow
  $procB = Start-Process -FilePath $concurrentExe -ArgumentList 'two' -RedirectStandardOutput $outB -RedirectStandardError $errB -PassThru -NoNewWindow
  $procA.WaitForExit()
  $procB.WaitForExit()
  if ($procA.ExitCode -ne 0) { throw "the first of two simultaneous starts of one release failed: $(Get-Content $errA -Raw)" }
  if ($procB.ExitCode -ne 0) { throw "the second of two simultaneous starts of one release failed: $(Get-Content $errB -Raw)" }
  # The pre-fix loser of this race reached exit 0 but warned on stderr that it
  # fell back to the previous runtime, so a bare exit-code check would pass
  # against the old code.
  if (Get-Content $errA -Raw) { throw "the first of two simultaneous starts of one release wrote to standard error: $(Get-Content $errA -Raw)" }
  if (Get-Content $errB -Raw) { throw "the second of two simultaneous starts of one release wrote to standard error: $(Get-Content $errB -Raw)" }
  if ((Get-Content $outA -Raw) -notmatch '"one"') { throw 'the first of two simultaneous starts did not forward its own arguments' }
  if ((Get-Content $outB -Raw) -notmatch '"two"') { throw 'the second of two simultaneous starts did not forward its own arguments' }
  $key3 = Get-EntryKey 'test-v3'
  if (-not (Test-Path (Join-Path $runtimeRoot "$key3/frankenphp.exe"))) { throw 'two simultaneous starts of one release did not activate a complete runtime' }
  if ((Get-ActiveKey) -ne $key3) { throw 'two simultaneous starts of one release did not activate it' }
  if (Get-ChildItem $runtimeRoot -Directory -Filter '*.staging-*') { throw 'a simultaneous start left a staging directory behind' }

  ## Two simultaneous starts of different releases each run their own
  ## runtime. A successful stage removes every other version's entry, so
  ## 'active' ends up naming one of the two, not both surviving.
  $exeC = Join-Path $temporary 'drupack-c.exe'
  $exeD = Join-Path $temporary 'drupack-d.exe'
  New-LauncherFixture 'test-v4' $exeC
  New-LauncherFixture 'test-v5' $exeD
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
  if ((Get-Content $outC -Raw) -notmatch 'fixture test-v4') { throw 'a release did not run its own runtime' }
  if ((Get-Content $outD -Raw) -notmatch 'fixture test-v5') { throw 'a release did not run its own runtime' }
  $activeAfterBoth = Get-ActiveKey
  if ($activeAfterBoth -notlike 'test-v4-*' -and $activeAfterBoth -notlike 'test-v5-*') { throw "active named neither concurrent release: $activeAfterBoth" }
  if (-not (Test-Path (Join-Path $runtimeRoot "$activeAfterBoth/frankenphp.exe"))) { throw 'the active release directory is incomplete' }

  ## A corrupted payload whose staging fails, with a good version already
  ## installed and active, warns and runs the installed version.
  $exeGood = Join-Path $temporary 'drupack-good.exe'
  New-LauncherFixture 'test-v6' $exeGood
  & $exeGood --help | Out-Null
  $keyGood = Get-EntryKey 'test-v6'

  $exeBroken = Join-Path $temporary 'drupack-broken.exe'
  New-CorruptedFixture 'test-v7' $exeBroken
  $warning = (& $exeBroken retained 2>&1) -join "`n"
  if ($LASTEXITCODE -ne 0) { throw "a corrupted payload with a valid installed version exited non-zero: $warning" }
  if ($warning -notmatch 'fixture test-v6') { throw 'the fallback did not run the installed version' }
  if ($warning -notmatch [regex]::Escape('Using the runtime already in the cache.')) { throw 'the fallback warning was not written to standard error' }
  if ((Get-ActiveKey) -ne $keyGood) { throw 'a failed stage replaced the active runtime' }

  ## An unreadable stored manifest on the active entry disqualifies it as a
  ## fallback: a corrupted payload then exits non-zero and never reaches a
  ## runtime to forward arguments to.
  Set-Content (Join-Path $runtimeRoot "$keyGood/manifest.json") '{}' -NoNewline
  $untrusted = (& $exeBroken untrusted 2>&1) -join "`n"
  if ($LASTEXITCODE -eq 0) { throw 'a corrupted payload with an unreadable stored manifest exited zero' }
  if ($untrusted -match 'fixture') { throw 'a corrupted payload with an unreadable stored manifest forwarded arguments to a runtime' }
} finally {
  Remove-Item -Recurse -Force $temporary
}

# The last launcher call exits non-zero on purpose, and a caller such as GitHub's pwsh step reports $LASTEXITCODE.
exit 0
