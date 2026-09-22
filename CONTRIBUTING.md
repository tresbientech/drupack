# Contributing to Drupack

The canonical repository is `tresbientech/drupack` on git.tresbien.tech. GitHub and drupal.org carry mirrors that every push to `main` updates, and neither takes issues or pull requests. [ADR 0001](docs/adr/0001-forge-canonical-github-packaging-mirror.md) records why.

## Build

Build on Linux `amd64` or `arm64` with Docker and BuildKit. The executable matches the build host's architecture.

A runtime carries the PHP extensions `runtime/php-extensions.txt` names, and a builder image compiles that set while the image itself is built. Build one image per C library first. Each run compiles PHP from source and takes well over an hour; the images survive between builds, so a later `docker build` reuses them.

```sh
bash runtime/build-builder.sh musl drupack-builder-musl:local
bash runtime/build-builder.sh gnu drupack-builder-gnu:local
docker build --target artifact --output type=local,dest=dist .
```

The release workflow names the same image after its inputs. `runtime/builder-tag.sh` digests the FrankenPHP commit, the PHP version, both extension list files, the C library and the machine type, and the workflow pulls `ghcr.io/tresbientech/drupack-builder` under that tag. A run whose inputs are unchanged pulls the published image; a run that changes one builds the image and publishes it under the new tag. `runtime/builder-inputs.sh` holds the version pins both scripts read.

The output is `dist/drupack`. The host needs no PHP, Composer or database server.

`application/` holds the engine's PHP files, which the build lays over a site's Composer project to make the application root. `examples/mercury-demo/` is the site the Drupack release packages: its Composer project and its `drupack.yml`, which `launcher/internal/siteconfig` validates and writes out as `site.json`. The `Dockerfile` builds the site `SITE_DIR` names, `examples/mercury-demo` by default. `runtime/` holds the PHP and FrankenPHP compile. `launcher/` is the Go module for the launcher and its packer. `build/` holds the macOS and Windows builds and the development loop, and the `Dockerfile` at the root is the Linux build. [ADR 0017](docs/adr/0017-one-directory-per-artifact.md) records the shape.

### macOS

The macOS build runs on the target architecture with the Xcode Command Line Tools, Go and Git. It needs the application payload from the Linux `app` stage.

```sh
bash build/macos/build.sh dist/payload "$TMPDIR/drupack" dist/drupack
```

### Windows

The Windows build runs on a Windows host with Visual Studio Build Tools 2022 and its C++ Clang component, Go, Git and PowerShell 7.3 or later. Export the application archive first:

```sh
docker build --target app -t drupack-build .
container=$(docker create drupack-build)
mkdir -p dist/payload
docker cp "$container:/go/src/app/app-payload.tar" dist/payload/app-payload.tar
docker cp "$container:/go/src/app/app_checksum.txt" dist/payload/app_checksum.txt
docker cp "$container:/app/site.json" dist/payload/site.json
docker rm "$container"
```

```powershell
./build/windows/build.ps1 -PayloadDirectory dist\payload -Version dev -WorkDirectory $env:TEMP\drupack -Output dist\drupack.exe
```

The Windows executable is the same launcher as Linux and macOS. It carries the PHP and FrankenPHP tree compressed with zstd, and unpacks under `%LOCALAPPDATA%\Drupack\runtime` on first start. Later starts compare a stored manifest and file sizes. Windows has no `exec`, so the launcher starts a child process instead of replacing itself.

## Tests

The suites run against the published executable, which is the launcher.

```sh
docker build --target artifact --output type=local,dest=dist .
python3 tests/conformance ./dist/drupack test-results/conformance --site-tests examples/mercury-demo/tests
```

`tests/conformance` takes a results directory as its second argument, and empties it before any case runs. It refuses a non-empty directory that no earlier run created. It reads the site from the `site.json` beside the executable. `--site-tests DIR` adds a site's own `*_cases.py` modules to the run; Mercury Demo's live in `examples/mercury-demo/tests`.

On Linux the suite uses Docker for three things:

