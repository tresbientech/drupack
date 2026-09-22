# Plan: one Linux binary carrying both runtimes

Goal: a Linux reader gets the glibc runtime, which serves authenticated pages
several times faster, without choosing a download. Payoff: the one-file promise
holds, and container hosts keep a runtime that runs anywhere.

## What was measured

A study on 2026-09-22 compared two HEAD builds differing only in libc, against
one MariaDB, at 1 to 64 concurrent users. Anonymous throughput matched within 8
percent. Authenticated throughput did not: 141 requests per second for musl
against 488 for glibc at 16 users, 166 against 568 at 64, p95 526ms against
150ms. Reversing the run order reproduced the musl figure.

The glibc runtime needs at most `GLIBC_2.17` and links only core glibc
libraries, so no host from the last decade is too old for it.

## What was decided

- One Linux asset carries both runtimes. Asset names and README URLs do not
  change. The binary grows from about 125 MB to about 179 MB.
- The launcher reads `DRUPACK_LIBC` when set. Otherwise it stats the ELF
  interpreter that `pack` records per runtime. Anything else falls to musl.
- `--version` names the runtime that ran. No new command-line option.
- Windows and macOS keep one runtime each.
- Cache retention is unchanged. A `DRUPACK_LIBC` flip pays one cold unpack,
  measured at 1.1 to 1.5 seconds.

---

## Phase 1: The build produces two runtimes and one application

### What to build

The `build` stage currently runs composer, the translation fetch and
`drush site:install` inside the libc-specific builder image. Two variants built
that way repeat the whole Drupal install and produce two different application
payloads.

Split it. An `app` stage runs composer, translations and the site install once,
under one pinned builder, and emits the application payload. A thin runtime
stage per libc runs `embed.sh` and emits `/out`. BuildKit runs the two runtime
stages in parallel.

`cmd/pack` takes a runtime per libc instead of one, writes both payloads and
both manifests into `payload.go`, and records each runtime's ELF interpreter in
its manifest. A platform passing one runtime keeps today's behaviour.

### Acceptance criteria

- [x] `docker build --target artifact` produces one executable holding two
      runtime payloads and one application payload.
- [x] Both runtimes unpack the same application cache entry, so the executable
      carries the application once rather than once per runtime.
- [x] `cd packaging/launcher && go test ./...` exits 0, including a pack case
      for one runtime and a case for two.
- [x] The musl manifest records no interpreter. The glibc manifest records
      `/lib64/ld-linux-x86-64.so.2` on amd64 and the arm64 equivalent.
- [x] `docker build --target uncompressed` still exports the musl executable,
      and `uncompressed-gnu` exports the glibc one. CONTRIBUTING.md says so.

A byte-identical application payload across two builds of one commit was the
first version of the second criterion. `drush site:install` writes an install
timestamp, a random private key and a UUID into 480 config rows, so two runs
differ whatever the build does. Reproducibility needs frozen time and seeded
randomness, which is its own change.

---

## Phase 2: The launcher selects a runtime

### What to build

Selection runs before `runtime.Prepare`. `DRUPACK_LIBC` decides when it names a
runtime the binary carries. Otherwise the launcher stats each manifest's
recorded interpreter and takes the first that exists. A binary carrying one
runtime selects it without looking at anything.

The interpreter path is stat-only. Nothing joins it into a path and nothing
executes it.

### Acceptance criteria

- [x] A table test covers: `DRUPACK_LIBC` naming each runtime, an interpreter
      present, an interpreter absent, and a single-runtime binary.
- [x] On a glibc host the binary runs the glibc runtime, and `DRUPACK_LIBC=musl`
      runs the musl one.
- [x] Inside Alpine the binary runs the musl runtime with no variable set.
- [x] Flipping `DRUPACK_LIBC` twice leaves one runtime entry in the cache and
      serves a site both times.

---

## Phase 3: The runtime names itself

### What to build

`embed.sh` already passes `-X main.version`. It gains `-X main.libc=$SPC_LIBC`
from the branch that already reads that variable. `entrypoint.go` prints the
libc beside the version.

### Acceptance criteria

- [x] `drupack --version` prints the version and the runtime that ran.
- [x] `DRUPACK_LIBC=musl drupack --version` names musl on a glibc host.
- [x] A conformance case asserts both lines.

