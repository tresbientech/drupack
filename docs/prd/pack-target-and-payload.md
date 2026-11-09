# Pack takes a target and a payload directory

## Problem Statement

A maintainer who adds a target or renames a payload file edits seven files.
`launcher/cmd/pack` takes 13 flags, and twelve lines call it: `drupack-build`,
the release workflow, the macOS and Windows build scripts, `native-pack.sh`,
`qa.sh` and the conformance harness. Each caller derives the entry name, the
architecture, the musl mark and four payload paths from a target and a
directory it already holds. The Node archive's name is rebuilt in bash, in
PowerShell and in the workflow, though `node.Archive` defines it in Go.

## Solution

A caller gives pack a target, a runtime directory, a payload directory, a
version and an output path. Pack derives the rest from the definitions Go
already holds, and prints what it resolved. A payload directory has one layout
everywhere. The files pack builds are the files it builds today.

## Out of Scope

- A description file inside the payload, or replaying a pack from one.
- Embedding the resolved inputs in the built file.
- A default for the target.
- Any change to `build/native-pack.sh`'s own arguments, which site repositories call.
- Any change to which targets carry Node.

## User Stories

1. As a maintainer, I want pack to take a target and a payload directory, so that a new target is one table entry.
2. As a maintainer, I want the Node rule in one place, so that a site asking for Node cannot ship a file without it unnoticed.
3. As a maintainer, I want pack to print its resolved inputs, so that I diagnose a wrongly packed file from the build log.
4. As a site author, I want my packed site unchanged, so that the refactor changes nothing I run.

## Implementation Decisions

- D1: Pack takes `-target`, `-runtime`, `-payload`, `-version` and `-output`, all required, plus `-site-version` and `-engine`. `-site-version` defaults to `-version`. Evidence: the flag list in `launcher/cmd/pack/main.go`; `build/native-pack.sh`, `build/qa.sh` and `drupack-build` pass a site version that differs, and the other callers pass the engine version twice.
- D2: `-site`, `-goarch`, `-entry`, `-source`, `-app`, `-app-checksum`, `-node` and `-musl` are removed. The launcher source is the working directory. Evidence: every caller runs from `launcher/` or sets that directory.
- D3: `-target` names a platform of `targets.json` plus a libc suffix, in the form `runtime.Target` returns. The architecture and the musl mark follow from it. A name outside the table stops pack. Evidence: `Target` and `Platforms` in `launcher/internal/runtime/targets.go`.
- D4: The entry name per platform is defined once in Go: `frankenphp.exe` on Windows and `drupack` elsewhere. Evidence: the `-entry` values at the twelve call sites.
- D5: A payload directory holds `build.PayloadName`, `build.ChecksumName`, the site's `siteconfig.OutputName` and a `node/` directory of archives named as `node.Archive` names them. An engine payload holds the first two. Evidence: `exportPayload` in `launcher/internal/build/build.go` writes this layout for the native builds.
- D6: `drupack-build` packs from a directory of that layout. Its own work directory takes it, so `packSteps` passes `-payload` and `-target` and builds no other pack flag. Evidence: `packSteps` assembles nine flags today from `payload`, `application` and `nodeArchives(r.Work)`.
- D7: A site whose `site.json` names `node` carries the archive `node.Archive` returns for the target. A missing archive stops pack. A target `node.Archive` reports as not carried gets none. Evidence: `builds` in `launcher/internal/node/node.go` has no musl entry; `exportPayload` copies one archive per target for a site carrying Node.
- D8: Pack splits into a function that reads the flags and the payload directory, and a function that writes the build copy. Both stay in `cmd/pack`. Evidence: `run` in `launcher/cmd/pack/main.go` does both today.
- D9: Pack prints one line per resolved input before it builds: target, architecture, entry, libc, payload directory, application checksum, Node archive or `none`, site name, and both versions.
- D10: Every caller moves to the new flags, and the code that derived the old ones is deleted: the `node` and `musl` arrays in `release.yml`, the `node` blocks in `macos/build.sh` and `native-pack.sh`, the `$node` lines in `build.ps1`, the `entry` and extension derivations in `native-pack.sh`, and the `entry` parameter of the harness's `_run_pack`.
- D11: `build/qa.sh` writes its edited `site.json` into the Node-free payload directory, so that directory has the layout of D5.
- D12: Four of the five tests in `launcher/cmd/pack/main_test.go` are rewritten on the two functions under names that keep their scenario. `TestWriteNodePayloadCarriesTheReleaseOrNothing` and the six tests of `tests/conformance/test_native_pack.py` are preserved. `TestParseTarget` and `TestPackPrintsItsResolvedInputs` are new. The tests of `launcher/internal/build/build_test.go` that assert a pack command assert the new one.
- D13: ADR 0055 records the decision.

### Considered options

- A `pack.json` description in the payload with a replay flag, built for flexibility. It lost because every payload builder would write a new format for variants that do not exist.
- Carrying Node when the archive file exists. It lost because a missing archive would pack a Node-free file in silence, and the musl rule would hide in a file name.

## Testing Decisions

- A good test builds a payload directory in a temporary directory, runs the resolving function, and reads the plan or the refusal. The build-copy function is checked by the files it writes.
- Tested: target parsing, payload resolution with the Node rule, the build copy, the printed inputs, and `drupack-build`'s pack command.
- Prior art: `launcher/cmd/pack/main_test.go`, the pack command assertions of `launcher/internal/build/build_test.go`, and `tests/conformance/test_native_pack.py`.

## Further Notes

The Windows and macOS callers run only in the release workflow, and a push to
`main` skips both. A dispatched run for `windows-amd64` and one for a macOS
target check them before the next tag. A wrongly packed file is diagnosed from
the pack step's printed inputs, read against the payload directory.
