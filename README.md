# Drupack

Drupack is one FrankenPHP executable for Linux `amd64`, Linux `arm64`, macOS `arm64`, macOS `amd64` or Windows `amd64`. It contains Drupal CMS with the Byte site template, PHP, SQLite, MySQL, PostgreSQL, Drush, Local MCP Tools, and MCP Server source.

## Build

Build on Linux `amd64` or `arm64` with Docker and BuildKit. The executable matches the build host's architecture.

```sh
docker build --target artifact --output type=local,dest=dist .
```

The output is `dist/drupack`. The host needs no PHP, Composer, or database server for SQLite use.

### macOS

The macOS build runs on the target architecture with the Xcode Command Line Tools, Go and Git. It needs the same application archive as the Windows build.

```sh
bash packaging/macos/build.sh application "$TMPDIR/drupack" dist/drupack
```

### Windows

The Windows build runs on a Windows host with Visual Studio Build Tools 2022, its C++ Clang component, Go, Git and PowerShell 7.3 or later. It needs the application archive from the Linux `build` stage.

```sh
docker build --target build -t drupack-build .
container=$(docker create drupack-build)
docker cp "$container:/go/src/app/app.tar" application/app.tar
docker cp "$container:/go/src/app/app_checksum.txt" application/app_checksum.txt
docker rm "$container"
```

```powershell
./packaging/windows/build.ps1 -ApplicationDirectory application -Version dev -WorkDirectory $env:TEMP\drupack -Output dist\drupack.exe
```

On first start, `drupack.exe` extracts PHP and FrankenPHP into `%LOCALAPPDATA%\Drupack\runtime\<version>`. Site data stays in `./data` or the `--data-dir` directory.

## Start a SQLite site

First start copies an installed Byte Seed site into `./data`. It needs administrator credentials.

```sh
./dist/drupack \
  --admin-user admin \
  --admin-password 'choose-a-password'
```

A first start without those options asks for a name and a password in the terminal. A start that cannot read input, such as one in a script, fails instead.

A first start opens `http://localhost:8080` in a browser once the site answers, on every platform. Drupal's web installer does not run. Later starts use the existing Site data, need no credentials, and open no browser. `--no-browser` stops Drupack from opening one in every case. The terminal prints the site address, the Site data path, the log path and how to stop the site, then a readiness line once the site answers.

Caddy's own lines, PHP warnings and PHP errors go to `<data>/logs/caddy.log`, in JSON. Access logs stay off.

A double-click in Windows Explorer opens a console window and asks for credentials on a first start; it opens the browser on every start from Explorer, first or later. `--no-browser` still stops that. A failed start keeps the window open until Enter.

`drupack --version` prints the release, and `drupack --help` lists every option.

`--data-dir` selects another Site data directory. `DRUPACK_DATA_DIR` supplies its default.

## Start a server database site

MySQL and PostgreSQL install the same Byte site on first start.

```sh
./dist/drupack --database mysql \
  --db-host 127.0.0.1 --db-name drupal \
  --db-user drupal --db-password 'database-password' \
  --admin-user admin --admin-password 'choose-a-password'
```

Use `--database pgsql` for PostgreSQL. `--db-port` is optional. MySQL defaults to `3306`; PostgreSQL defaults to `5432`.

The equivalent environment variables are `DRUPACK_DATABASE`, `DRUPACK_DB_HOST`, `DRUPACK_DB_PORT`, `DRUPACK_DB_NAME`, `DRUPACK_DB_USER`, `DRUPACK_DB_PASSWORD`, `DRUPACK_ADMIN_USER`, and `DRUPACK_ADMIN_PASSWORD`.

The executable stores database configuration in Site data. Database migrations and later `settings.php` changes belong to the site owner.

## Administer with Drush

`dr` runs the bundled Drush command set against Site data. Put Drupack options before the Drush command.

