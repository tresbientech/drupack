# A project tunes its Mercure hub

## Problem Statement

A site with `extra.drupack.mercure` gets a hub whose directives Drupack fixes in
one fragment. A site author cannot change any of them.

- A page served from Drupal's page cache carries no per-user cookie, so it
  cannot subscribe. The hub refuses every subscriber without a token, and
  Mercure's `anonymous` directive is off.
- A collaboration feature cannot show who else is editing. Mercure's
  subscription events and API are off.
- An author who adds a second `mercure` block to `drupack.caddy` breaks the hub.
  An unnamed block fails the start. A named block answers every hub request,
  while `mercure_publish()` keeps publishing to Drupack's hub, so updates from
  PHP never reach a browser.

## Solution

Every hub Drupack serves publishes its subscriptions, so a site can build a
presence list from tokens it already signs. A project that wants other hub
settings keeps a `drupack.mercure.caddy` beside `composer.json`. Drupack
imports it inside its own hub block, ahead of the lines it pins. The file adds
directives such as `anonymous`, `debugger` or `write_timeout`. Drupack's
address, cookie name, secrets and history file stay in force. The engine reads
the folder's file, and a packaged site reads the copy its payload carries.

## Out of Scope

- Settings for single hub options under `extra.drupack.mercure` in
  `composer.json`.
- A check that refuses a directive in the hub file, `playground` included.
- An environment variable naming the hub's `mercure.db`.
- The hub on the static port.
- A Drupal helper that mints presence tokens or renders a presence list.
- Mercure's `cors_origins` for a frontend on another origin.
- A larger history, or a separate store for presence events.

## User Stories

### Presence

1. As a module author, I want the hub to publish who subscribes to a topic, so
   that a collaboration screen lists the other editors.
2. As a module author, I want presence events to carry my token's payload, so
   that peers see a username without asking Drupal.

### Hub file

3. As a site author, I want a file of hub directives beside `composer.json`, so
   that pages from Drupal's page cache can subscribe.
4. As a site author, I want Drupack's hub settings to win over my file, so that
   publishing from PHP and drush keeps working.
5. As a site author who packages a site, I want the hub file in the package, so
   that I ship the hub I tested.
6. As a site author with a typo in the hub file, I want the start to name the
   file, so that I find the line.
7. As a site author with a hub file and no Mercure key, I want a refused
   start, so that I see the file is inert.
8. As a site author, I want requests for the hub file refused, so that a
   docroot at the project root never serves it.

## Implementation Decisions

### Presence

- D1: Every hub turns on Mercure's subscription events and subscription API
  through the `subscriptions` directive. The events are private updates, and the
  hub answers the API only to a token whose grant covers the requested path.
  Evidence: FrankenPHP v1.13.4 `caddy/go.mod` pins `dunglas/mercure` v1.1.5.
  That release documents the API in `docs/concepts/active-subscriptions.md`.
  `dispatchSubscriptionUpdate()` in its `subscribe.go` sets `Private: true`.
- D2: Presence events share the hub's history of 1000 updates, and the size
  stays 1000. A tab adds one event when it opens and one when it closes, per
  matcher. Evidence: `dispatchSubscriptionUpdate()` dispatches through the
  transport; `BoltTransport.Dispatch()` in `bolt.go` persists every update.
- D3: Drupack mints no token. A site grants presence by adding the
  subscriptions topic family and a `payload` to the subscriber token it signs.
  Evidence: the token shape in `docs/build-your-site.md`, section "A site that
  pushes live updates".

### Where the hub file lives

- D4: A project's hub directives live in `drupack.mercure.caddy`, beside
  `drupack.caddy`. The engine reads the project folder, a packaged site its
  application directory. Evidence: `execServer()` in `launcher/start.go` looks for `drupack.caddy` in `rules`, and
  `launcher/sitestart.go` passes `application`. `build/app-payload.sh` packs
  the whole application directory, so the file ships with no build change.
- D5: The hub fragment imports the file as its first line inside the `mercure`
  block. `caddyfile.Mercure` gains the file's path, empty for a project without
  one. Mercure's parser keeps the last value of a single-value key and appends
  each `issuer` block. Drupack's `resource_identifier`, `cookie_name`, `issuer`
  and `transport` lines follow the import, so their values win. Evidence:
  `UnmarshalCaddyfile()` in Mercure's `caddy/mercure.go`. On 2026-11-10 the
  runtime's `adapt` of a test Caddyfile kept a later `cookie_name` over an
  earlier one.
