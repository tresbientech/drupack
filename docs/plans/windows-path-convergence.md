# Plan: Path handling converges on one form per language

> Source RFC: [docs/rfc/windows-path-convergence.md](../rfc/windows-path-convergence.md)
> Source PRD: [docs/prd/windows-path-convergence.md](../prd/windows-path-convergence.md)

Base branch: `worktree-windows-paths` at 615a186. The RFC edits
`RootRelativePath`, `SiteDataPublicStream`, `WindowsPathServiceProvider` and
`runtime/support/`, none of which sit on `main`.

## Architectural decisions

Durable across every phase.

- **Canonical form**: absolute, forward slashes, no trailing separator, no `.`
  or `..` segment. Every path Drupack computes, exports or prints takes it,
  including the reader's `--data-dir`. One form, so no reverse conversion.
- **PHP entry point**: `Symfony\Component\Filesystem\Path`, reached through
  `vendor/autoload.php`. No facade, no hand-required vendor file.
- **Go**: `path/filepath` keeps the native form inside the launcher.
  `filepath.ToSlash` converts at the three exported runtime variables.
- **Minted segment**: `^[a-z][a-z0-9.-]{0,31}$`, stated once in
  `internal/runtime` and enforced by `cmd/pack` at build time.
- **Drupal**: one service override per defect. No scaffold override, no
  Composer patch.
- **Container key**: `settings.php` hashes `DRUPACK_RUNTIME_APP_DIR` as the
  launcher exports it, which is the canonical form on every platform.

## Suite

Every phase runs the Linux chain first. The build comes before the rest,
because the conformance suite runs against the built executable.

```sh
/tmp/drupack-suite-lock.sh bash -c 'docker build --target artifact --output type=local,dest=dist .'
cd packaging/launcher && go test ./...
./dist/drupack php-cli tests/unit/windows_paths.php
python3 -m unittest discover -s tests/conformance -p 'test_harness.py'
/tmp/drupack-suite-lock.sh bash -c 'python3 tests/conformance ./dist/drupack test-results/conformance'
```

Another session works from a second worktree on this machine. Two conformance
runs at once bind the same ports and collide on container names. A measured
collision took 1800s against 467s alone and timed out nine site starts. Docker
builds and conformance runs take the shared lock. The go, unit and harness runs
do not.

## Platform runs

Windows runs from this checkout through WSL interop. Docker produces the
application archive in WSL, and the Windows side builds and tests against it.

```sh
docker build --target build -t drupack-build .
container=$(docker create drupack-build)
# build.ps1 reads application/app-payload.tar. embed.sh empties app.tar on
# purpose, so that frankenphp's embed init returns early and leaves
# EmbeddedAppPath unset.
docker cp "$container:/go/src/app/app-payload.tar" application/app-payload.tar
docker cp "$container:/go/src/app/app_checksum.txt" application/app_checksum.txt
docker rm "$container"
```

```powershell
./packaging/windows/build.ps1 -ApplicationDirectory application -Version dev `
  -WorkDirectory $env:TEMP\drupack -Output dist\drupack.exe
python tests/conformance dist\drupack.exe test-results\conformance
```

---

## Phase 1: One form reaches the reader

**User stories**: 1, 2, 5, 9, 17, 18, 28, 30

### What to build

The boundary conversion and its two consequences. The launcher keeps native
paths inside and exports canonical ones. `launch.php` prints canonical paths in
the lines a reader copies. `settings.php` hashes the application
directory as the launcher exports it.

A `realpath()` call returns the native form, so a resolution re-canonicalises
its result before the value travels.

### Acceptance criteria

- [ ] `cd packaging/launcher && go test ./...` exits 0, including a table
      asserting the canonical form of each of the three exported variables.
- [ ] That table fails when the conversion is removed from any one of them.
- [ ] A Windows start prints `Site data` and `Log` lines whose paths carry
      forward slashes, no trailing separator and no `..` segment.
- [ ] `php-cli` reports one `deployment_identifier` for the native form and the
      canonical form of the same directory, proving no site rebuilds its
      container.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` passes
      whole on Linux.
- [ ] `python tests\conformance dist\drupack.exe test-results\conformance` passes
      whole on Windows.

---

## Phase 2: Symfony Path replaces the helpers

**User stories**: 3, 4, 10, 11, 12, 13, 14, 19, 20, 27, 29

### What to build

`launch.php` requires `vendor/autoload.php` in place of its hand-written
require. `RootRelativePath`, `absolutePath()` and the join inside
`fromStartDirectory()` go, and their callers move to `Path::makeRelative()`,
`Path::isAbsolute()` and `Path::makeAbsolute()`.

`SiteDataPublicStream::getLocalPath()` keeps resolving with `realpath()`.
`Path::isBasePath()` replaces the text comparison that follows, never the
resolution.

The unit table keeps the boundaries Drupack still owns. Entries for the deleted
helpers go with them.

### Acceptance criteria

- [ ] `grep -rn 'RootRelativePath\|function absolutePath' --include='*.php' .`
      returns nothing outside `vendor/`, including in tests and docs.
- [ ] `./dist/drupack php-cli tests/unit/windows_paths.php` exits 0.
- [ ] A relative `--data-dir` resolves against the directory the reader started
      from, on Linux and on Windows.
- [ ] A public file whose target resolves outside Site data still answers 404,
      and the case asserting that still passes.
- [ ] One `dr` command is timed before and after the autoload change, on Linux
      and on Windows, and all four numbers land in the phase report.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` passes
      whole on Linux, and the same run passes on Windows.

---

## Phase 3: The minted segment rule earns a gate

**User stories**: 15, 16

### What to build

One exported rule in `internal/runtime` replacing the two comments that state
it today. `cmd/pack` refuses a `-version` that would mint a segment breaking it,
at build time, where `PrepareApp` already refuses a bad checksum at run time.

### Acceptance criteria

- [ ] `cd packaging/launcher && go test ./...` exits 0, including a table over
      accepted and refused versions. `vdev-27e7e19d151a` and `r2e2893a48a83`
      are accepted.
- [ ] `go run ./cmd/pack -version '1.0.0 beta'` exits non-zero and names the
      rule in its message.
- [ ] `grep -rn '\[a-z\]\[a-z0-9' packaging/launcher --include='*.go'` shows the
      rule in one place.
- [ ] The release build still produces an executable that prints its version.

---

## Phase 4: The crawl catches the eleventh defect

**User stories**: 6, 7, 8, 23, 24, 25, 26

### What to build

A conformance case class crawling a fixed page set: the front page,
`/card-components`, `/admin/dashboard`, `/admin/modules`, and one node carrying
an uploaded image, so an image style derivative address appears. It fails when
an `href`, a `src` or an inline style carries a drive letter, a backslash or the
application root prefix.

### Acceptance criteria

- [ ] The class passes on Linux against the built executable.
- [ ] It fails against a build with `WindowsPathServiceProvider` unregistered,
      proving the crawl reaches the fault.
- [ ] It passes on Windows against `dist\drupack.exe`.
- [ ] The uploaded image answers 200 at its rendered address on both platforms.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` passes
      whole on Linux, and the same run passes on Windows.

---

## Phase 5: The glossary states the rule

**User stories**: 21, 22

### What to build

`CONTEXT.md` gains the three terms the RFC defines: canonical path, minted
segment, application root. The RFC's header stops saying "Not implemented".

### Acceptance criteria

- [ ] `CONTEXT.md` defines all three terms, each in one sentence.
- [ ] The RFC header names the branch that implemented it.
- [ ] No other document still describes a path form this plan removed.
