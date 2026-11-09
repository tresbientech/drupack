# One reader for the address a site was served on

## Problem Statement

A maintainer who changes how Drupack picks a site's address has four places to
change. Drush, export, the engine's hub address and the handover of a second
start each read the Listener record and rebuild the address with their own code.
The default listen address is written twice, the fallback host twice, and two
parsers read the same record depending on the mode. `TestPlanStart` passes while
the order between a typed option, the record and the default has no test.

## Solution

One function answers "which address did the last start serve", for every command
that asks. It reads the record, applies one precedence, and returns the planned
listeners. A start keeps its own sequence and takes its default listen address
from the same value the readers use. No command line, message or URL changes.

## Out of Scope

- Any change to `planStart`, which [ADR 0050](../adr/0050-the-launcher-owns-a-start.md) keeps pure.
- One parser for both modes. The site parser takes an IP and the engine parser takes any host, on purpose.
- A record of where each field came from.
- A `status` or `open` command.
- The child's environment, which [its own PRD](child-environment.md) covers.

## User Stories

1. As a maintainer, I want one function to read the Listener record, so that a change to the precedence lands in one place.
2. As a maintainer, I want the precedence tested as a table, so that a wrong URL reproduces as one row.
3. As a site author, I want Drush and export to address the same URL as before, so that the refactor changes nothing I see.

## Implementation Decisions

- D1: One function, `Served(dir state.Dir, name string, s Site, typed state.Listener) (Address, error)`, in package `main`, owns every read of the Listener record. Evidence: the readers are `openSite` (`launcher/drush.go`), `engineProject.drush` (`launcher/drush.go`), `engineExport` (`launcher/export.go`) and `packagedStart.served` (`launcher/sitestart.go`).
- D2: A `Site` value holds what differs per mode: the default listen address and one mode flag. The engine's mode reads a listen address with the engine's parser and adds no restart advice to an unreadable record's refusal. One constructor per mode builds it, and the default listen literal lives there alone. Evidence: `loopbackIP+":"+strconv.Itoa(site.Port)` at `launcher/sitestart.go:105` and `launcher/drush.go:98`; `defaultListen` in `launcher/parse.go`.
- D3: `Served` applies one precedence: typed, then recorded, then default, for listen, static listen, host and files directory. The scheme is the record's, or `state.UnrecordedScheme`. Evidence: the `cmp.Or` chain in `openSite`; `UnrecordedScheme` in `launcher/internal/state/record.go`.
- D4: One constant names the host of a site no name resolves for. It replaces `unrecordedHost` and `siteFallbackHost`. Evidence: both are `"localhost"`, in `launcher/drush.go` and `launcher/sitestart.go`.
- D5: `Address` embeds the merged `state.Listener` (listen, static listen, host, scheme, files directory, each typed value over the recorded one) and adds the parsed bind and port and `Recorded`. The typed values arrive as a `state.Listener` too, so the change adds one type. `Address.URL()` and `Address.Mercure()` derive from the main address. `Address.Plan(hasStatic, noun)` calls `planStart`, and only a reader that needs the static listener calls it. Evidence: `planStart` builds a static address from the host and the next port and parses it as an IP, which refuses for a hostname listen; engine drush, the handover and the engine's hub read the main address alone.
- D6: A start calls `Site.Listen(typed)` for its bind and port, then `tls.Choose`, then `planStart`, whose signature does not change. Evidence: `startSite` and `startEngine` call `planStart` with the TLS answer's host and scheme.
- D7: `packagedStart.served` keeps its fallback to the start's own plan when `Recorded` is false. Evidence: the comment above that block in `launcher/sitestart.go` names the two cases with no record.
- D8: Every refusal keeps its text. Evidence: `TestSiteDrushRefusals`, `TestEngineDrushRefusals` and `TestEngineExportRefusals` assert the messages.
- D9: The change deletes what it replaces: the `cmp.Or` chain in `openSite`, the hand-built URL in `engineExport`, the listener block in `engineProject.drush`, the parse block in `packagedStart.served`, and `readListener` as a separate function.
- D10: `TestServed` is a table of typed options, record and `Site` in, `Address` out. It holds one row per precedence case and the seven address rows of `TestSiteDrushRefusals`, under the same names. `TestSiteListen` covers the start side for both parsers. No existing test is deleted or edited.
- D11: ADR 0052 records the decision and ADR 0050 gains an amendment line. Evidence: ADR 0050 lists the ADRs it amends in its header.

### Considered options

- One `Resolve` for starts and readers, built to minimise the interface. It lost because a start reads no record, so it passed a nil one, and the plan step split in two around the TLS call.
- A package `internal/address` with a per-field origin map, built for flexibility. It lost because the record is a file and the typed options are on the command line, so the origin is already readable.

## Testing Decisions

- A good test gives `Served` a record file, typed options and a `Site`, and reads the `Address`. None inspects a helper.
- Tested: `Served` and `Site.Listen`. The drush and export tests stay as end-to-end checks on the `Command`.
- The record is a real file in `t.TempDir()`, written with `WriteListener` or the `f.record` helper of `launcher/drush_test.go`.
- Prior art: the table of `TestPlanStart` in `launcher/start_test.go`, and the fixture of `TestSiteDrushRefusals`.

## Further Notes

A wrong URL is diagnosed from the record file, the typed options, the `Site`
value and the returned `Address`. The Windows build is checked by compiling for
it, since a push to `main` runs no Windows job.
