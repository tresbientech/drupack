# Plan: One conformance suite for every platform

> Source PRD: [docs/prd/test-architecture.md](../prd/test-architecture.md)
> Source RFC: [docs/rfc/test-architecture.md](../rfc/test-architecture.md)

## Architectural decisions

These hold across every phase.

### Suite

- Command: `python3 tests/conformance EXECUTABLE RESULTS [unittest arguments]`,
  and `python` in place of `python3` on Windows, which ships no `python3`.
  Python 3.10 or later, standard library only.
- Layout: one directory holding a main module, one harness module, the harness
  unit tests, and one case module per product area.
- Results: each case writes its logs and Site data under its own directory in
  `RESULTS`.
- Run cache: one private temporary directory per run, passed as
  `DRUPACK_CACHE_DIR` and mounted into every container. Removed at exit.
- Marks: platforms `linux`, `macos`, `windows`. Tools `go`, `docker`. A missing
  tool on a marked platform fails the case.
- Ports: picked by the operating system on `127.0.0.1`. Only the default-port
  case binds 7225 on a host.
- Readiness: HTTP 200 on `/user/login` at the case's port, while the process
  lives.
- Waits: one table in the harness. A wait is its product deadline plus a margin,
  or a stated budget where the product has no deadline.
- Offline run: `--network none`, a slim Python image pinned by digest. The
  variable `CONFORMANCE_OFFLINE=1` marks the run inside the container.

### Release workflow

- Dispatch: `release.yml` input `platform`, one of `all`, `linux-amd64`,
  `linux-arm64`, `macos-arm64`, `macos-amd64`, `windows-amd64`. A job named
  `application` exports the application archive.

### Commands the criteria use

- `R=/tmp/drupack-qa`, and `dist/drupack` built by
  `docker build --target artifact --output type=local,dest=dist .`
- the suite: `python3 tests/conformance ./dist/drupack $R`
- a dispatch: `gh workflow run release.yml -R tresbientech/drupack --ref BRANCH
  -f platform=TARGET`, read with `gh run view ID -R tresbientech/drupack --json
  jobs --jq '.jobs[] | [.name, .conclusion]'`

Each phase's QA command is the step-0 chain with every ported file's entry
replaced by the suite. The ledger holds the current one.

---

## Phase 1: One-target dispatch

**User stories**: 25, 26, 27

### What to build

A `workflow_dispatch` run of `release.yml` takes a `platform` input and runs that
target's build and tests alone. A new `application` job builds the `build` stage
and uploads the application archive. Windows and macOS need it instead of the
Linux job, and the Linux amd64 job stops exporting the archive. A tag push, or
`platform=all`, runs every job as today. `publish` still runs on tags only. No
test changes.

### Acceptance criteria

- [ ] `go run github.com/rhysd/actionlint/cmd/actionlint@v1.7.12
  .github/workflows/release.yml` exits 0.
- [ ] A dispatch with `platform=windows-amd64` reports `application` and
  `Windows amd64` as `success`, and every other job as `skipped`.
- [ ] A dispatch with `platform=linux-arm64` reports `Linux arm64` as `success`,
  and every other job as `skipped`.
- [ ] `grep -A4 '^  publish:' .github/workflows/release.yml` shows
  `needs: [linux, windows, macos]` and `if: github.ref_type == 'tag'`.

---

## Phase 2: Harness and the site cases

**User stories**: 2, 3, 4, 5, 6, 7, 9, 10, 17, 22, 23, 28

### What to build

The suite exists and holds the seven `browser.py` cases, marked `linux` and
`macos`. Every start goes through the harness: run cache, picked port,
readiness on that port, stop with escalation, waits from the table. The
first-start-without-credentials case owns 7225 and fails naming the port when it
is taken. The `launcher.sh` case 0 merges into the working-directory case.

`offline.sh` runs the suite's site cases in its existing container. The macOS
job runs the suite in place of `browser.py`. `browser.py` and `launcher.sh` case
0 are deleted, and CONTRIBUTING names the suite.

### Acceptance criteria

- [ ] `time python3 -m unittest discover -s tests/conformance -p
  'test_harness.py'` passes in under 10 seconds with no `dist/drupack` present.
- [ ] The suite exits 0 on the Linux host.
- [ ] `bash tests/offline.sh ./dist/drupack $R/offline` exits 0.
- [ ] After `touch $R.stamp` and a suite run, `find ~/.cache/Drupack -newer
  $R.stamp 2>/dev/null` prints nothing.
- [ ] After a suite run, `ss -Hltn 'sport = :7225'` prints nothing.
- [ ] With a listener held on `127.0.0.1:7225`, the suite with `-k default_port`
  exits non-zero and its output contains `7225`.
- [ ] `grep -rn 'server.log' tests/conformance` prints nothing, and `test ! -e
  tests/browser.py` succeeds.
- [ ] A dispatch with `platform=macos-arm64` succeeds.

---

## Phase 3: Offline run in a slim image

**User stories**: 1, 15, 18, 19, 20, 21

### What to build

On the Linux host the site cases skip, naming the offline run. One Linux case,
marked `docker`, runs them in the slim Python image under `--network none` as
the host user, with the run cache mounted. Inside, `CONFORMANCE_OFFLINE=1` runs
the site cases and skips the offline case. `offline.sh` is deleted. The Linux
job runs the suite in its place, and the QA chain stops running the site cases
on the host.

### Acceptance criteria

