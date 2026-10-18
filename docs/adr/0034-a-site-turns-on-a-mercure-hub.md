# A site turns on a Mercure hub

Accepted on 2026-10-17.

## Context

FrankenPHP links the Mercure hub into every runtime, and no Caddyfile mounted
it. A site that wanted live updates in the browser had to run a hub beside the
executable.

## Decision

- `mercure: true` in `drupack.yml` mounts the hub at `/.well-known/mercure` on
  every start of that site. The key defaults to false.
- `application/Caddyfile` imports `mercure.caddy` or `none.caddy`, which the
  start names through `DRUPACK_RUNTIME_MERCURE_SNIPPET`. The hub sits in
  `php_server`'s route block, where `mercure_publish()` finds it.
- Site data holds `mercure_publisher_secret` and `mercure_subscriber_secret`.
  A start creates each missing one. `drush` creates none.
- Start and `drush` export `DRUPACK_MERCURE_URL` and both secrets. A site's
  settings file maps them to the modules it uses.
- The hub speaks Mercure protocol 1.0. Subscribers and HTTP publishers sign
  HS256 access tokens whose `iss` and `aud` are `DRUPACK_MERCURE_URL`.
  Anonymous subscribers, CORS origins, the subscriptions API and the UI stay
  off.
- The hub keeps its last 1000 updates in `mercure.db` in Site data, so a
  reconnect with `Last-Event-ID` replays what it missed.
- The Engine executable serves no hub.

## Consequences

- A request publishes with `mercure_publish()` and no token. Drush and cron
  publish over HTTP, since `php-cli` has no `mercure_publish()`.
- The variant fixture, which replaced the Node fixture, sets the key and
  carries the `MercureHub` cases.
