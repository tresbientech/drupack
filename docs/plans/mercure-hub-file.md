# Plan: A project tunes its Mercure hub

**Spec:** docs/prd/mercure-hub-file.md

## Architectural decisions

From the PRD, in its words:

- **D1**: Every hub turns on Mercure's subscription events and subscription API
  through the `subscriptions` directive.
- **D2**: Presence events share the hub's history of 1000 updates, and the size
  stays 1000.
- **D3**: Drupack mints no token. A site grants presence through the subscriber
  token it signs.
- **D4**: A project's hub directives live in `drupack.mercure.caddy`, beside
  `drupack.caddy`.
- **D5**: The hub fragment imports the file as its first line inside the
  `mercure` block, so Drupack's pinned lines win.
- **D6**: A start that finds a hub file while it serves no hub fails, naming the
  file and `extra.drupack.mercure`.
- **D7**: The server word names every project file in an adapter error.
- **D8**: The guards refuse `/drupack.mercure.caddy` by name.
- **D9**: Drupack checks no directive in the hub file. The docs warn about
  `playground`.
- **D10**: Drupack's hub keeps its other defaults.
- **D11**: The docs name both features.
- **D12**: ADR 0052 records D1 to D10 and amends ADR 0034 and ADR 0048.
- **D13**: The backlog's static-port hub entry loses its shared-transport claim.

Durable decisions the PRD leaves implicit:

- **Routes**: the subscription API at `/.well-known/mercure/subscriptions`, its
  events on `/.well-known/mercure/subscriptions/{match_type}/{match}/{subscriber}`,
  and the debugger UI at `/.well-known/mercure/debug/`.
- **Server word**: `php-server CADDYFILE [PROJECT-FILE...]`. The launcher and
  the runtime ship together, so no older argument shape needs support.

## Build and test loop

Fragments and the launcher are Go, and both repacks below build the launcher
from the working tree. Only Phase 3 changes the runtime.

- Engine: `drupack-build --engine-executable --engine /src` in the `drupack-job`
  container writes `dist/engine/drupack-linux-amd64`.
- Variant: `go run ./cmd/pack` in the `drupack-job` container repacks
  `dist/variant/variant-demo-linux-amd64` from
  `/tmp/drupack-qa/portable-drupal/variant-work/payload` and
  `dist/variant/site.json`, as `build/qa.sh` repacks its no-node copy.
- Conformance results go to one reused folder,
  `/tmp/drupack-qa/portable-drupal/results/hub-file`, deleted after each run.

---

## Phase 1: Presence on every hub

**User stories**: 1, 2
**Decisions**: D1, D2, D3, D11 (presence part)

### What to build

The hub fragment turns on `subscriptions`. A subscriber whose token grants the
subscriptions topic family receives an event when another subscriber opens or
closes a stream, carrying that subscriber's token payload. A token with an exact
grant on the collection reads the active list. The build guide gains the grant
shape and the history cost, and `docs/cli.md#mercure-hub` gains one line.

### Acceptance criteria

- [ ] `go test ./internal/caddyfile/` in `launcher/` passes after
  `go test ./internal/caddyfile/ -run TestRender -update` rewrites
  `site-everything.caddyfile`, whose diff adds the `subscriptions` line alone.
- [ ] `bash runtime/adapt-test.sh gnu` passes.
- [ ] `-k MercureHub` passes on the repacked variant:
  `python3 tests/conformance dist/variant/variant-demo-linux-amd64 /tmp/drupack-qa/portable-drupal/results/hub-file -k MercureHub`.
  It runs three new cases:
  - a presence event carries its payload on connect and on leave
  - the collection lists a subscriber of an absolute URL topic
  - the API refuses a token without the grant
- [ ] The existing `MercureHub` cases pass unchanged with the widened `token()`
  helper.

---

## Phase 2: A hub file on the engine

**User stories**: 3, 4, 7, 8
**Decisions**: D4, D5, D6, D8, D9, D10, D11 (hub file part)

### What to build

A start that finds `drupack.mercure.caddy` beside `drupack.caddy` imports it as
the first line of the hub block. A start that finds one with no hub refuses. The
guards refuse a request for the file. The build guide gains a hub-file
subsection with the `playground` warning. "A site's own Caddy rules" says a
`mercure` block there fails or splits the hub. `CONTEXT.md` names the file.

### Acceptance criteria

- [ ] `bash build/go-tests.sh` passes, and the regenerated
  `site-everything.caddyfile` shows the import as the hub block's first line.
- [ ] `bash runtime/adapt-test.sh gnu` passes with a hub file in the adapter
  test's project and application folders.
- [ ] On the rebuilt engine, `tests/conformance` with
  `DRUPACK_TEST_ENGINE=dist/engine/drupack-linux-amd64` and `-k EngineExecutable`
  passes, with three new cases:
  - a hub file with `anonymous` lets a subscriber with no token in
  - a hub file whose `cookie_name` differs still lets the
    `mercure_access_token` cookie in
  - a hub file in a project without the key fails the start, and the output
    names the file and `extra.drupack.mercure`
- [ ] The engine's guard case in the same run gets 403 for
  `/drupack.mercure.caddy`.

---

## Phase 3: Adapter errors name the hub file

**User stories**: 6
**Decisions**: D7

### What to build

The launcher hands the server word every project file it found. On an adapter
error the server word prints Caddy's message, then one line per file. The
runtime changes, so the `drupack-job` image is rebuilt before the engine.

### Acceptance criteria

- [ ] `bash runtime/adapt-test.sh gnu` passes.
- [ ] `docker build --target job -t drupack-job .` rebuilds the runtime, then
  the engine is rebuilt. `-k EngineExecutable` then passes with one new case: a
  broken hub file fails the start, and the output names it.
- [ ] The existing broken `drupack.caddy` case passes unchanged.

---

## Phase 4: A hub file in a packaged site

**User stories**: 5
**Decisions**: D4 (packaged part)

### What to build

`build/variant-fixture.sh` packs a hub file that turns on `debugger`. The
packaged variant serves the debugger UI, which shows that the payload carried
the file and the start imported it.

### Acceptance criteria

- [ ] `build/qa.sh variant` passes. It builds the variant from the fixture, runs
  the build's suite, and runs `NodeRefusals`, `MercureHub` and `ProjectRules` on
  the musl file.
- [ ] `ProjectRules` holds one new case: `/.well-known/mercure/debug/` answers
  200.

---

## Phase 5: Records

**User stories**: none
**Decisions**: D12, D13

### What to build

ADR 0052 records D1 to D10. ADR 0034 and ADR 0048 gain an "Amended by" line. The
backlog's static-port hub entry drops its shared-transport claim and cites the
engine test.

### Acceptance criteria

- [ ] `ls docs/adr/0052-a-project-tunes-its-mercure-hub.md` succeeds.
- [ ] `grep -l 0052 docs/adr/0034-*.md docs/adr/0048-*.md` lists both files.
- [ ] `grep -c 'would share nothing' docs/backlog.md` prints 0.
