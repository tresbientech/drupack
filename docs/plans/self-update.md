# Plan: The Engine executable updates itself

**Spec:** docs/prd/self-update.md

## Architectural decisions

- D1: `launcher/update.go`, package main; the release URL is one constant
- D2: `run()` handles `self-update` before routing; `--check` alone
- D3: site, Composer and `dev` refusals before any network call, exit 1
- D4: `release.json` asset named `drupack` for this file's target
- D5: download to a temp file beside the executable, hashed; mismatch fails
- D6: `replaceExecutable` in `launch_unix.go` and `launch_windows.go`
- D7: an Engine run removes `PATH.old`
- D8: `newer` orders `X.Y.Z` and prerelease digit runs numerically
- D9: `pack -musl` stamps `musl bool`; target is `GOOS-GOARCH[-musl]`
- D10: system roots plus `DRUPACK_CA_FILE`
- D11: `updateNotice` after `runtime.Root`, off in CI, with
  `DRUPACK_NO_UPDATE_CHECK=1`, or with no terminal on stderr
- D12: `<cache root>/update-check` holds the newest version; one stderr line
- D13: stale or missing file: touch, then start `self-update --check` detached
- D14: `--check` fetches in 5 s, writes `update-check`, exits 0
- D15: the existing sweep covers the old runtime entry
- D16: `docs/cli.md`, `serve.php` USAGE, ADR 0031, ADR 0030

Commands the criteria name:

- Go: `(cd launcher && go test ./...)`
- PHP: `dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/launch_test.php"`
- Conformance: `DRUPACK_TEST_ENGINE=dist/engine/drupack-linux-amd64 python3 tests/conformance dist/drupacked-demo-linux-amd64 DIR --site-tests examples/drupacked-demo/tests -k EngineExecutable`
- Full chain: `bash build/qa.sh`

---

## Phase 1: `self-update --check` reports the newest release

**User stories**: 2, 5, 9, 11, 12, 13, 14, 15, 16
**Decisions**: D1, D2, D3, D4, D8, D9, D10, D14

### What to build

The word with `--check`: its three refusals, the fetch, the order, the target
pick and the `update-check` write. The pack stamp and its callers.

### Acceptance criteria

- [ ] `(cd launcher && go test ./...)` passes, with cases for `newer`
  (`alpha10` over `alpha3`, `0.9.0` against `1.0.0-alpha3`), each target, a
  missing target, the three refusals and the `update-check` write.
- [ ] `go test ./cmd/pack/` asserts the `-musl` stamp.
- [ ] `grep -n -- '-musl' .github/workflows/release.yml launcher/internal/build/build.go`
  prints the engine's musl pack and the drupack-build step.
- [ ] The conformance run passes, with a site executable's refusal and the
  Composer refusal as `EngineExecutable` cases.

---

## Phase 2: `self-update` replaces the executable

**User stories**: 1, 7, 8, 10
**Decisions**: D5, D6, D7

### What to build

The download beside the executable, the checksum, and the swap on Unix and
Windows. An Engine run clears a leftover `.old`.

### Acceptance criteria

- [ ] `(cd launcher && go test ./...)` passes, with a replace that keeps the
  file executable, a wrong SHA-256 that leaves it byte-identical with no temp
  file, and an unwritable directory refused before a request.
- [ ] `GOOS=windows go vet ./...` in `launcher/` passes.

---

## Phase 3: A run names a newer release

**User stories**: 3, 4, 5, 6
**Decisions**: D11, D12, D13, D15

### What to build

`updateNotice` on Engine runs: the off cases, the cached line, the 24 hour
touch and the detached `--check`.

### Acceptance criteria

- [ ] `(cd launcher && go test ./...)` passes, with each off case, a fresh file
  that sends no request, a stale file that spawns, and a newer cached version
  that prints the line.
- [ ] The conformance run passes, and no case's output carries the line.

---

## Phase 4: Docs name the word

**User stories**: none
**Decisions**: D16

### What to build

`docs/cli.md`, `serve.php` USAGE, ADR 0031 and the ADR 0030 amendment.

### Acceptance criteria

- [ ] `grep -n 'self-update\|DRUPACK_NO_UPDATE_CHECK' docs/cli.md engine/serve.php`
  prints the word in both and the variable in `docs/cli.md`.
- [ ] `test -e docs/adr/0031-the-engine-updates-itself.md` passes, and
  `grep -n self-update docs/adr/0030-the-launcher-routes-the-readers-words.md` prints a line.
- [ ] `launch_test.php` passes.
- [ ] `bash build/qa.sh` passes.
