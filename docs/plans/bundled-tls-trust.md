# Plan: Bundled TLS trust and the runtime php.ini

> Source PRD: [docs/prd/bundled-tls-trust.md](../prd/bundled-tls-trust.md)

## Architectural decisions

Durable across every phase.

- **Trust file**: `cacert.pem`, vendored in the repository beside the shared
  php.ini, packed into the runtime directory beside the entry executable. One
  copy per runtime version, removed with it.
- **Environment contract**: `PHPRC` always points at the unpacked runtime
  directory. `DRUPACK_CA_FILE` points at the packed bundle unless the caller
  already set it, and a caller's value wins.
- **One ini**: the repository's php.ini is the only source of PHP settings. Its
  `curl.cainfo` and `openssl.cafile` both read `${DRUPACK_CA_FILE}`. The Windows
  build appends `extension_dir` and its extension lines to a copy of that file.
- **Environment function**: one exported function in the launcher's runtime
  package takes the runtime directory and the parent environment, and returns
  the child environment. Both platform entry points call it.
- **Conformance module**: `tests/conformance/php_runtime_cases.py`, with
  `PLATFORMS` marks per class and `harness.running_offline()` gating the one
  case that needs the internet.
- **No new downloads**: no build fetches the bundle. A refresh is a commit.

## Suite

Every phase runs the Linux chain first. The build comes before the rest, because
the conformance suite runs against the built executable.

```sh
docker build --target artifact --output type=local,dest=dist .
cd packaging/launcher && go test ./...
python3 -m unittest discover -s tests/conformance -p 'test_harness.py'
python3 tests/conformance ./dist/drupack test-results/conformance
```

## Platform runs

Windows runs from this checkout through WSL interop, the way the Windows path
plan already describes: Docker produces the application archive in WSL, and the
Windows side builds and tests against it.

```sh
docker build --target build -t drupack-build .
container=$(docker create drupack-build)
docker cp "$container:/go/src/app/app.tar" application/app.tar
docker cp "$container:/go/src/app/app_checksum.txt" application/app_checksum.txt
docker rm "$container"
```

```powershell
./packaging/windows/build.ps1 -ApplicationDirectory application -Version dev `
  -WorkDirectory $env:TEMP\drupack -Output dist\drupack.exe
python tests/conformance dist\drupack.exe test-results\conformance
```

macOS builds from the same application archive with `packaging/macos/build.sh`,
and runs the same conformance command against its own executable.

Two probes recur in the acceptance criteria below. The first hides the host
store by pointing `SSL_CERT_FILE` and `SSL_CERT_DIR` at a path that does not
exist, which reproduces the reported failure on a build without the bundle. The
second fetches `https://updates.drupal.org/release-history/drupal/current` and
reads the HTTP status. Both run through `php-cli` on the built executable.

---

## Phase 1: Linux end to end

**User stories**: 1, 6, 14, 18, 19, 22, 23, 29

### What to build

The vendored bundle, the two CA lines in the shared ini, and the environment
function that puts them to work. Both platform entry points call that function,
so the Windows path keeps its console-owned flag and gains the new variables at
the same time. The Linux build packs the ini and the bundle beside the entry
executable.

The new conformance module lands here, marked Linux. It drives the built
executable and asserts what a site owner could observe: which ini loaded, the
active memory limit, a certificate path that resolves and parses, and a caller's
own bundle taking precedence.

### Acceptance criteria

- [ ] `cd packaging/launcher && go test ./...` exits 0, including new unit tests
      for both precedence rules.
- [ ] Those unit tests fail when `PHPRC` is left to the caller, and when a
      caller's `DRUPACK_CA_FILE` is overwritten.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance RuntimeConfiguration` passes.
- [ ] That class asserts the loaded ini sits under the runtime cache, the memory
      limit reads 512M, and the certificate path parses as over 100 certificates.
- [ ] A php.ini written into the working directory changes no setting.
- [ ] With `SSL_CERT_FILE` and `SSL_CERT_DIR` pointed at a missing path, the
      release history fetch still returns 200. The same probe against the
      current release returns cURL error 60.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` passes
      whole, with the offline suite green at the new limits.

---

## Phase 2: Windows end to end

**User stories**: 1, 2, 15, 20, 21

### What to build

The Windows build stops authoring PHP settings. It copies the shared ini,
appends its extension directory and extension lines, and packs the bundle beside
the executable. The conformance class from phase 1 gains its Windows mark.

### Acceptance criteria

- [ ] `./packaging/windows/build.ps1` completes, and its extension check still
      finds every required extension.
- [ ] `python tests\conformance dist\drupack.exe test-results\conformance RuntimeConfiguration` passes.
- [ ] A site started from that executable reports available updates with no
      OpenSSL message, and its log holds no cURL error 60.
- [ ] The same site reads 512M for its memory limit.
- [ ] `python tests\conformance dist\drupack.exe test-results\conformance` passes whole.

---

## Phase 3: macOS end to end

**User stories**: 7, 15

### What to build

The macOS build packs the ini and the bundle into the runtime directory it hands
the packer. The conformance class gains its macOS mark.

### Acceptance criteria

- [ ] `packaging/macos/build.sh` produces an executable that prints its version.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance RuntimeConfiguration` passes on macOS.
- [ ] With `SSL_CERT_FILE` and `SSL_CERT_DIR` pointed at a missing path, the
      release history fetch returns 200.
- [ ] The full conformance run passes on macOS.

---

## Phase 4: Development server parity

**User stories**: 16, 24

### What to build

The development entry point copies the bundle with the other runtime files and
exports the same variable, so a developer's site resolves trust the way a
release does.

### Acceptance criteria

- [ ] `packaging/dev-server.sh ./data-dev` serves a site whose memory limit reads
      512M and whose certificate path resolves to the copied bundle.
- [ ] Update status fetches on that site with no cURL error in the log.
- [ ] A bundle named by `DRUPACK_CA_FILE` in the environment of the development
      server wins over the copied one.

---

## Phase 5: Real verification and the offline floor

**User stories**: 3, 4, 5, 26, 27, 28

### What to build

The case that proves the shipped anchors verify a real endpoint, not a file that
merely parses. It skips when the harness reports an offline run, so the offline
container stays green.

### Acceptance criteria

- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance RuntimeTrustOnline` passes,
      and asserts a 200 from the Drupal release history.
- [ ] That case fails against a build whose bundle is truncated to one
      certificate.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance OfflineRun` passes,
      and its inner run skips the network case by name.
- [ ] The network case passes on Windows and macOS.

---

## Phase 6: Override, audit and follow-through

**User stories**: 9, 10, 11, 12, 13, 17, 30

### What to build

The documentation and the upkeep that keep the trust set current. The README
gains `DRUPACK_CA_FILE` beside the other environment variables. The bundle's
source URL and SHA-256 sit beside the copy step. The dependency updates RFC
gains the watch line for the bundle, and the multi-platform plan's known issue
about php.ini closes.

### Acceptance criteria

- [ ] The README documents the variable, what it replaces, and the corporate
      proxy case.
- [ ] The recorded checksum matches the vendored file.
- [ ] The dependency updates RFC names the bundle among what the daily job
      watches.
- [ ] The multi-platform plan no longer lists the php.ini issue as open.
- [ ] A 64M upload succeeds on a site started from a release build.
- [ ] A site installed before this change clears its update error after one
      manual check.
