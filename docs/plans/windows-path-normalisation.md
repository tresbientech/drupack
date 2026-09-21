# Plan: Windows path handling and one application extraction

> Source PRD: [docs/prd/windows-path-normalisation.md](../prd/windows-path-normalisation.md)
> Source RFC: [docs/rfc/windows-path-normalisation.md](../rfc/windows-path-normalisation.md)
> Source ADR: [docs/adr/0002-shared-application-extraction.md](../adr/0002-shared-application-extraction.md)

## Architectural decisions

Durable across every task.

- **Namespace**: `Drupack\Support\`, autoloaded from the application root by a
  PSR-4 entry in the packaged Composer project.
- **Container wiring**: a class registered in
  `$GLOBALS['conf']['container_service_providers']` from the site settings, which
  the kernel reads without any module. It calls `setClass()` on the two existing
  service definitions, so core keeps its own constructor arguments.
- **Replaced services**: `Drupal\Core\Theme\Icon\IconFinder` and
  `plugin.manager.sdc`.
- **Application cache**: `<user cache>/Drupack/app/<checksum>`, with a completion
  marker written last. `frankenphp.EmbeddedAppPath` stays empty, and the entry
  point changes its working directory instead.
- **Public files**: `file_public_path` at `<data>/files`, `file_public_base_url`
  at the root-relative `/sites/default/files`, and a server route mapping that
  prefix onto Site data.
- **Retention**: an entry lives while used within 30 days, by the rule the
  launcher cache retention RFC already sets.

## Suite

Every task runs the Linux suite first. The build comes before the rest, because
the conformance suite runs against the built executable.

```sh
docker build --target artifact --output type=local,dest=dist .
cd packaging/launcher && go test ./...
python3 -m unittest discover -s tests/conformance -p 'test_harness.py'
python3 tests/conformance ./dist/drupack test-results/conformance
./dist/drupack php-cli tests/unit/windows_paths.php
```

The last command joins from task 1.

## Windows runs

Windows runs from this checkout through WSL interop, so a task is proven on the
platform carrying the defect without waiting for CI. Docker runs in WSL only, so
the Linux side produces the application archive and the Windows side consumes it.

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

Confirmed present on the Windows side:

- Go 1.27.0
- Python 3.13.0
- Visual Studio Build Tools 2022, with its Clang component
- a warm work directory holding the downloads and the vcpkg tree

A rebuild reuses that work directory.

Tasks 1 and 2 repair defects that appear on Windows alone, so their cases must
run there. Tasks 3 to 6 change behaviour on every platform, and their Windows
run confirms the platform with the different separator and the different process
model.

---

## Task 1: Icons render on Windows

**User stories**: 1, 4, 5, 6, 22, 23, 24, 25

### What to build

A pure function that answers whether a URI names a local file the product may
read. It permits a one-character scheme, and refuses every other scheme and any
host. An icon reader subclass calls it before reading.

Four wiring changes carry it into the running site:

- a service provider registers the subclass
- the site settings register that provider
- the packaged Composer project gains the namespace
- the build regenerates its autoloader after the runtime files arrive

A conformance case starts a site and asserts that its icon elements carry SVG
content.

### Acceptance criteria

- [ ] `./dist/drupack php-cli tests/unit/windows_paths.php` exits 0.
- [ ] That unit table asserts a refusal for `public://x.svg`, `http://h/x.svg`,
      `//host/share/x.svg` and `php://input`, and an acceptance for `C:\x.svg`
      and `C:/x.svg`.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance WindowsPathCases.test_icons_carry_their_svg_content` passes.
- [ ] `python tests/conformance dist\drupack.exe test-results\conformance WindowsPathCases.test_icons_carry_their_svg_content` passes on Windows.
- [ ] That Windows case fails against the current release, proving it reaches the fault.
- [ ] `cd packaging/launcher && go test ./...` exits 0.
- [ ] `python3 -m unittest discover -s tests/conformance -p 'test_harness.py'` exits 0.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` exits 0.

---

## Task 2: Addresses carry no disk path

**User stories**: 2, 3, 4, 5, 6, 22, 23, 24, 25

### What to build

A pure function that expresses an absolute application path relative to the
application root, with forward slashes. A component discovery subclass calls it
where core records a component directory. The provider from task 1 registers
that subclass too.

