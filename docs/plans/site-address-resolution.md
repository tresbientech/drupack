# Plan: One reader for the address a site was served on

**Spec:** docs/prd/site-address-resolution.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: `Served(dir, name, Site, typed state.Listener) (Address, error)` owns every read of the Listener record.
- **D2**: a `Site` value per mode holds the default listen address and one mode flag.
- **D3**: one precedence, typed then recorded then default; the scheme is the record's or `state.UnrecordedScheme`.
- **D4**: one constant for the host of a site no name resolves for.
- **D5**: `Address` embeds the merged `state.Listener` and adds the bind, the port and `Recorded`, with `URL()`, `Mercure()` and `Plan(hasStatic, noun)`.
- **D6**: a start calls `Site.Listen`, then `tls.Choose`, then `planStart`, unchanged.
- **D7**: `packagedStart.served` falls back to its own plan when nothing is recorded.
- **D8**: every refusal keeps its text.
- **D9**: the replaced code is deleted.
- **D10**: `TestServed` and `TestSiteListen`; no existing test deleted or edited.
- **D11**: ADR 0052, and an amendment line in ADR 0050.
- **Tests**: `cd launcher && go test ./...` runs the Go tests. `cd launcher && GOOS=windows go build ./... && go vet ./...` checks the Windows build. `BASE` is the commit the branch starts from.

---

## Phase 1: Site drush and export read through `Served`

**User stories**: 1, 2, 3
**Decisions**: D1, D2, D3, D5, D8, D10

### What to build

`Served`, `Site`, `Typed` and `Address` exist, with the site constructor. `openSite` calls `Served` and takes its listeners, host, scheme and files directory from the result. `TestServed` holds the precedence rows and the seven address refusals of `TestSiteDrushRefusals`.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes.
- [ ] `go test . -run TestServed -v` in `launcher/` lists one passing row per precedence case and seven refusal rows named as in `TestSiteDrushRefusals`.
- [ ] `grep -c 'cmp.Or(listen' launcher/drush.go` prints 0.
- [ ] `git diff --numstat BASE..HEAD -- launcher/drush_test.go launcher/export_test.go launcher/start_test.go` shows no deleted line.
- [ ] `cd launcher && GOOS=windows go build ./... && go vet ./...` succeeds.

---

## Phase 2: The engine readers and the handover

**User stories**: 1, 3
**Decisions**: D1, D7, D8, D9

### What to build

`engineExport`, `engineProject.drush` and `packagedStart.served` call `Served`, with the engine constructor of `Site`. The engine's static URL and hub URL come from the returned `Address`. `readListener` moves into `Served`.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes.
- [ ] `grep -n 'readListener\|\.Listener()' launcher/*.go | grep -v _test.go` names `launcher/address.go` alone.
- [ ] `grep -c 'parseListen' launcher/export.go` and `grep -c 'engineListen' launcher/drush.go` both print 0.
- [ ] `TestServed` has an engine row with a hostname listener and a row with no record, both passing.
- [ ] `git diff --numstat BASE..HEAD -- launcher/drush_test.go launcher/export_test.go launcher/sitestart_test.go` shows no deleted line.

---

## Phase 3: The start side and one fallback host

**User stories**: 1, 3
**Decisions**: D2, D4, D6, D9, D10, D11

### What to build

`startSite` and `startEngine` take their bind and port from `Site.Listen`. One constant replaces the two fallback hosts. `TestSiteListen` covers the default and a typed value for both parsers. ADR 0052 records the decision, and this plan file is deleted.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with `TestSiteListen` in the output of `go test . -run TestSiteListen -v`.
- [ ] `grep -rn 'unrecordedHost\|siteFallbackHost' launcher` prints nothing.
- [ ] `grep -n 'strconv.Itoa(site.Port)' launcher/*.go | grep -v _test.go` prints one line.
- [ ] `git diff BASE..HEAD -- launcher/start.go | grep -c 'func planStart'` prints 0.
- [ ] `cd launcher && GOOS=windows go build ./... && go vet ./...` succeeds.
- [ ] `ls docs/adr/0052-*.md` names one file and `grep -c 0052 docs/adr/0050-the-launcher-owns-a-start.md` prints at least 1.
