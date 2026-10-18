# Plan: Display Builder live editing through the Drupack Mercure hub

**Spec:** docs/prd/display-builder-mercure-demo.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: A change calls `SseState::record()`, which also publishes an update of type `changed` with empty data when a hub is configured. The topic is the absolute URL of the instance's SSE route.
- **D2**: Hub settings come from `$settings['display_builder_mercure']` (`url`, `publisher_secret`, `subscriber_secret`), else from Drupack's three `DRUPACK_MERCURE_*` variables, else no hub and the PHP loop.
- **D3**: `GET /api/display-builder/{display_builder_instance}/refresh` runs one pass of the SSE loop for the caller's session, with `view` access on the instance.
- **D4**: With a hub, the builder page sets a subscriber cookie for the instance topic, connects to the hub with `match=<topic>`, and fetches the refresh route on `sse:changed`.
- **D5**: `IslandInterface::needsSse()` still decides whether a builder subscribes.
- **D6**: Display Builder requires `symfony/mercure` ^0.9 and `web-token/jwt-library`. Tokens are protocol 1.0, with `iss` and `aud` set to the hub URL.
- **D7**: The change is a merge request on Display Builder 1.0.x. The owner opens the drupal.org issue and fork.
- **D8**: The Drupacked Demo sets the hub key, requires both libraries, and applies the change as a patch on 1.0.0-beta8.
- **D9**: The demo leaves the page layout builder off. The post gives the setup commands.
- **D10**: A Playwright script on a local demo build asserts the cross-browser update and records a side-by-side video into `~/Downloads`.
- **D11**: A Drupack bug the script finds ships in the next Drupack beta, pinned by the demo.
- **D12**: The post is a draft in `~/Downloads`, in the owner's voice.
- **Routes**: `/api/display-builder/{display_builder_instance}/refresh` (GET, new); `/.well-known/mercure` (the hub, served by Drupack).
- **Work locations**: Display Builder in a 1.0.x clone outside this repo; the demo in `~/DEV/DRUPAL/drupacked-demo` on a branch; scripts, builds and the video outside every repo.

---

## Phase 1: Hub publish and refresh route

**User stories**: 2, 3, 4, 6, 7, 8, 11
**Decisions**: D1, D2, D3, D6

### What to build

Display Builder's server side. With a hub configured, every recorded change
publishes a `changed` ping on the instance topic. The refresh route answers a
second session with that session's island swaps, and the session that made
the change with an empty body. Without a hub, the module behaves as before.

### Acceptance criteria

- [ ] The module's kernel tests pass, among them new cases: the refresh route returns swaps for another session and an empty body for the recording session. Command: `vendor/bin/phpunit` on the module's `tests/src/Kernel` in a Drupal 11.4 test site.
- [ ] New kernel cases resolve the hub from `settings.php`, then the `DRUPACK_MERCURE_*` variables, then none.
- [ ] A new unit case asserts that `record()` publishes one `changed` update on the instance topic through a mock hub, and none without a hub.
- [ ] Against a Drupack beta7 engine start with the hub key, a `curl` subscriber with a subscriber token for the topic receives `event: changed` after `drush php:eval` records a change.
- [ ] `vendor/bin/phpstan` and `vendor/bin/phpcs` pass on the module with its own configuration.

---

## Phase 2: Browser subscribes through the hub

**User stories**: 1, 5, 9, 10
**Decisions**: D4, D5, D7

### What to build

The builder page's side. With a hub configured and the collaboration island
on, the page sets a subscriber cookie for its instance topic, opens the stream
on the hub, and refetches its islands on each ping. With the island off, no
stream opens. With no hub, the PHP stream opens as before.

### Acceptance criteria

- [ ] A Playwright script with two browser contexts, logged in on a Drupal 11.4 site served by the Drupack beta7 engine with the hub key, sees a block moved in context A appear in context B within 3 seconds.
- [ ] During that run, the Drupack hub answers the stream, and no request to `/api/display-builder/{id}/sse` is made. Checked from the browser's request log.
- [ ] The subscriber cookie's token grants `subscribe` on the instance topic alone. A curl subscription with it on another instance's topic gets no update.
- [ ] With the island off, the page opens no stream. With no hub, the page connects to the `/sse` route. Both checked by the same script.
- [ ] The Phase 1 tests still pass. The merge request branch holds Phases 1 and 2 as commits on 1.0.x.

---

## Phase 3: Demo integration and recording

**User stories**: 12, 13, 15
**Decisions**: D8, D9, D10, D11

### What to build

The Drupacked Demo, built locally on Drupack beta7 with the hub key, both
libraries and the change as a patch on 1.0.0-beta8. The post's setup commands
turn on the page layout builder and the collaboration island. The Playwright
script runs the two-browser check on that build and records the video. A
Drupack bug it finds gets fixed in Drupack, released through the upstream
dispatch, and pinned in the demo.

### Acceptance criteria

- [ ] `composer install` in the demo branch applies every patch, the new one included, with no failure.
- [ ] The post's setup commands, run in order on the fresh local build, exit 0 and leave the page layout builder reachable at `/admin/structure/page-layout/{page_layout}/builder`.
- [ ] The Playwright script passes on that build and writes the side-by-side video to `~/Downloads`.
- [ ] The demo's own test suite passes on the branch, through the demo's CI on a pushed branch, after the owner approves the push.

---

## Phase 4: Post draft

**User stories**: 14
**Decisions**: D12

### What to build

A short blog post draft in the owner's voice: what the hub is, why Display
Builder's live editing is the first use, the video, the merge request link,
and the demo install plus setup commands.

### Acceptance criteria

- [ ] The draft is in `~/Downloads`, and every command in it is a command Phase 3 ran.
- [ ] The writing bans find nothing in the draft: `writing_bans.check()` from `~/.claude/hooks/writing_bans.py` returns an empty list on the file's text.
