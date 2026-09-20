# Contributing to Drupack

The canonical repository is `tresbientech/drupack` on git.tresbien.tech. GitHub and drupal.org carry mirrors that a version tag updates, and neither takes issues or pull requests. [ADR 0001](docs/adr/0001-forge-canonical-github-packaging-mirror.md) records why.

## Build

Build on Linux `amd64` or `arm64` with Docker and BuildKit. The executable matches the build host's architecture.

```sh
docker build --target artifact --output type=local,dest=dist .
```

The output is `dist/drupack`. The host needs no PHP, Composer or database server.

The Composer project that becomes the packaged site lives in `drupal/`. The files copied into the application root live in `runtime/`. `packaging/` holds the build scripts.

### macOS

The macOS build runs on the target architecture with the Xcode Command Line Tools, Go and Git. It needs the application archive from the Linux `build` stage.

```sh
bash packaging/macos/build.sh application "$TMPDIR/drupack" dist/drupack
```

### Windows

The Windows build runs on a Windows host with Visual Studio Build Tools 2022 and its C++ Clang component, Go, Git and PowerShell 7.3 or later. Export the application archive first:

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

The Windows executable is the same launcher as Linux and macOS. It carries the PHP and FrankenPHP tree compressed with zstd, and unpacks under `%LOCALAPPDATA%\Drupack\runtime` on first start. Later starts compare a stored manifest and file sizes. Windows has no `exec`, so the launcher starts a child process instead of replacing itself.

## Tests

The suites run against the published executable, which is the launcher.

```sh
docker build --target artifact --output type=local,dest=dist .
bash tests/launcher.sh ./dist/drupack
bash tests/network.sh ./dist/drupack
bash tests/server-database.sh ./dist/drupack
bash tests/initialization.sh ./dist/drupack
python3 tests/conformance ./dist/drupack test-results/conformance
```

Each shell script takes an optional results directory as its second argument. `tests/conformance` requires one. `tests/network.sh` needs Docker; on Linux, so does `tests/conformance`, which proves its site cases offline inside a container instead of running them on the host. `tests/server-database.sh` starts MySQL and PostgreSQL containers itself. `tests/launcher.sh` packs small fixture launchers with Go and skips those cases when Go is absent, and its full-cache case skips without Docker.

The launcher's own unit tests need Go:

```sh
cd packaging/launcher && go test ./...
```

On Windows, `tests/windows/launcher.Tests.ps1` covers the launcher, and `tests/windows/site.Tests.ps1` takes a built executable:

```powershell
./tests/windows/launcher.Tests.ps1
./tests/windows/site.Tests.ps1 -Executable dist\drupack.exe
```

## Development loop

A change to `runtime/` reaches the executable only through a build, which takes minutes. `packaging/dev-server.sh` serves the application from the build image instead, with `runtime/` copied over it on each start, so a change to `launch.php` or the Caddyfile applies in about a second.

```sh
docker build --target build -t drupack-build .
bash packaging/dev-server.sh ./dev-data 7225
```

Neither start needs more options. The test scripts still need a built executable.

## Launcher

Every published executable is a launcher carrying the real executable, compressed with `github.com/klauspost/compress/zstd`. The first run of a version unpacks it under the user's cache directory, then replaces its own process with it on Linux and macOS, or starts it as a child on Windows, which has no `exec`. Later runs compare a stored manifest and file sizes, then start. `DRUPACK_CACHE_DIR` moves that cache.

`packaging/launcher` holds the launcher and its packer. The `packed` build stage runs the packer over the same executable the `uncompressed` target exports, so `docker build --target uncompressed` still gives you that executable on its own.

## Releases

A version tag without a `v` prefix, such as `0.1.1`, pushed to the Forge, mirrors to GitHub and drupal.org and starts the release workflow. It builds all five targets, runs their tests, then publishes a GitHub Release with each executable under a versioned and an unversioned name, `checksums.txt`, `release.json`, a CycloneDX SBOM and provenance attestations.

Documents worth reading before a change: `CONTEXT.md` for the vocabulary, `docs/prd/` for what the product promises, `docs/plans/` for the current work, `docs/adr/` for decisions and `docs/rfc/` for proposals.
