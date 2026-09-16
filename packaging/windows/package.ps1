param(
  [Parameter(Mandatory = $true)] [string] $RuntimeDirectory,
  [Parameter(Mandatory = $true)] [string] $Version,
  [Parameter(Mandatory = $true)] [string] $Output
)

$ErrorActionPreference = 'Stop'
$source = Split-Path -Parent $MyInvocation.MyCommand.Path
$work = Join-Path ([System.IO.Path]::GetTempPath()) ("drupack-package-" + [guid]::NewGuid())

try {
  if ([System.IO.Path]::GetFileName($Version) -ne $Version) { throw 'Version must be a directory name' }
  $runtime = Resolve-Path $RuntimeDirectory
  New-Item -ItemType Directory -Path $work | Out-Null
  Copy-Item (Join-Path $source 'main.go') $work
  $files = Get-ChildItem -Path $runtime -File -Recurse | ForEach-Object {
    $relative = $_.FullName.Substring($runtime.Path.Length).TrimStart('\', '/') -replace '\\', '/'
    @{ path = $relative; sha256 = (Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLower(); mode = 493 }
  }
  if (-not ($files.path -contains 'frankenphp.exe')) { throw 'Runtime directory must contain frankenphp.exe' }
  @{ version = $Version; files = @($files) } | ConvertTo-Json -Compress -Depth 3 | Set-Content (Join-Path $work 'runtime-manifest.json') -NoNewline
  [System.IO.Compression.ZipFile]::CreateFromDirectory($runtime, (Join-Path $work 'runtime.zip'))
  [Convert]::ToBase64String([System.IO.File]::ReadAllBytes((Join-Path $work 'runtime.zip'))) | Set-Content (Join-Path $work 'runtime.payload') -NoNewline
  $outputDirectory = Split-Path -Parent $Output
  if ($outputDirectory) { New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null }
  go build -o $Output (Join-Path $work 'main.go')
} finally {
  Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}
