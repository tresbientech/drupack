# PRD: One store holds the launcher's cache entries

## Problem Statement

The launcher's cache holds three kinds of unpacked entry: the runtime, the
application and the Node release. Each kind has its own code to lock, stage,
hold, sweep and clean. `CleanApps` and `CleanNode` are one loop with different
words. `unpackApp` and `LaySiteApp` each write their own staging swap. A
cache bug found in one kind stays in the other two.

`clean` misses two parts of the cache:

- It never removes runtime entries. The glossary and ADR 0002 say it does.
- It never removes the engine's `folders/` entries. `serve.php` writes one per
  folder it serves, and nothing removes them, even after the folder is deleted.

## Solution

One store type owns locking, staging, holding, sweeping and cleaning for every
cache kind. Each kind keeps its own readiness test and retention rule. `clean`
walks every kind: runtimes, applications, Node releases and folder entries. It
keeps an entry a running site or server uses, as today, and says so.

## Out of Scope

- New retention rules, such as a 30-day sweep for Node releases or folder entries.
- Changes to the PHP Serving lease or to how `serve.php` names a folder entry.
- A lock package such as `gofrs/flock` or `lockedfile`.
- Why the Drupacked Demo's cache on the owner's machine holds two runtime entries.

## User Stories

1. As a reader who runs `clean`, I want unpacked runtimes removed too, so that `clean` frees the space it promises.
2. As an engine user, I want `clean` to remove the state of folders no server runs, so that a deleted folder leaves nothing in the cache.
3. As an engine user, I want `clean` to keep a served folder's entry, so that the running server keeps its lease and stop record.
4. As a reader, I want `clean` to keep every entry a running site uses and name it, so that no site loses files under it.
5. As a reader, I want `clean --dry-run` to list every kind with its size, so that I see what `clean` would free.
6. As a Drupack developer, I want one store for the runtime, application and Node entries, so that a staging or cleanup fix lands once.
7. As a Drupack developer, I want the clean tests as one table over kinds, so that a new kind gets coverage from one more row.
8. As a Drupack developer, I want the hold function set once where a store is built, so that a test seam crosses no signature.

## Implementation Decisions

- D1. One store type in the runtime package owns a cache kind: its directory under the cache root, its hold function, its readiness test and its retention rule. Its entry points are prepare and clean. Evidence: `prepare` in `launcher/internal/runtime/cache.go`, `PrepareApp` in `app.go`, `PrepareNode` in `node.go`.
- D2. The kinds and their rules:
  - runtime: the cache root itself, keyed by `Key(version, payload)`, ready when its manifest and file sizes match, kept while active.
  - application: `app/`, keyed `r<checksum[:12]>`, ready when `.complete` exists, kept 30 days after `.used`.
  - Node: `node/`, keyed by `Key`, ready as the runtime is, kept until `clean`.
  - folder: `folders/`, written by `serve.php`, cleaned only.
  Evidence: `warm`, `Activate` and `removeOthers` in `cache.go`; `complete` and `sweepApps` in `app.go`; `folderEntry` in `engine/serve.php:128`.
