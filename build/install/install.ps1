# Installs @NAME@ @VERSION@ into the current directory. Run it in PowerShell with:
#   irm @BASE_URL@/install-@NAME@.ps1 | iex
# The block keeps its variables and preferences out of the session that runs it.
& {
    $ErrorActionPreference = 'Stop'
    # Invoke-WebRequest redraws its progress bar for every chunk, which slows a large download.
    $ProgressPreference = 'SilentlyContinue'
    $name = '@NAME@'
    $version = '@VERSION@'
    $baseUrl = '@BASE_URL@'
    # Each build this release published, with its SHA-256.
    $builds = [ordered]@{
@BUILDS@
    }

    # PowerShell 7 also runs on Linux and macOS, where install-@NAME@.sh installs.
    if ([Environment]::OSVersion.Platform -ne 'Win32NT') {
        throw "This script installs on Windows. On Linux or macOS, run install-$name.sh in a shell."
    }
    # Windows on Arm runs the amd64 build under emulation.
    $target = 'windows-amd64'
    if (-not $builds.Contains($target)) {
        throw "$name $version has no $target build. It has: $($builds.Keys -join ' ')."
    }

    $url = "$baseUrl/$name-$version-$target.exe"
    $file = Join-Path (Get-Location) "$name.exe"
    # A fresh name, so a file or link already in this directory cannot stand in for the download.
    $download = "$file.$([guid]::NewGuid().ToString('N')).download"
    Write-Host "Downloading $url"
    try {
        Invoke-WebRequest -Uri $url -OutFile $download -UseBasicParsing
        # Windows PowerShell finds Get-FileHash in a script module, which it cannot load
        # when started from PowerShell 7, whose module path it inherits. .NET hashes alike in both.
        $stream = [IO.File]::OpenRead($download)
        try {
            $actual = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash($stream)).Replace('-', '').ToLowerInvariant()
        } finally {
            $stream.Dispose()
        }
        if ($actual -ne $builds[$target]) {
            throw "The download's SHA-256 is $actual, and the release lists $($builds[$target]). Nothing was installed."
        }
        if (Test-Path $file) {
            Write-Host "Replacing $file"
        }
        Move-Item -Force $download $file
    } finally {
        Remove-Item -Force -ErrorAction SilentlyContinue $download
    }

    $bin = Join-Path $env:LOCALAPPDATA "Programs\$name"
    Write-Host "Installed $name $version in $file."
    Write-Host ''
    Write-Host 'Start it with:'
    Write-Host ''
    Write-Host "    .\$name.exe" -ForegroundColor Green
    Write-Host ''
    Write-Host 'To run it from any directory, move it onto your PATH:'
    Write-Host "  New-Item -ItemType Directory -Force '$bin'; Move-Item -Force '$file' '$bin'"
    Write-Host "  [Environment]::SetEnvironmentVariable('Path', [Environment]::GetEnvironmentVariable('Path', 'User') + ';$bin', 'User')"
}
