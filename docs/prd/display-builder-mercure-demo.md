# PRD: Display Builder live editing through the Drupack Mercure hub

Date: 2026-10-18

## Problem Statement

Drupack 1.0.0-beta7 serves a Mercure hub, and no Drupal module can use it. The
four modules that speak Mercure mint 0.x tokens, which a protocol 1.0 hub
refuses. Nothing in core or Drupal CMS pushes updates to a browser.

Display Builder's real-time collaboration streams server-sent events from PHP.
Each open builder holds one PHP worker in a loop that checks a tempstore slot
every 2 seconds. A few open builders take a share of FrankenPHP's threads.

A blog post about the hub has no demo a reader can run.

## Solution

Display Builder gains an optional hub path. When a hub is configured, a change
publishes a ping, and each open builder fetches its own islands. No PHP worker
waits per open builder. Without a hub, the PHP loop runs as today.

The Drupacked Demo turns the hub on and carries the change as a patch. Display
Builder's page layout stays off in the demo. The post gives the commands that
turn it on, shows a recording of two browsers, and links the merge request.

## User Stories

1. As a site editor, I want a change made in one browser to show in another browser on the same builder within a second, so that two people can edit one layout.
2. As a site editor, I want my own change not to come back to me as a refresh, so that my builder does not reload what I just did.
3. As a site editor, I want each browser to render the islands for its own account, so that avatars and access checks stay correct.
4. As a site editor, I want changes from drush, a queue worker or an AI agent to reach every open builder, so that edits outside a browser show up too.
5. As a site owner, I want open builders to hold no PHP worker while they wait, so that the server keeps its threads for requests.
6. As a site owner, I want the module to run as today when no hub is configured, so that nothing changes on a site without one.
7. As a site owner, I want to name the hub in `settings.php`, so that any Mercure hub works.
8. As a Drupack user, I want the module to find Drupack's hub with no setting, so that turning the hub on needs one `composer.json` line.
9. As a site owner, I want the collaboration island to keep deciding whether a builder subscribes, so that the switch I know stays the switch.
10. As a subscriber, I want my token to allow reading one builder's topic alone, so that a token cannot read another instance.
11. As a Display Builder maintainer, I want the change covered by tests in the module, so that I can review and keep it.
12. As a reader of the post, I want a demo I install with one command, so that I try the hub without building anything.
13. As a reader, I want the commands that turn on the page layout builder, so that I reach the live editing screen.
14. As a reader, I want a recording of two browsers, so that I see the result before I install anything.
15. As the post's author, I want the demo checked end to end before the post goes out, so that the commands work.

## Implementation Decisions

- D1. A Display Builder change calls `SseState::record()`. With a hub configured, `record()` also publishes an update of type `changed` with empty data. The topic is the absolute URL of the instance's SSE route. Evidence: `SseState::record()` in `src/SseState.php`; route `display_builder.api_sse`.
- D2. The hub settings come from `$settings['display_builder_mercure']`, with the keys `url`, `publisher_secret` and `subscriber_secret`. When that key is unset, they come from `DRUPACK_MERCURE_URL`, `DRUPACK_MERCURE_PUBLISHER_SECRET` and `DRUPACK_MERCURE_SUBSCRIBER_SECRET`. With neither, the module runs its PHP loop. Evidence: Drupack exports the three variables, `application/mercure.php`.
- D3. A new route `GET /api/display-builder/{display_builder_instance}/refresh` runs one pass of the SSE loop for the caller's session. It returns the islands' out-of-band swaps, or an empty body when `shouldRelay()` says no. Access is `view` on the instance, as for the SSE route. Evidence: the loop body in `ApiSseController::sse()`; `SseState::shouldRelay()`.
- D4. With a hub configured, the builder page sets a subscriber cookie that grants `subscribe` on the instance's topic. `sse-connect` points at the hub with `match=<topic>`, and `sse:changed` triggers a GET of the refresh route. Evidence: `ProfileViewBuilder::connectSseStream()`; htmx SSE extension 2.2.3 in `display_builder.libraries.yml`.
- D5. `IslandInterface::needsSse()` still decides whether a builder subscribes. The collaboration island stays off in the default profile. Evidence: `ProfileViewBuilder.php:132`; `config/optional/display_builder.profile.default.yml`.
- D6. Display Builder requires `symfony/mercure` ^0.9 and `web-token/jwt-library`. Tokens are protocol 1.0: `typ: at+jwt`, `iss` and `aud` set to the hub URL, `exp`, and `authorization_details`. Evidence: `WebTokenFactory::create()` in symfony/mercure 0.9.0; Drupack's hub config in `application/mercure.caddy`.
- D7. The change goes to Display Builder as a merge request on 1.0.x. The owner opens the drupal.org issue and its fork.
- D8. The Drupacked Demo sets `extra.drupack.mercure: true`, requires `symfony/mercure` and `web-token/jwt-library`, and applies the change as a patch rebased onto its pinned 1.0.0-beta8. Evidence: `drupacked-demo/composer.json` patches for `drupal/display_builder`.
- D9. The demo recipe leaves Display Builder's page layout off. The post gives the commands that enable `display_builder_page_layout`, turn on the collaboration island and create the default page layout. Evidence: `recipes/drupacked_demo_site/recipe.yml`; route `entity.page_layout.add_default_form`.
- D10. A Playwright script drives two browser sessions on a local demo build on Drupack beta7. It asserts that a change in one shows in the other, and records both into one side-by-side video in `~/Downloads`.
- D11. A Drupack bug the script finds is fixed in Drupack and ships in the next beta, and the demo pins that beta.
- D12. The post is a draft in `~/Downloads`, in the owner's voice. It explains the hub, embeds the video, links the merge request, and gives the install and setup commands.

### Considered options

- Turning on Mercure 0.x compatibility in Drupack's hub. The modules it would enable have no compelling use case.
- The ECA modeler's live testing. One user waits up to 1.5 seconds less.
- Canvas notifications. Largest audience, largest change, slowest review.
- Publishing rendered islands through the hub. Every viewer would get one user's render.
- `mercure_publish()` alone. It is missing in php-cli, so drush, workers and agents could not publish.
- A hand-written HS256 signer. The owner chose `symfony/mercure`.
- Subscribing whenever a hub is set. It changes the module's default behavior.
- Enabling the page layout builder in the demo recipe. The owner chose setup commands in the post.

## Testing Decisions

A test drives the module through HTTP or the browser. It never asserts on a
rendered Caddyfile or a private method.

- Display Builder kernel tests: the refresh route returns swaps for another session and an empty body for the session that recorded the change. The hub settings resolve from `settings.php`, then the environment, then nothing.
- Display Builder unit test: `record()` publishes one `changed` update on the instance topic through a mock hub, and none without a hub.
- The Playwright script of D10 is the end-to-end test. It runs against the local demo build.
- Prior art: `tests/src/Unit/SseStateUnitTest.php` in Display Builder; `tests/conformance/mercure_cases.py` in Drupack.

## Out of Scope

- Mercure 0.x compatibility in Drupack's hub.
- Removing Display Builder's PHP loop.
- Several tabs of one browser session. Today's loop skips them too.
- Publishing the post.

## Further Notes

- The hub's default cookie name for protocol 1.0 carries the `__Secure-` prefix. The demo serves plain http on 127.0.0.1, so D10 checks that browsers keep the cookie there.
- Two browsers need two sessions. The same account in two browsers is enough.