- D3. The store carries the hold function. `PrepareRelease`, `Prepare`, `PrepareApp` and `PrepareNode` lose their `hold` parameter. `main.go` passes `HoldUsage` once, and tests build a store with a hold that keeps no handle. Invariant: hold runs before the root lock releases. Evidence: `hold` in `release.go:20`, `cache.go` `Prepare` and `prepare`, `app.go` `PrepareApp`, `node.go` `PrepareNode`.
- D4. One swap step fills a staging directory, writes the readiness marker last and renames the result into place. The store and `LaySiteApp` both call it. Invariant: a directory without its marker is never used, and staging runs under the target's lock. Evidence: `stage` in `cache.go`, `unpackApp` in `app.go`, `LaySiteApp` in `siteapp.go`.
- D5. `clean` makes one call over every kind, in the order runtime, application, Node, folder. Each kind lists its entries with sizes. It keeps an entry in use with the line `in use by a running site, kept`, and removes the rest unless `--dry-run` is given. The application and Node reports keep their strings. Evidence: `main.go:59-62`; `tests/conformance/launcher_cases.py:163` asserts `Removed 1 unpacked application` and `Run NAME clean`; `node_cases.py:103` asserts `node/<entry>` and `Removed 1 unpacked Node release`.
- D6. `clean` removes runtime entries, the active one included, so the next start unpacks again. It removes only a directory carrying a manifest this program wrote, or an abandoned staging directory. Invariant: `DRUPACK_CACHE_DIR` can name a directory holding the reader's own files. Evidence: the `removeOthers` comment in `cache.go`.
- D7. `clean` removes each `folders/` entry whose `serving.lock` no server holds. The in-use test takes an exclusive non-blocking lock on that file and releases it at once: `flock` on unix, `LockFileEx` on Windows. An entry moves to a staging name and is tested again before removal, as `removeOthers` does. Invariant: `Serving::lock` holds `flock(LOCK_EX)` on `serving.lock` for the server's life. Evidence: `application/process.php:262-273`.
- D8. Lock code stays hand-written, and `go.mod` gains no module. `lockRoot`, `HoldUsage` and `entryInUse` keep their bodies. The lease test sits beside them in `lock_unix.go` and `lock_windows.go`, using `golang.org/x/sys`, already required. Evidence: `launcher/go.mod`.
- D9. Each kind keeps its retention rule from D2. Evidence: ADR 0007, ADR 0027.
- D10. The old shape goes: `CleanApps`, `CleanNode`, `unpackApp`, `reportHeld`, `applicationWord`, `releaseWord`, the `hold` parameters and the near-copy tests. Evidence: `rtk grep -n "func CleanApps\|func CleanNode\|func unpackApp" launcher/internal/runtime`.
- D11. Docs follow:
  - `docs/cli.md`: the `clean` section and the engine's `clean` line
  - help texts: `application/launch.php:56` and `engine/serve.php:34`
  - `CONTEXT.md`: the `clean` command entry and the Runtime bullet
  - ADR 0043 records the store and the wider `clean`, amending ADR 0002 and ADR 0007
  - `docs/backlog.md` drops the entry
  Evidence: `docs/cli.md:272-277,364`, `CONTEXT.md:71,150`.

### Considered options

- `LaySiteApp` as a store kind. It lost because it holds one entry in Site data, under a lock of its own, with no sweep and no `clean`. Its carry-over hook would serve one kind.
- `gofrs/flock` for the root lock. It lost because it covers one of three lock uses. The `.inuse` hold must survive the exec into the runtime, and `gofrs/flock` opens with close-on-exec. On Windows the hold relies on an open handle blocking directory removal, which a byte-range lock does not give.
- Removing only the folder entries whose folder is gone. It lost because an entry's name is a hash of the folder path. `serve.php` would have to record the path, and today's entries carry none.

## Testing Decisions

A good test prepares or cleans through the store, then checks the directories
left and the report printed. It never reads the store's fields.

- Go, `launcher/internal/runtime`: the prepare cases run against the store for each kind they apply to: warm start, re-stage on a size change, concurrent starts, checksum mismatch, interrupted staging. Prior art: `cache_test.go`.
- Go: `clean` is one table over kinds. Rows cover listing, dry run, an entry another process holds, and reader content left in the root. A row holds `serving.lock` with `flock` and checks the folder entry stays. Prior art: `TestPreparesAndHoldsForAnotherProcess` in `cache_test.go`, `TestCleanNodeListsThenRemovesEachReleaseAndKeepsOneInUse` in `app_test.go`.
- Conformance, `tests/conformance/engine_cases.py`: `clean` removes the entry of a folder no server runs and keeps a served one's entry. Prior art: `test_a_detached_start_returns_once_ready_and_stop_ends_it`.
- The clean cases in `launcher_cases.py` and `node_cases.py` pass unchanged.

## Further Notes

- A start that claims a folder while `clean` moves its entry away fails with `Cannot open the serving lease`. The reader runs the start again.
- A start that opens `serving.lock` just before the move and locks it after the second test serves from a removed entry. A later start of that folder would then serve too. The window spans one `fopen` and one `flock` in `Serving::lock`.
- Windows runs only on release candidates. The `LockFileEx` lease test needs a `windows-amd64` dispatch before merge.