```sh
./dist/drupack dr --data-dir ./data status
./dist/drupack dr --data-dir ./data pm:list --status=enabled
```

Runtime Composer operations are not included. `dr` opens no browser and prints no readiness line.

## Local MCP tools

The Seed site enables `mcp_tools`. It supports local agents that administer the Drupal site. The package includes `mcp_server` source but does not enable it or expose a transport.

The dependency lock includes `mcp/sdk` 0.6.0, which GHSA-7m52-jw36-44r3 affects. The advisory covers the SDK's client HTTP transport. `mcp_tools` and `mcp_server` use only its server classes.

## Automatic updates

The Seed site uninstalls `automatic_updates` and `package_manager`. A packaged executable cannot update its own Drupal core in place; Drupack ships a new executable instead. `automated_cron` stays enabled for the site's own scheduled work.

`drupal/automatic_updates` 4.1.0's `CommandExecutor::start()` loops on a comparison against a time that never advances. A cron-triggered request then stalls until PHP's execution time limit. The unreleased `4.x-dev` branch, as of 2026-03-31, has the same code. The Seed site's first request returns quickly because it does not ship `automatic_updates` or `package_manager` enabled. Enabling either module brings the 240-second stall back: the loop's code stays in the executable.

Getting a Drupal core security fix means installing a newer Drupack release. A new release carries the newer Drupal core. Site data survives replacing the executable; `tests/replacement.sh` checks that. Nothing yet watches upstream Drupal releases and triggers a new Drupack release for one.

## Releases

A version tag such as `0.1.0` publishes a GitHub Release with these files:

- `drupack-<version>-linux-amd64`
- `drupack-<version>-linux-arm64`
- `drupack-<version>-macos-arm64`
- `drupack-<version>-macos-amd64`
- `drupack-<version>-windows-amd64.exe`
- `checksums.txt`
- `release.json`, with each asset's target, URL, SHA-256 value and size
- `drupack.cdx.json`, a CycloneDX SBOM

Verify downloaded executables before use.

```sh
sha256sum --ignore-missing -c checksums.txt
```

On Windows, compare the `checksums.txt` entry with this output.

```powershell
(Get-FileHash drupack-<version>-windows-amd64.exe -Algorithm SHA256).Hash.ToLower()
```

GitHub attests where each release file was built. Verify an attestation with the GitHub CLI.

```sh
gh attestation verify drupack-<version>-linux-amd64 --repo tresbientech/drupack
```

The macOS executables are not notarized. macOS blocks a downloaded executable until its quarantine attribute is removed.

```sh
xattr -d com.apple.quarantine drupack-<version>-macos-arm64
chmod +x drupack-<version>-macos-arm64
```

The Windows executable is not code-signed. SmartScreen or antivirus software can warn before its first start.

## Site data

Site data contains the database, uploads, private files, generated settings, hash salt, configuration exports, and runtime files. Stop the executable before copying Site data for backup. Keep backups private.

## Tests

Build an uncompressed test binary when UPX compression is unnecessary.

```sh
docker build --target uncompressed --output type=local,dest=dist/uncompressed .
bash tests/database-init.sh ./dist/uncompressed/drupack
bash tests/offline.sh ./dist/uncompressed/drupack
bash tests/network.sh ./dist/uncompressed/drupack
bash tests/server-database.sh ./dist/uncompressed/drupack
bash tests/replacement.sh ./dist/uncompressed/drupack
bash tests/initialization.sh ./dist/uncompressed/drupack
python3 tests/browser.py ./dist/uncompressed/drupack
```

### Development loop

A change to `runtime/` reaches the executable only through a build, which takes minutes. `packaging/dev-server.sh` serves the application from the build image instead, with `runtime/` copied over it on each start.

```sh
docker build --target build -t drupack-build .
bash packaging/dev-server.sh ./dev-data 8080 --admin-user admin --admin-password 'choose-a-password'
```

A later start needs no options. The test scripts still need a built executable.