- proving the site cases offline inside a container, instead of on the host
- running the network cases against a second container
- starting MySQL and PostgreSQL containers for the server-database cases

With no Docker daemon, the site cases run on the host and the other container cases skip, each named in the `Skipped:` report at the end of the run. `DRUPACK_TEST_MYSQL` and `DRUPACK_TEST_PGSQL` name a running server as `HOST:PORT` in place of a container. The suite connects to database `drupal` as user `drupal` with the password `harness.DATABASE_PASSWORD` holds. On Linux, `tests/conformance` also needs Go, to pack small fixture launchers for its launcher cases.

One conformance run happens at a time on a machine. A second run waits for the first, and says so on standard error. Two at once bind the same ports and collide on container names, which times out site starts in both. `DRUPACK_SUITE_LOCK` names the lock file, so a machine that needs two independent runs can give each its own.

The harness's own unit tests need no built executable:

```sh
python3 -m unittest discover -s tests/conformance -p 'test_harness.py'
```

The PHP unit files run through the bundled runtime, because `launch.php`
requires `vendor/autoload.php` and the lock targets a PHP the host may not
have. They read `application/vendor`, a link to the vendor directory of an
installed site:

```sh
composer install --working-dir=examples/mercury-demo
ln -s ../examples/mercury-demo/vendor application/vendor
```

Then:

```sh
./dist/drupack php-cli "$PWD/application/tests/launch_test.php"
./dist/drupack php-cli "$PWD/application/tests/windows_paths_test.php"
./dist/drupack php-cli "$PWD/application/tests/previous_copies_test.php"
./dist/drupack php-cli "$PWD/application/tests/site_data_public_stream_test.php"
```

`site_data_public_stream_test.php` covers the check that refuses a public file
target resolving outside Site data.

The launcher's own unit tests need Go:

```sh
cd launcher && go test ./...
```

On Windows, `python` runs the suite in place of `python3`, which Windows does not provide:

```powershell
python tests/conformance dist\drupack.exe test-results\conformance --site-tests examples\mercury-demo\tests
```

## Development loop

A change to `runtime/` reaches the executable only through a build, which takes minutes. `build/dev/dev-server.sh` serves the application from the build image instead, with `runtime/` copied over it on each start, so a change to `launch.php` or the Caddyfile applies in about a second.

```sh
docker build --target app -t drupack-build .
bash build/dev/dev-server.sh ./dev-data 7225
```

Neither start needs more options. The test scripts still need a built executable.

## Launcher

Every published executable is a launcher carrying the real executable, compressed with `github.com/klauspost/compress/zstd`. The first run of a version unpacks it under the user's cache directory, then replaces its own process with it on Linux and macOS, or starts it as a child on Windows, which has no `exec`. Later runs compare a stored manifest and file sizes, then start. `DRUPACK_CACHE_DIR` moves that cache.

`launcher/` holds the launcher and its packer, at the path its `go.mod` declares. A Linux executable carries a runtime per C library, built from one builder image each, and the launcher picks one per host. The `packed` build stage runs the packer over the same executables the `uncompressed` and `uncompressed-gnu` targets export, so `docker build --target uncompressed` gives you the musl one on its own and `--target uncompressed-gnu` the glibc one.

## Releases

A version tag without a `v` prefix, such as `0.1.1`, pushed to the Forge, mirrors to GitHub and drupal.org and starts the release workflow. It builds all five targets, runs their tests, then publishes a GitHub Release with each executable under a versioned and an unversioned name, `checksums.txt`, `release.json`, a CycloneDX SBOM and provenance attestations.

A push to `main` mirrors the same way. It builds and tests Linux amd64 when the push touched a path outside `docs/`, `LICENSE` and the root Markdown files. A documentation commit starts no build.

Documents worth reading before a change: `CONTEXT.md` for the vocabulary, `README.md` for what the product promises, `docs/adr/` for the decisions behind the current shape, `docs/backlog.md` for open questions, `docs/plans/` for work already scheduled and `docs/reviews/` for the reviews a live plan acts on. A decision lands as a short numbered ADR. A plan and the review it acts on are deleted once the work ships, so the tree holds no finished checklists.
