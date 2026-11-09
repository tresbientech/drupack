# Plan: Pack takes a target and a payload directory

**Spec:** docs/prd/pack-target-and-payload.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: pack takes `-target`, `-runtime`, `-payload`, `-version`, `-output`, plus `-site-version` and `-engine`.
- **D2**: eight flags are removed, and the launcher source is the working directory.
- **D3**: `-target` has the form `runtime.Target` returns; a name outside `targets.json` stops pack.
- **D4**: the entry name per platform is defined once in Go.
- **D5**: one payload layout: `build.PayloadName`, `build.ChecksumName`, `siteconfig.OutputName`, and `node/` named by `node.Archive`.
- **D6**: `drupack-build` packs from a directory of that layout.
- **D7**: a site naming `node` carries the archive `node.Archive` returns; a missing one stops pack.
- **D8**: pack splits into a resolving function and a build-copy function, both in `cmd/pack`.
- **D9**: pack prints one line per resolved input.
- **D10**: every caller moves, and the code that derived the old flags is deleted.
- **D11**: `build/qa.sh` writes its edited `site.json` into the Node-free payload directory.
- **D12**: the test mapping of the PRD.
- **D13**: ADR 0055.
- **Tests**: `cd launcher && go test ./...` runs the Go tests. `bash build/unit-tests.sh` runs the Python unit files, of which `test_native_pack.py` runs a real pack. `bash -n FILE` parses a shell script.

---

## Phase 1: Pack and its Go and Python callers

**User stories**: 1, 2, 3, 4
**Decisions**: D1, D2, D3, D4, D5, D6, D7, D8, D9, D12

### What to build

Pack takes the new flags and derives the rest. `drupack-build` lays one payload directory and passes it. `build/native-pack.sh` and the harness's fixture pack call the new flags, since the unit suite runs both. The four rewritten Go tests keep their scenario names, and the pack command assertions of the build tests assert the new command.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes.
- [ ] `go test ./cmd/pack -v` in `launcher/` lists `TestParseTarget`, `TestPackPrintsItsResolvedInputs`, `TestWriteNodePayloadCarriesTheReleaseOrNothing`, and one test each for the runtime in the build copy, the Node rule, the engine pack and the musl mark.
- [ ] The Node rule test has three passing rows: glibc carries, musl carries none, a missing archive fails.
- [ ] `cd launcher && go run ./cmd/pack -h 2>&1 | grep -c -- '-goarch\|-entry\|-musl\|-node\|-app-checksum\|-source'` prints 0.
- [ ] `bash build/unit-tests.sh` passes, with the six tests of `test_native_pack.py` unchanged: `git diff --numstat BASE..HEAD -- tests/conformance/test_native_pack.py` shows no deleted line.
- [ ] `grep -c '"-goarch"\|"-app"\|"-musl"' launcher/internal/build/build.go` prints 0.

---

## Phase 2: The release callers

**User stories**: 1, 4
**Decisions**: D10, D11, D13

### What to build

The release workflow's four pack steps, `build/macos/build.sh`, `build/windows/build.ps1` and `build/qa.sh` call the new flags. The arrays and derivations that fed the old flags are deleted. `qa.sh`'s Node-free directory holds its `site.json`. ADR 0055 records the decision, and this plan file is deleted.

### Acceptance criteria

- [ ] `grep -rn -- '-app-checksum\|-goarch\|-site-version' build .github tests/conformance/harness.py | grep -v 'native-pack.sh\|qa.sh'` prints nothing, and the two named files pass `-site-version` alone.
- [ ] `grep -n 'node=()\|musl=()\|node_extension' build .github -r` prints nothing.
- [ ] `bash -n build/qa.sh build/macos/build.sh build/native-pack.sh` succeeds.
- [ ] `bash build/unit-tests.sh` passes.
- [ ] `ls docs/adr/0055-*.md` names one file.
- [ ] After the merge reaches GitHub, the `linux` job of that push's `release.yml` run is green.
- [ ] A dispatched `release.yml` run for `windows-amd64` and one for `macos-arm64` are green. Each dispatch waits for the owner's approval.
