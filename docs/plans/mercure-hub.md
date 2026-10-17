# Plan: A Mercure hub a Packaged site turns on

**Spec:** docs/prd/mercure-hub.md

## Architectural decisions

- **D1**: `drupack.yml` gains `mercure`, a boolean, false when absent; the strict parse refuses any other type.
- **D2**: `site.json` always carries `mercure`; `launch.php` reads it with no fallback.
- **D3**: Site data holds `mercure_publisher_secret` and `mercure_subscriber_secret`, created by a start when missing, 32 random bytes in hex, mode 0600.
- **D4**: `drush` creates no secret, and exports the hub variables only when both files exist.
- **D5**: Start and `drush` export `DRUPACK_MERCURE_URL`, `DRUPACK_MERCURE_PUBLISHER_SECRET` and `DRUPACK_MERCURE_SUBSCRIBER_SECRET`.
- **D6**: `application/Caddyfile` imports `"{$DRUPACK_RUNTIME_MERCURE_SNIPPET}"`, which names `mercure.caddy` or `none.caddy`.
- **D7**: `mercure.caddy` mounts the hub with both JWT keys, HS256, bolt at `mercure.db` in Site data, `size 1000`, every optional endpoint off.
- **D8**: The hub sits in `php_server`'s route block, ahead of it, so `mercure_publish()` finds it.
- **D9**: The Engine executable does not change.
- **D10**: The Node fixture becomes the variant fixture, `variant-demo`, with `mercure: true` and a probe that publishes.
- **D11**: ADR 0034, `docs/build-your-site.md` and `docs/cli.md`.
- **Route**: `/.well-known/mercure` on the site's own port.
- **Authorization**: subscribers and HTTP publishers present an HS256 bearer token; `mercure_publish()` needs none.

---

## Phase 1: The contract key

**User stories**: 1, 2, 3
**Decisions**: D1, D2

### What to build

`drupack.yml` accepts `mercure`. The build writes it into `site.json` as `true` or `false`. `launch.php` reads the value; nothing acts on it yet.

### Acceptance criteria

- [x] `(cd launcher && go test ./internal/siteconfig/...)` passes with cases for `mercure` true, false, absent and a string.
- [x] The same tests show `site.json` holding `"mercure": false` for a contract without the key.

---

## Phase 2: A start serves the hub and a request publishes

**User stories**: 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 20, 21, 22
**Decisions**: D3, D5 (start), D6, D7, D8, D9, D10

### What to build

A start of a site with the key creates the secrets and exports the variables. It points the Caddyfile at the hub snippet, and the hub serves on the site's port. The variant fixture replaces the Node fixture, sets the key and ships a probe that publishes a private update. A `MercureHub` conformance case subscribes, publishes through the probe, replays with `Last-Event-ID`, refuses bad tokens and checks the secret files. QA and every CI job on the fixture run it.

### Acceptance criteria

- [x] `python3 tests/conformance dist/variant/variant-demo-linux-amd64-musl <results> -k MercureHub -k NodeRefusals` passes after the variant build `build/qa.sh` runs.
- [x] `python3 tests/conformance dist/drupacked-demo-linux-amd64 <results> -k MercureHub` passes: 404 at `/.well-known/mercure`, no secret file.
- [x] `git grep -n -E "node-fixture|node-demo|dist/node\b" -- ':!docs'` prints nothing.
- [x] `git diff main -- engine/` prints nothing.

---

## Phase 3: Drush and HTTP publishing

**User stories**: 5, 17, 18
**Decisions**: D4, D5 (drush)

### What to build

`drush` on a site with the key exports the three variables once a start created the secrets, and creates nothing itself. The `MercureHub` case publishes over HTTP with a token signed by the publisher secret, and checks that `drush php:eval` sees the variables.

### Acceptance criteria

- [x] `python3 tests/conformance dist/variant/variant-demo-linux-amd64-musl <results> -k MercureHub` passes with the HTTP publish and Drush cases.
- [x] A `MercureHub` case runs `drush` on Site data with no secret files and finds none created afterwards.

---

## Phase 4: Documentation

**User stories**: 19
**Decisions**: D11

### What to build

ADR 0034 records the opt-in hub, its secrets and its transport. `docs/build-your-site.md` lists the key. `docs/cli.md` lists the three variables and the two ways to publish.

### Acceptance criteria

- [x] `test -f docs/adr/0034-*.md` succeeds and `grep -n "mercure" docs/build-your-site.md docs/cli.md` prints the key and all three variables.
- [ ] `bash build/qa.sh` passes on the branch head, run from the main checkout, and writes `qa-green`.
