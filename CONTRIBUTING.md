# Contributing to Drupack

The canonical repository is `tresbientech/drupack` on git.tresbien.tech. GitHub and drupal.org carry mirrors that every push to `main` updates, and neither takes issues or pull requests. [ADR 0001](docs/adr/0001-forge-canonical-github-packaging-mirror.md) records why.

## Build

Build on Linux `amd64` or `arm64` with Docker and BuildKit. The executable matches the build host's architecture.

A runtime carries the PHP extensions `packaging/php-extensions.txt` names, and a builder image compiles that set while the image itself is built. Build one image per C library first. Each run compiles PHP from source and takes well over an hour; the images survive between builds, so a later `docker build` reuses them.

```sh
bash packaging/build-builder.sh musl drupack-builder-musl:local
bash packaging/build-builder.sh gnu drupack-builder-gnu:local
docker build --target artifact --output type=local,dest=dist .
```

The output is `dist/drupack`. The host needs no PHP, Composer or database server.

The Composer project that becomes the packaged site lives in `drupal/`. The files copied into the application root live in `runtime/`. `packaging/` holds the build scripts.

### macOS

The macOS build runs on the target architecture with the Xcode Command Line Tools, Go and Git. It needs the application archive from the Linux `app` stage.

```sh
bash packaging/macos/build.sh application "$TMPDIR/drupack" dist/drupack
```

### Windows

The Windows build runs on a Windows host with Visual Studio Build Tools 2022 and its C++ Clang component, Go, Git and PowerShell 7.3 or later. Export the application archive first:

```sh
docker build --target app -t drupack-build .
container=$(docker create drupack-build)
docker cp "$container:/go/src/app/app-payload.tar" application/app-payload.tar
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
python3 tests/conformance ./dist/drupack test-results/conformance
```

`tests/conformance` takes a results directory as its second argument. On Linux it also needs Docker, which it uses to prove its site cases offline inside a container instead of running them on the host, to run its network cases against a second container, and to start MySQL and PostgreSQL containers for its server-database cases. On Linux, `tests/conformance` also needs Go, to pack small fixture launchers for its launcher cases.

One conformance run happens at a time on a machine. A second run waits for the first, and says so on standard error. Two at once bind the same ports and collide on container names, which times out site starts in both. `DRUPACK_SUITE_LOCK` names the lock file, so a machine that needs two independent runs can give each its own.

The harness's own unit tests need no built executable:

```sh
python3 -m unittest discover -s tests/conformance -p 'test_harness.py'
```

The PHP unit files run through the bundled runtime, because `launch.php`
requires `vendor/autoload.php` and the lock targets a PHP the host may not
have. They also need `runtime/vendor`, a symlink to `drupal/vendor` that
`composer install --working-dir=drupal` fills:

```sh
./dist/drupack php-cli "$PWD/tests/unit/launch_test.php"
./dist/drupack php-cli "$PWD/tests/unit/windows_paths.php"
./dist/drupack php-cli "$PWD/tests/unit/previous_copies.php"
./dist/drupack php-cli "$PWD/tests/unit/site_data_public_stream.php"
```

`site_data_public_stream.php` covers the check that refuses a public file
target resolving outside Site data.

The launcher's own unit tests need Go:

```sh
cd packaging/launcher && go test ./...
```

On Windows, `python` runs the suite in place of `python3`, which Windows does not provide:

```powershell
python tests/conformance dist\drupack.exe test-results\conformance
```

## Development loop

A change to `runtime/` reaches the executable only through a build, which takes minutes. `packaging/dev-server.sh` serves the application from the build image instead, with `runtime/` copied over it on each start, so a change to `launch.php` or the Caddyfile applies in about a second.

```sh
docker build --target app -t drupack-build .
bash packaging/dev-server.sh ./dev-data 7225
```

Neither start needs more options. The test scripts still need a built executable.

## Launcher

Every published executable is a launcher carrying the real executable, compressed with `github.com/klauspost/compress/zstd`. The first run of a version unpacks it under the user's cache directory, then replaces its own process with it on Linux and macOS, or starts it as a child on Windows, which has no `exec`. Later runs compare a stored manifest and file sizes, then start. `DRUPACK_CACHE_DIR` moves that cache.

`packaging/launcher` holds the launcher and its packer. A Linux executable carries a runtime per C library, built from one builder image each, and the launcher picks one per host. The `packed` build stage runs the packer over the same executables the `uncompressed` and `uncompressed-gnu` targets export, so `docker build --target uncompressed` gives you the musl one on its own and `--target uncompressed-gnu` the glibc one.

## Releases

A version tag without a `v` prefix, such as `0.1.1`, pushed to the Forge, mirrors to GitHub and drupal.org and starts the release workflow. It builds all five targets, runs their tests, then publishes a GitHub Release with each executable under a versioned and an unversioned name, `checksums.txt`, `release.json`, a CycloneDX SBOM and provenance attestations.

A push to `main` mirrors the same way. It builds and tests Linux amd64 when the push touched a path outside `docs/`, `LICENSE` and the root Markdown files. A documentation commit starts no build.

Documents worth reading before a change: `CONTEXT.md` for the vocabulary, `README.md` for what the product promises, `docs/adr/` for the decisions behind the current shape, `docs/backlog.md` for open questions and `docs/plans/` for work already scheduled. A decision lands as a short numbered ADR, and a plan is deleted once its work ships, so the tree holds no finished checklists.