- D6: A start that finds a hub file while it serves no hub fails, and its
  message names the file and `extra.drupack.mercure`. `execServer()`, which
  both starts call, holds the check. Evidence: `config.Mercure` is
  nil for a start without the key, per `caddyfile.Config`.

### How a start treats the hub file

- D7: The server word names every project file in an adapter error.
  `php-server` takes the Caddyfile, then the project files the start found, and
  prints one line per file. Evidence: `serveCaddyfile()` and the argument check
  in `runtime/entrypoint.go`.
- D8: The guards refuse `/drupack.mercure.caddy` by name, as they refuse
  `/drupack.caddy`. Evidence: `@protectedName` in
  `launcher/internal/caddyfile/fragments/refusals.caddy`.
- D9: Drupack checks no directive in the hub file. The docs warn that
  `playground` hands every visitor an all-access token. Evidence: ADR 0048
  trusts the project's author with `drupack.caddy`.
- D10: Drupack's hub keeps its other defaults. `anonymous`, `debugger` and
  `write_timeout` change only through the hub file. Evidence:
  `launcher/internal/caddyfile/fragments/mercure.caddy`.

### Docs and records

- D11: The docs name both features.
  - `docs/build-your-site.md` gains presence and the hub file under "A site that
    pushes live updates".
  - Its "A site's own Caddy rules" says a `mercure` block there fails or splits
    the hub, and points to the hub file.
  - `docs/cli.md#mercure-hub` gains one line.
  - `CONTEXT.md` adds the hub file to the "Mercure hub" entry.
- D12: `docs/adr/0052-a-project-tunes-its-mercure-hub.md` records D1 to D10. It
  amends ADR 0034 and ADR 0048, which each gain an "Amended by" line.
- D13: `docs/backlog.md`, "The Mercure hub on the static port", loses the claim
  that a second `mercure` directive shares nothing. Two hubs with one `name`
  and identical `bolt` blocks share a transport. Evidence: the engine test of
  2026-11-10, in which `mercure_publish()` reached a subscriber of the second hub.

### Considered options

- Per-option keys under `extra.drupack.mercure`. Drupack validates a short
  list. The first key costs about eight files, and each later key four to five.
  The hub file costs six and takes every directive.
- Keys and a hub file together. Two ways to set one directive, at the cost of
  both.
- No project control, with fixed values. A page-cached site never subscribes.
- A second hub in `drupack.caddy`. The engine test of 2026-11-10 split
  `mercure_publish()` from the subscribers. The two hubs join only when the
  project hardcodes the cache entry's `mercure.db` path.
- Refusing `playground` on the adapted JSON. About 15 lines of Go. The owner
  chose ADR 0048's trust on 2026-11-10.

## Testing Decisions

A test drives a started server over HTTP and asserts what a browser, drush or
PHP sees. No test reads the rendered Caddyfile except the golden render test.

- The golden render test regenerates `site-everything.caddyfile`, whose config
  sets a hub file. Prior art: `launcher/internal/caddyfile/caddyfile_test.go`.
- `MercureHub` in `tests/conformance/mercure_cases.py` gains three cases:
  - a listener with a presence grant gets `active: true` with the payload when a
    second subscriber connects, and `active: false` when it leaves
  - `GET /.well-known/mercure/subscriptions` with an exact grant lists the
    subscriber
  - a token without the grant is refused by the API

  Its `token()` helper gains topics and a payload. Current callers keep their
  tokens.
- `tests/conformance/engine_cases.py` gains four cases, with prior art in its
  working and broken `drupack.caddy` cases:
  - a hub file with `anonymous` lets a subscriber with no token in
  - a hub file whose `cookie_name` differs still lets the `mercure_access_token`
    cookie in
  - a broken hub file fails the start, and the output names it
  - a hub file in a project without the key fails the start
- `build/variant-fixture.sh` packs a hub file with `debugger`. `ProjectRules` in
  `tests/conformance/project_rules_cases.py` asserts that
  `/.well-known/mercure/debug/` answers 200.

## Further Notes

- The subscription API carries a percent-encoded topic in its path. The
  dot-segment guard in `refusals.caddy` runs before the hub. A topic whose
  decoded segment starts with a dot would meet a 403. The API case uses an
  absolute URL topic. The phase that adds it shows whether the guard reaches
  it.
- The engine test of 2026-11-10 ran `dist/engine/drupack-linux-amd64`, built on
  2026-11-09. Its script stays outside the repo.
