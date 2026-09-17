param(
  [Parameter(Mandatory = $true)] [string] $Executable
)

$ErrorActionPreference = 'Stop'

$executable = (Resolve-Path $Executable).Path
$temporary = Join-Path ([System.IO.Path]::GetTempPath()) ("drupack-site-test-" + [guid]::NewGuid())
$data = Join-Path $temporary 'data'
$listen = '127.0.0.1:18090'
New-Item -ItemType Directory -Path $temporary | Out-Null
# os.UserCacheDir reads LOCALAPPDATA, so the launcher extracts its runtime under the test directory.
$env:LOCALAPPDATA = Join-Path $temporary 'cache'

function Start-Site([string[]] $Arguments, [string] $Name) {
  $process = Start-Process -FilePath $executable -ArgumentList (@('--data-dir', $data, '--listen', $listen) + $Arguments) -WorkingDirectory $temporary -RedirectStandardOutput (Join-Path $temporary "$Name.out.log") -RedirectStandardError (Join-Path $temporary "$Name.err.log") -PassThru
  foreach ($attempt in 1..120) {
    if ($process.HasExited) { break }
    try {
      if ((Invoke-WebRequest -Uri "http://$listen/user/login" -TimeoutSec 5 -SkipHttpErrorCheck).StatusCode -eq 200) { return $process }
    } catch { }
    Start-Sleep -Seconds 2
  }
  Stop-Site $process
  throw "$Name did not serve /user/login: $(Get-Content (Join-Path $temporary "$Name.err.log") -Raw)"
}

function Stop-Site($Process) {
  if (-not $Process.HasExited) { taskkill /T /F /PID $Process.Id | Out-Null }
  $Process.WaitForExit()
}

function Assert-Status([string] $Path, [int] $Expected) {
  $status = (Invoke-WebRequest -Uri "http://$listen$Path" -TimeoutSec 10 -SkipHttpErrorCheck).StatusCode
  if ($status -ne $Expected) { throw "$Path returned $status, expected $Expected" }
}

try {
  $refused = (& $executable --data-dir $data 2>&1) -join "`n"
  if ($LASTEXITCODE -eq 0) { throw 'first start without administrator credentials succeeded' }
  if (Test-Path $data) { throw "first start without administrator credentials wrote Site data: $refused" }

  $site = Start-Site @('--admin-user', 'windows-admin', '--admin-password', 'Windows.site.test.password.2026') 'first-start'
  Assert-Status '/sites/default/settings.php' 404
  Assert-Status '/sites/default/private/' 403
  Stop-Site $site

  $bootstrap = & $executable dr --data-dir $data status --field=bootstrap
  if ($LASTEXITCODE -ne 0 -or ($bootstrap -join '') -notmatch 'Successful') { throw "dr status did not bootstrap Drupal: $bootstrap" }

  $mcpTools = & $executable dr --data-dir $data mcp-tools:client-config
  if ($LASTEXITCODE -ne 0 -or ($mcpTools -join '') -notmatch 'mcp') { throw "dr mcp-tools:client-config failed: $mcpTools" }

  $site = Start-Site @() 'restart'
  Stop-Site $site
} finally {
  Remove-Item -Recurse -Force $temporary -ErrorAction SilentlyContinue
}