---

## Phase 4: Hardening the dynamic runtime

### What to build

The musl runtime is static-pie with no interpreter, so the dynamic loader never
runs. The glibc runtime brings the loader back, and with it three exposures the
static build did not have.

`ld.so` honours `LD_PRELOAD`, `LD_LIBRARY_PATH`, `LD_AUDIT` and
`GLIBC_TUNABLES`. `Environment()` forwards the parent environment untouched
apart from `PHPRC` and `DRUPACK_CA_FILE`, so a caller who sets any of them
injects code into the PHP process. Drop those four on Linux. musl ignores them,
so the drop needs no condition on the selected runtime.

The glibc runtime links with lazy binding: `readelf -d` shows `JMPREL` and no
`BIND_NOW`, against `FLAGS: BIND_NOW` on the musl one. Add `-Wl,-z,now` to the
`glibc)` branch in `embed.sh` for full RELRO and a read-only GOT.

`DRUPACK_LIBC` accepts `musl` and `glibc`. Any other value stops the start and
names the accepted pair, the way `activeKey` and `MintedSegment` already refuse
a value they cannot trust.

Both builder images stay pinned by digest and get reviewed together.

One exposure stays. The host's glibc enters the trust base, and the launcher
cannot verify it the way staging verifies every file it unpacks. A host glibc
fix also reaches Drupack without a release. ADR 0015 records both directions.

### Acceptance criteria

- [x] `readelf -d` on the packed glibc runtime shows `BIND_NOW`.
- [x] A test asserts `Environment()` drops `LD_PRELOAD`, `LD_LIBRARY_PATH`,
      `LD_AUDIT` and `GLIBC_TUNABLES`, and keeps every other variable.
- [x] A start under `LD_PRELOAD` pointing at a shared object serves a site
      without loading it.
- [x] `DRUPACK_LIBC=gnu` exits non-zero and names `musl` and `glibc`.
- [x] The Dockerfile pins both builder images by digest.

---

## Phase 5: CI proves both runtimes

### What to build

The Linux job runs its 65 conformance cases on a glibc runner, so detection
picks glibc and the suite covers that runtime whole. The Alpine step already
covers musl on a musl host. One new step forces `DRUPACK_LIBC=musl` on the
glibc runner, which proves the override and the musl runtime on a host that has
a choice.

### Acceptance criteria

These wait on a push: CI has not run this branch.

- [ ] The Linux job passes on amd64 and arm64.
- [ ] The forced-musl step starts a site and prints a version naming musl.
- [ ] The Alpine step passes unchanged.
- [ ] A tag build publishes the same asset names as today.

---

## Phase 6: What a reader is told

### What to build

`README.md` gains a table under Install: the host, the runtime it gets, and the
reason. No ratio, since one WSL2 host at one run per rung does not carry a
number that reads as a specification.

`docs/cli.md` documents `DRUPACK_LIBC` beside the other environment variables.

`docs/adr/0015-two-runtimes-in-one-executable.md` records the decision, cites
the measurement with its method and limits, and states the host-libc coupling
in both directions.

### Acceptance criteria

- [x] The README table names glibc hosts, musl hosts and the fallback.
- [x] `docs/cli.md` lists the variable and its two values.
- [x] ADR 0015 exists, numbered after 0014.
- [x] No user-facing document claims a throughput ratio.

---

## Out of scope

- A native-Linux re-measurement with repeats. The decision does not wait on it,
  and ADR 0015 states what the current number rests on.
- Changing cache retention so both runtimes stay warm. A flip costs one unpack.
- A `--libc` command-line option. `docs/backlog.md` already carries an open
  question about the options `dr` accepts and ignores.
- Windows and macOS, which have one libc each.

---

## State on 2026-09-22

Phases 1, 2, 3, 4 and 6 are merged on `main` at `e2cf739`, with 21 of 25
acceptance criteria met. The full conformance suite passed 58 tests with no
failures, and `cd packaging/launcher && go test ./...` is green.

Phase 5's four criteria stay open. They need a push, since CI has not run this
branch, and the Linux job now pulls one 8.6 GB builder image per libc on a
runner that starts with about 14 GB free.

Next session: push and read the Linux job, on amd64 and arm64.
