# Pack takes a target and a payload directory

Proposed on 2026-11-09.

## Problem

`launcher/cmd/pack` takes 13 flags. Six files call it on 11 lines:

- `.github/workflows/release.yml`: Linux site and engine per libc, the Windows variant, the macOS variant
- `build/windows/build.ps1`: site and engine
- `build/macos/build.sh`: site and engine
- `build/native-pack.sh`
- `build/qa.sh`: the site without Node
- `tests/conformance/harness.py`: the fixture

Each caller derives the same flags from facts it already holds:

- `-app`, `-app-checksum`, `-site` and `-node` are four paths inside one payload directory.
- `-entry` is `drupack`, or `frankenphp.exe` on Windows.
- `-goarch` and `-musl` follow from the target and its libc, both listed in `targets.json`.
- `-source` is the module the command runs from.

The Node archive's name and extension are rebuilt in bash, in PowerShell and in the workflow. The rule that a musl file carries no Node lives in a workflow comment.

## Proposed interface

```sh
go run ./cmd/pack -target T -runtime DIR -payload DIR -version V -output FILE
```

| Flag | Meaning |
|---|---|
| `-target` | A `targets.json` platform plus a libc suffix: `linux-amd64`, `linux-arm64-musl`, `windows-amd64`. Required. |
| `-runtime` | The runtime directory, as today. |
| `-payload` | The directory holding `app-payload.tar.zst`, `app_checksum.txt`, `site.json` and `node/`. |
| `-version` | The engine version, as today. |
| `-output` | The path of the built file, as today. |
| `-site-version` | The site's release. Defaults to `-version`. Only `native-pack.sh` passes another value. |
| `-engine` | Pack the engine executable. The payload then holds no `site.json`. |

Removed: `-site`, `-goarch`, `-entry`, `-source`, `-app`, `-app-checksum`, `-node`, `-musl`.

### Inside `cmd/pack`

```go
type target struct {
	platform, goarch, entry string
	musl                    bool
}

func parseTarget(name string) (target, error)
func resolve(flags) (plan, error) // the only reader of flags and of the payload directory
func assemble(p plan, buildDir string) error
```

### The Node rule

A site whose `site.json` names `node` carries its archive on glibc, macOS and Windows. A missing archive stops the pack. A musl file carries none.

The site without Node needs no flag: `qa.sh` already deletes `node` from that payload's `site.json`.

### What pack hides

- the target's architecture, entry name and libc mark
- the payload's file names
- the Node archive's name, extension and carry rule
- the source module, which is the working directory

Pack prints one line per resolved input before it builds: target, architecture, entry, libc, payload directory, application checksum, Node archive or `none`, site name, both versions.

## Dependency strategy

Local-substitutable: the filesystem. Go tests build a payload in `t.TempDir()` with one helper, `payloadDir(t, withSite, withNode)`. `resolve` and `assemble` run without `go build`. The harness and `test_native_pack.py` cover the build itself.

## Call sites after the change

```sh
# release.yml, per libc; suffix is "" or "-musl"
go run ./cmd/pack -target "linux-$arch$suffix" -runtime "../runtime-$libc/out" \
  -payload ../dist/payload -version "$DRUPACK_VERSION" -output "../dist$directory/drupacked-demo"
go run ./cmd/pack -engine -target "linux-$arch$suffix" -runtime "../runtime-$libc/out" \
  -payload ../dist/engine-payload -version "$DRUPACK_VERSION" -output "../dist/engine$directory/drupack"

# native-pack.sh
go run ./cmd/pack -target "$target" -runtime "$work/runtime" -payload "$payload" \
  -version "$version" -site-version "$site_version" -output "$output"

# qa.sh, the site without Node
go run ./cmd/pack -target "$platform" -runtime "/opt/drupack/runtimes/$platform-glibc" \
  -payload "$no_node" -version "$DRUPACK_ENGINE_VERSION" -site-version dev -output ...
```

`build.ps1`, `macos/build.sh`, the two variant steps and the harness take the first form with their own target.

Deleted from the callers:

- the `node=()` and `musl=()` arrays in `release.yml`
- the `node=()` blocks in `macos/build.sh` and `native-pack.sh`
- the `$node` lines in `build.ps1`
- the `entry` and `node_extension` derivations in `native-pack.sh`
- the `entry` parameter of the harness's `_run_pack`

`qa.sh` writes its edited `site.json` into `$no_node`, so that directory is a whole payload.

## Testing strategy

| Test | Outcome |
|---|---|
| `TestWriteBuildCopyCarriesTheRuntime` | Rewritten as `TestAssembleCarriesTheRuntime`. |
| `TestASiteNamesNodeOnlyWhenItCarriesIt` | Rewritten as `TestResolveCarriesNodeWhenTheSiteNamesIt`: glibc carries, musl carries none, a missing archive fails. |
| `TestTheEngineExecutableCarriesTheEngineMark` | Rewritten as `TestResolveEngineNeedsNoSiteFile`. |
| `TestAMuslFileCarriesTheMuslMark` | Rewritten as a row of `TestParseTarget`, a table of target names to architecture, entry and libc mark. |
| `TestWriteNodePayloadCarriesTheReleaseOrNothing` | Preserved. |
| The six tests of `test_native_pack.py` | Preserved. The script's own interface does not change. |

New: `TestParseTarget` refuses a name outside `targets.json`. `TestPackPrintsItsResolvedInputs` asserts the printed lines.

## Implementation recommendations

The payload's file names, the entry names and the Node archive name are each defined once in Go. The code that writes a payload and the code that packs it read the same constants. The harness holds its own copy of the three file names.

`-target` has no default. Every caller names its target.

A wrongly packed file is diagnosed from the pack step's log, compared with the payload directory.

## Open question

The Node rule assumes every payload of a site that names `node` holds an archive for each glibc, macOS and Windows target. `macos/build.sh` states this in a comment. Confirm it in the code that fetches the archives before `resolve` treats a missing one as a failure.
