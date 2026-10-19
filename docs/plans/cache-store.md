# Plan: One store holds the launcher's cache entries

**Spec:** docs/prd/cache-store.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: One store type owns a cache kind: directory, hold function, readiness test, retention rule. Entry points: prepare and clean.
- **D2**: The kinds are runtime, application, Node and folder, each with the rules the PRD lists.
- **D3**: The store carries the hold function; the `hold` parameters go.
- **D4**: One swap step (stage, marker last, rename) serves the store and `LaySiteApp`.
- **D5**: `clean` makes one call over every kind; the application and Node reports keep their strings.
- **D6**: `clean` removes runtime entries that carry a manifest, and abandoned staging, nothing else in the root.
- **D7**: `clean` removes a folder entry whose `serving.lock` no server holds, with a move and a second test first.
- **D8**: Lock code stays hand-written; the lease test sits in `lock_unix.go` and `lock_windows.go`.
- **D9**: Each kind keeps its retention rule.
- **D10**: The old functions and near-copy tests go.
- **D11**: `docs/cli.md`, help texts, `CONTEXT.md`, ADR 0043 and the backlog follow.

Go commands run from `launcher/`. Conformance runs need a fresh build:
`build/qa.sh` builds both executables, or the build steps it names.

---

## Phase 1: The runtime and Node kinds run through the store

**User stories**: 6, 8
**Decisions**: D1, D2, D3, D9, D10

### What to build

The store type, with the runtime and Node kinds. `Prepare`, `PrepareNode` and
`PrepareRelease` build stores and lose `hold`. `main.go` passes `HoldUsage`
once. Activation and `removeOthers` stay with the runtime kind.

### Acceptance criteria

- [ ] `go test ./internal/runtime/ ./...` passes, with the `cache_test.go` prepare cases calling the store.
- [ ] `rtk grep -n "hold func" internal/runtime/cache.go internal/runtime/node.go internal/runtime/release.go` prints nothing outside the store type.
- [ ] `GOOS=windows go vet ./...` passes.

---

## Phase 2: The application kind and the shared swap

**User stories**: 6
**Decisions**: D2, D4, D9, D10

### What to build

The application kind joins the store, with `.complete`, `.used` and the
30-day sweep. One swap step replaces the staging code in `stage`, `unpackApp`
and `LaySiteApp`.

### Acceptance criteria

- [ ] `go test ./internal/runtime/` passes, the `app_test.go` sweep cases and every `siteapp_test.go` case included.
- [ ] `rtk grep -n "func unpackApp\|func sweepApps\|func PrepareApp" internal/runtime` prints nothing.
- [ ] `GOOS=windows go vet ./...` passes.

---

## Phase 3: `clean` walks every kind, runtimes included

**User stories**: 1, 4, 5, 7
**Decisions**: D5, D6, D10

### What to build

`main.go` calls one clean over the runtime, application and Node kinds. A
runtime entry goes when it carries a manifest; reader content in the root
stays. The three clean tests become one table over kinds.

### Acceptance criteria

- [ ] `go test ./internal/runtime/` passes a clean table with rows per kind: listing, dry run, an entry another process holds, and reader content in the root.
- [ ] `rtk grep -n "func CleanApps\|func CleanNode\|func reportHeld\|func applicationWord\|func releaseWord" internal/runtime` prints nothing.
- [ ] `python3 tests/conformance dist/drupacked-demo-linux-amd64 <results> -k ColdWarmStart -k SiteNamedCaches -k NodeClean` passes the existing clean cases unchanged.

---

## Phase 4: `clean` removes the folder entries no server runs

**User stories**: 2, 3, 4
**Decisions**: D2, D7, D8

### What to build

The folder kind and the lease test: `flock` on unix and `LockFileEx` on
Windows, on `serving.lock`, released at once. An unheld entry moves to a
staging name, is tested again, then goes.

### Acceptance criteria

- [ ] `go test ./internal/runtime/` passes a clean table row that holds `serving.lock` with `flock` from another process and finds the entry kept.
- [ ] `DRUPACK_TEST_ENGINE=dist/engine/drupack-linux-amd64 python3 tests/conformance dist/drupacked-demo-linux-amd64 <results> -k EngineExecutable` passes a new case: `clean` removes an unserved folder's entry and keeps a served one's.
- [ ] A `windows-amd64` dispatch of the release workflow passes the Go runtime tests.

---

## Phase 5: Docs and decision record

**User stories**: 1, 2
**Decisions**: D11

### What to build

The `clean` section of `docs/cli.md` and the engine's `clean` line name every
kind. The two help texts, the `CONTEXT.md` entries and ADR 0043 match. The
backlog entry goes.

### Acceptance criteria

- [ ] `rtk grep -n "own schedule" docs/cli.md` prints nothing.
- [ ] `rtk grep -n "One store for the launcher" docs/backlog.md` prints nothing.
- [ ] `docs/adr/0043-*.md` exists and amends ADR 0002 and ADR 0007.
- [ ] `python3 -m unittest discover -s tests/conformance -p test_environment.py` passes.
