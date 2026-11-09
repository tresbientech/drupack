# One reader for the address a site was served on

Proposed on 2026-11-09. Works inside [ADR 0050](../adr/0050-the-launcher-owns-a-start.md): `planStart` stays a pure function.

## Problem

Six places in `launcher/` read the Listener record and rebuild an address from it:

- `openSite` (`drush.go:71-107`), used by site drush and site export
- `engineExport` (`export.go:51-72`)
- `engineProject.drush` (`drush.go:224-236`), for the Mercure URL
- `packagedStart.served` (`sitestart.go:307-317`), for the handover URL

Each one merges typed options, the record and defaults with its own code. The results:

- The default listen `loopbackIP+":"+site.Port` is written at `sitestart.go:105` and `drush.go:98`.
- The fallback host `localhost` is defined as `siteFallbackHost` and as `unrecordedHost`.
- Two parsers read the same record. Site code uses `parseListen` and engine code uses `engineListen`.
- `engineExport` builds the static URL by hand, outside `planStart`.

`TestPlanStart` passes while the precedence between typed, recorded and default values has no test of its own.

## Proposed interface

New file `launcher/address.go`, package `main`.

```go
// Site holds what differs per kind of target. Each field is set once per mode.
type Site struct {
	Listen  string // default --listen
	Static  bool   // the project sets extra.drupack.static
	Noun    string // "site" or "project", for planStart's refusal
	AnyHost bool   // engine: engineListen; site: parseListen
}

type Typed struct{ Listen, StaticListen, Host string }

type Address struct {
	startPlan
	Recorded bool
	FilesDir string
}

func Served(dir state.Dir, name string, s Site, t Typed) (Address, error)
func (s Site) Listen(typed string) (bind string, port int, err error)
func (a Address) Mercure() string
```

`Served` is the call for every command that addresses what the last start served. A start calls `Site.Listen`, then `tls.Choose`, then `planStart` as it does today.

### Callers

```go
// openSite
a, err := Served(state.At(data), l.Name, siteTarget(site),
	Typed{c.options["listen"], c.options["static-listen"], c.options["host"]})

// engineExport, engineProject.drush
a, err := Served(f.entry, l.Name, f.target(), Typed{})

// startSite
bind, port, err := siteTarget(site).Listen(c.options["listen"])
```

### What `Served` hides

- the record read and its "remove that file, then start again" refusal
- the precedence: typed, then recorded, then default, for listen, static listen, host and files directory
- the parser choice
- the host and scheme of a Site data no start has served
- the `planStart` call

## Dependency strategy

Local-substitutable. The record is a file under a `state.Dir`. Tests write it into `t.TempDir()` with `WriteListener`, or with the `f.record(t, json)` helper for malformed JSON. No fake and no interface type.

## Testing strategy

New tests:

- `TestServed`: a table of typed options, record and `Site` in, `Address` out. It holds one row per precedence case and per refusal.
- `TestSiteListen`: the default and the typed value, for both parsers.

Existing tests:

| Test | Outcome |
|---|---|
| `TestPlanStart`, `TestEngineListen`, `TestParseListen`, `TestStaticListenerDefaultsToTheNextPort`, `TestValidHost` | Preserved. Their functions keep their signatures. |
| `TestSiteDrushEnvironment`, `TestEngineDrushEnvironment`, `TestEngineDrushReadsAHostnameListener`, `TestEngineDrushWithoutAServedHub` | Preserved as end-to-end checks on the `Command`. |
| `TestSiteDrushRefusals` | Preserved. It asserts each refusal reaches `drushPlan`. Its seven address rows are copied into `TestServed` under the same names. |
| `TestEngineExportReadsTheRecordedStaticAddress`, `TestEngineExportRefusals`, `TestSiteExportAddressesTheRecordedStaticListener`, `TestSiteExportDefaultsToTheNextPortOnUnrecordedSiteData` | Preserved. |

No existing test is deleted.

## Implementation recommendations

`Served` owns every read of the Listener record. A new command that needs the served URL calls it and derives nothing.

Deleted with this change:

- the `cmp.Or` chain in `openSite`
- the hand-built URL in `engineExport`
- the `engineListen` and `mercureURL` block in `engineProject.drush`
- the parse block in `packagedStart.served`
- `readListener` as a separate function
- `unrecordedHost` and `siteFallbackHost`, replaced by one constant

`packagedStart.served` keeps its fallback to the start's own plan when `Recorded` is false. No other code mixes a plan with a record.

A wrong URL is diagnosed from three inputs and one output: the record file, the typed options, the `Site` value, and the `Address`.

[The child environment RFC](child-environment.md) takes `Address` as an input and lands after this one.