Conformance assertions cover the rendered page: no address carries a drive
letter, no address carries a backslash, and a component example asset answers
with a success status.

### Acceptance criteria

- [ ] `./dist/drupack php-cli tests/unit/windows_paths.php` exits 0.
- [ ] That unit table covers a mixed-separator absolute path, a uniform absolute
      path, an already relative path, and a root carrying a trailing separator.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance WindowsPathCases` passes, including the three address assertions.
- [ ] The rendered page fetched by those cases contains no `%3A` and no `%5C`.
- [ ] `python tests/conformance dist\drupack.exe test-results\conformance WindowsPathCases` passes on Windows.
- [ ] Those Windows cases fail against the current release, proving they reach the fault.
- [ ] `cd packaging/launcher && go test ./...` exits 0.
- [ ] `python3 -m unittest discover -s tests/conformance -p 'test_harness.py'` exits 0.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` exits 0.

---

## Task 3: Public files leave the application directory

**User stories**: 18, 19, 20

### What to build

Site settings point the public file path at Site data and supply a root-relative
base address. The server maps the public file address prefix onto Site data. The
launcher stops linking settings and files into the application copy, and the
application ships a settings file that includes the one in Site data.

Extraction stays per-site in this task.

### Acceptance criteria

- [ ] No symlink exists under the application directory after a start, checked by
      a conformance assertion.
- [ ] A file written to public storage answers 200 at its rendered address.
- [ ] A site started on one port, then restarted on another, serves a working
      address for the same file.
- [ ] An existing Site data directory from the previous release still serves its
      uploaded files after the change.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` exits 0.
- [ ] The same conformance run passes on Windows against `dist\drupack.exe`.
- [ ] `cd packaging/launcher && go test ./...` exits 0.

---

## Task 4: One extraction per release

**User stories**: 9, 10, 11, 12, 21

### What to build

The entry point carries the application archive and unpacks it into the shared
per-user cache, writing a completion marker last. A start that finds the marker
unpacks nothing. The build stops embedding the archive in the bundled server.
The entry point changes its working directory to the cache. The launcher's
restart goes, along with the temporary directory redirection that required it.

### Acceptance criteria

- [ ] A first start creates exactly one application directory under the user
      cache, and none under Site data or the system temporary directory.
- [ ] A second start of the same release creates no new directory and rewrites no
      file, asserted by comparing directory modification times.
- [ ] Two sites of one release share one application directory.
- [ ] A start interrupted before the marker is written unpacks again on the next
      start.
- [ ] Stopping the server with a signal leaves the application directory present.
- [ ] The replacement cases assert the new location and pass.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` exits 0.
- [ ] The same conformance run passes on Windows against `dist\drupack.exe`.
- [ ] `cd packaging/launcher && go test ./...` exits 0.

---

## Task 5: The first start reports progress

**User stories**: 7, 8

### What to build

The extractor reports bytes written against the declared total while it unpacks.
A start that unpacks nothing prints no progress.

### Acceptance criteria

- [ ] A first start writes at least two progress reports naming a byte count and
      the total, asserted by a conformance case reading the start's output.
- [ ] The final report names the same total the manifest declares.
- [ ] A second start of the same release writes no progress report.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` exits 0.
- [ ] The same conformance run passes on Windows against `dist\drupack.exe`.
- [ ] `cd packaging/launcher && go test ./...` exits 0.

---

## Task 6: Old copies leave

**User stories**: 13, 14, 15, 16, 17

### What to build

A used marker written under the cache lock on every start. Cleanup removes
entries unused for 30 days. A start on this release removes the previous
layout's directories, and reports the count and the bytes freed. A command
reports and clears cache entries on demand.

### Acceptance criteria

- [ ] A Go test proves an entry with a 31 day old marker is removed and one with
      a 29 day old marker stays.
- [ ] A Go test proves a directory matching the old name pattern, without a file
      this product ships inside it, stays.
- [ ] A start with previous-layout directories present removes them and prints
      the count and the freed bytes.
- [ ] The clean command with a dry-run flag lists entries and frees nothing,
      asserted by a conformance case.
- [ ] The clean command removes the entries it listed.
- [ ] The same conformance run passes on Windows against `dist\drupack.exe`.
- [ ] `cd packaging/launcher && go test ./...` exits 0.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` exits 0.
