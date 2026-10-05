# Plan: One serving-state type for sites and folders

**Spec:** docs/prd/serving-state.md

## Architectural decisions

- D1: `final class Serving` in `application/process.php`
- D2: `new Serving($state, $logDirectory)`; `serving.lock`, `stop.json` and
  `server.log` live in the class alone
- D3: `public readonly string $stopRecord` for Caddy
- D4: `claim(): bool` keeps the handle until `pcntl_exec`
- D5: `detach(foreground, stopCommand, arguments)` replaces `detachStart` and
  `detachServer`
- D6: `stop(what, named): int` replaces `stopServer` unchanged in behaviour
- D7: `launch.php` keeps its refusals between `claim()` and `detach()`
- D8: `stopSite()` and `stopFolder()` each end in one `stop()`
- D9: `serve.php` builds `new Serving($entry, $entry)`
- D10: the restated getters and the five free functions go
- D11: `runtime/watch/testdata/stop.json`, checked against what `serveStop`
  writes
- D12: `serving_test.php` with a child stub that claims the lease and serves
  the fixture

The on-disk layout, every message and every exit status stay.

Commands the criteria name:

- Go: `(cd runtime/watch && go test ./...)`
- PHP: `dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/<file>"`
- Full chain: `bash build/qa.sh`

---

## Phase 1: The `stop.json` shape has one fixture

**User stories**: 4
**Decisions**: D11

### What to build

The fixture, and a Go case that runs the stop channel and compares the keys of
the record it writes with the fixture's.

### Acceptance criteria

- [ ] `(cd runtime/watch && go test ./...)` passes.
- [ ] Renaming the `token` JSON tag in `watch.go` makes that run fail, then the
  rename is reverted.

---

## Phase 2: A folder serves and stops through `Serving`

**User stories**: 1, 2, 3, 4, 5, 6, 7
**Decisions**: D1, D2, D3, D4, D5, D6, D8 (folder), D9, D12

### What to build

The class with its unit test, wired into `qa.sh`. The Engine's folder start and
stop use it, and the folder getters go. The site keeps the free functions until
phase 3.

### Acceptance criteria

- [ ] `serving_test.php` passes with the seven cases the PRD lists.
- [ ] `grep -n 'entryLease\|entryStopRecord\|entryLog' engine` prints nothing.
- [ ] `serve_test.php` passes.
- [ ] `grep -n serving_test build/qa.sh` prints the line.

---

## Phase 3: A site serves and stops through `Serving`

**User stories**: 1, 2, 3, 5, 6, 7
**Decisions**: D7, D8 (site), D10

### What to build

`launch.php` builds a `Serving` for its start, its stop and its Caddy export.
The `SiteData` getters and the five free functions go.

### Acceptance criteria

- [ ] `grep -rn 'takeLease\|leaseFree\|detachServer\|detachStart\|stopServer' application engine`
  prints nothing outside the class.
- [ ] `grep -n 'function lease\|function stopRecord\|function serverLog' application/support/SiteData.php`
  prints nothing.
- [ ] `launch_test.php`, `site_data_test.php`, `serve_test.php` and
  `serving_test.php` pass.
- [ ] `bash build/qa.sh` passes, with the stop, detached, handover and engine
  conformance cases in its runs.

---

## Phase 4: Close the backlog entry

**User stories**: none
**Decisions**: none

### What to build

The backlog entry "One serving-state type for sites and folders" goes, and this
plan is deleted.

### Acceptance criteria

- [ ] `grep -n 'serving-state type' docs/backlog.md` prints nothing.
- [ ] `test -e docs/plans/serving-state.md` fails.
