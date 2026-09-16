$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '../..')
$temporary = Join-Path ([System.IO.Path]::GetTempPath()) ("drupack-windows-test-" + [guid]::NewGuid())
$payload = Join-Path $temporary 'payload'
$data = Join-Path $temporary 'data'
New-Item -ItemType Directory -Path $payload, $data | Out-Null

try {
  @'
package main
import ("fmt"; "os")
func main() { fmt.Printf("args=%q env=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE")) }
'@ | Set-Content (Join-Path $temporary 'runtime.go')
  go build -o (Join-Path $payload 'frankenphp.exe') (Join-Path $temporary 'runtime.go')
  & (Join-Path $root 'packaging/windows/package.ps1') -RuntimeDirectory $payload -Version test-v1 -Output (Join-Path $temporary 'drupack.exe')

  $env:DRUPACK_TEST_VALUE = 'forwarded'
  $first = & (Join-Path $temporary 'drupack.exe') --data-dir $data alpha beta
  $expected = 'args=["--data-dir" "' + $data + '" "alpha" "beta"] env=forwarded'
  if ($first -ne $expected) { throw "first launch did not forward arguments and environment: $first" }
  if (-not (Test-Path (Join-Path $data 'runtime/test-v1/frankenphp.exe'))) { throw 'first launch did not extract the runtime' }
  if ((Get-Content (Join-Path $data 'runtime/test-v1/runtime-manifest.json') | ConvertFrom-Json).version -ne 'test-v1') { throw 'first launch did not persist the runtime manifest' }
  if ((Get-Content (Join-Path $data 'runtime/active')).Trim() -ne 'test-v1') { throw 'first launch did not activate the runtime' }

  $second = & (Join-Path $temporary 'drupack.exe') --data-dir=$data restart
  if ($second -notmatch 'restart') { throw 'restart did not use the active runtime' }

  $brokenSource = Join-Path $temporary 'broken-source'
  New-Item -ItemType Directory -Path $brokenSource | Out-Null
  Copy-Item (Join-Path $root 'packaging/windows/main.go') $brokenSource
  @{ version = 'test-v2'; files = @(@{ path = 'frankenphp.exe'; sha256 = '00'; mode = 493 }) } | ConvertTo-Json -Compress | Set-Content (Join-Path $brokenSource 'runtime-manifest.json') -NoNewline
  [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes('not a zip')) | Set-Content (Join-Path $brokenSource 'runtime.payload') -NoNewline
  go build -o (Join-Path $temporary 'broken.exe') (Join-Path $brokenSource 'main.go')
  $warning = & (Join-Path $temporary 'broken.exe') --data-dir $data retained 2>&1
  if ($warning -notmatch 'Using the previous runtime') { throw 'failed extraction did not warn about the previous runtime' }
  if ($warning -notmatch 'retained') { throw 'failed extraction did not run the previous runtime' }
  if ((Get-Content (Join-Path $data 'runtime/active')).Trim() -ne 'test-v1') { throw 'failed extraction replaced the active runtime' }

  Set-Content (Join-Path $data 'runtime/test-v1/runtime-manifest.json') '{}'
  $untrusted = & (Join-Path $temporary 'broken.exe') --data-dir $data untrusted 2>&1
  if ($LASTEXITCODE -eq 0) { throw 'an invalid stored manifest ran the active runtime' }
  if ($untrusted -match 'untrusted') { throw 'an invalid stored manifest forwarded arguments to the active runtime' }
} finally {
  Remove-Item -Recurse -Force $temporary
}