- [ ] `python3 tests/conformance ./dist/drupack $R -v` exits 0. Its output marks
  each site case `skipped` and the offline case `ok`.
- [ ] The offline case's results directory holds a verbose log marking each
  site case `ok`.
- [ ] `grep -rn 'shm-size' tests .github` prints nothing, and `test ! -e
  tests/offline.sh` succeeds.
- [ ] With `PATH` reduced to a directory holding only `python3`, the suite with
  `-k offline` exits non-zero and its output names `docker`.
- [ ] Dispatches with `platform=linux-amd64` and `platform=linux-arm64` succeed.

---

## Phase 4: Argument checks and replacement

**User stories**: 12, 29, 30, 31

### What to build

The `database-init.sh` and `replacement.sh` cases move into the suite, marked
`linux` and `macos`. A start expected to refuse is bounded by its budget in the
wait table. Both files, their workflow steps, their CONTRIBUTING lines and the
macOS `coreutils` install are deleted.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `test ! -e tests/database-init.sh -a ! -e tests/replacement.sh` succeeds.
- [ ] `grep -n 'coreutils' .github/workflows/release.yml` prints nothing.
- [ ] The phase audit maps every assertion of both deleted files to a case.
- [ ] A dispatch with `platform=macos-arm64` succeeds.

---

## Phase 5: Launcher cases on Linux

**User stories**: 15, 16, 29, 32

### What to build

The remaining `launcher.sh` cases move into the suite, marked `linux`. Cold
start, simultaneous cold starts and the full-cache case each take a private
cache. The harness packs fixture launchers from a Go stub runtime, including the
corrupted variant. The Linux job gains Go and runs these cases. `launcher.sh` is
deleted.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `test ! -e tests/launcher.sh` succeeds.
- [ ] With `PATH` reduced to a directory holding `python3` and `docker`, the
  suite with `-k launcher` exits non-zero and its output names `go`.
- [ ] The phase audit maps every assertion of `launcher.sh` to a case.
- [ ] A dispatch with `platform=linux-amd64` succeeds, and `gh run view ID
  --log` shows the launcher cases `ok`.

---

## Phase 6: Initialization cases

**User stories**: 8, 11, 32

### What to build

The `initialization.sh` cases move into the suite, marked `linux`, with
PostgreSQL marked `docker`. The case about terminal output asserts the
address block, the `Drupack is ready.` line the server prints once it answers,
and the login link. The
interrupted start runs in its own process group. `initialization.sh` is
deleted.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `test ! -e tests/initialization.sh` succeeds.
- [ ] `grep -rn 'Drupack is ready' tests/conformance` matches only the
  terminal-output case and the default-port case.
- [ ] The phase audit maps every assertion of `initialization.sh` to a case.
- [ ] A dispatch with `platform=linux-amd64` succeeds, and its log shows the
  initialization cases `ok`.

---

## Phase 7: Server databases

**User stories**: 29

### What to build

The `server-database.sh` cases move into the suite, marked `linux` and
`docker`. A MySQL or PostgreSQL first start waits on the server-database budget
in the wait table. `server-database.sh` and its workflow step are deleted.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `test ! -e tests/server-database.sh` succeeds.
- [ ] The phase audit maps every assertion of `server-database.sh` to a case.
- [ ] Dispatches with `platform=linux-amd64` and `platform=linux-arm64` succeed.

---

## Phase 8: Network listener

**User stories**: 29

### What to build

The `network.sh` cases move into the suite, marked `linux` and `docker`. The
client container is the slim Python image. The unused third argument of
`network.sh`, an installed-data fixture, goes with the file, since no caller
passes it.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `test ! -e tests/network.sh` succeeds, and `grep -rn 'selenium' tests
  .github` prints nothing.
- [ ] The phase audit maps every assertion of `network.sh` to a case.
- [ ] Dispatches with `platform=linux-amd64` and `platform=linux-arm64` succeed.

---

## Phase 9: Windows site cases

**User stories**: 13, 14

### What to build

The `site.Tests.ps1` cases merge into their site twins, which gain the `windows`
mark. The harness stops a Windows start by killing its process tree. The Windows
job runs the suite, which covers those cases, and still runs
`launcher.Tests.ps1`. `site.Tests.ps1` is deleted.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `test ! -e tests/windows/site.Tests.ps1` succeeds.
- [ ] The phase audit maps every assertion of `site.Tests.ps1` to a case.
- [ ] A dispatch with `platform=windows-amd64` succeeds, and its log shows the
  merged site cases `ok`.

---

## Phase 10: Windows launcher cases

**User stories**: 1, 12, 13, 14, 31

### What to build

The `launcher.Tests.ps1` cases merge into their launcher twins, which gain the
`windows` mark. Windows-only cases stay marked `windows`. The fixture stub uses
the platform's entry name and reports `PHPRC`. `tests/windows` is deleted, and
CONTRIBUTING names one command. The repository's `qa.command` becomes the final
chain after the merge, since that config is shared with the main checkout, which
keeps the old suites until then.

### Acceptance criteria

- [ ] The suite exits 0 on the Linux host.
- [ ] `ls tests` prints only `conformance`.
- [ ] `grep -n 'tests/' CONTRIBUTING.md` names only the suite command.
- [ ] The phase audit maps every assertion of `launcher.Tests.ps1` to a case.
- [ ] A dispatch with `platform=windows-amd64` succeeds, and its log shows the
  launcher cases `ok`.
- [ ] A dispatch with `platform=all` at the branch head reports every test job
  and `application` as `success`.
