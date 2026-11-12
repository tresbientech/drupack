# A site serves a Mercure hub when its composer.json asks

## Context

FrankenPHP links the Mercure hub into every runtime. A site that wants live updates in the browser needs the hub mounted without running a second server. A project that develops with the engine needs the same hub as the packed site.

## Decision

### Turning the hub on

- `composer.json` `extra.drupack.mercure`, a boolean, turns the hub on for a Packaged site and for a project the engine serves. It is false when absent.
- `drupack.yml` refuses a `mercure` field and names the `composer.json` one. The build writes the value to `site.json`.
- The hub sits at `/.well-known/mercure`, inside `php_server`'s route block, where `mercure_publish()` finds it.
- `mercure.caddy` is the one fragment that mounts it, for a Packaged site and for the engine.

### Secrets, history and tokens

- Site data holds `mercure_publisher_secret` and `mercure_subscriber_secret`. The engine keeps them in the folder's cache entry. A start creates each missing one. `drush` creates none.
- A start and `drush` export `DRUPACK_MERCURE_URL` and both secrets. A site's `settings.php` maps them to the modules it uses.
- The hub speaks Mercure protocol 1.0. Subscribers and HTTP publishers sign HS256 tokens whose `iss` and `aud` are the hub's address. A request publishes with `mercure_publish()` and no token. Drush and cron publish over HTTP.
- The cookie is named `mercure_access_token`, because an HTTP site cannot keep the default `__Secure-` name.
- The hub keeps its last 1000 updates in `mercure.db`, in Site data or the folder's cache entry. A reconnect with `Last-Event-ID` replays what it missed.
- Every hub turns on `subscriptions`. Subscription events and the subscription API are private. A token reads them through a grant the site signs. Drupack mints no token.
- Subscription events share the 1000-update history.

### The project's hub file

- A project keeps extra hub directives in `drupack.mercure.caddy`, beside `composer.json`. The engine reads the folder's file. A Packaged site reads the copy in its payload.
- `mercure.caddy` imports the file as the first line of the `mercure` block. Mercure keeps the last value of a repeated setting, so Drupack's `resource_identifier`, `cookie_name` and `transport` win. An `issuer` block of the project's joins Drupack's.
- A start that finds the file while it serves no hub fails, and its message names the file and `extra.drupack.mercure`.
- When the Caddyfile fails to adapt, the start prints one line per project file.
- The request guards refuse `/drupack.mercure.caddy` by name.
- Drupack checks no directive in the file. Its author is the project's author, as with `drupack.caddy` in [0014](0014-request-guards-and-caddy-rules.md).
- A change to the file takes a restart.

## Reason

The setting lives in `composer.json` because the engine never reads `drupack.yml`, and a plain Composer project has none. Composer hashes `extra` into the lock, so the setting travels with the project. The engine keeps secrets in the cache entry and adds no file to the project folder. Moving the folder gives it new secrets and an empty history.

Drupack fixes the hub's core directives so `mercure_publish()` keeps reaching the hub it serves. One field per option under `extra.drupack.mercure` would cross the composer reader, `site.json`, the template and the tests for each option. A file takes every directive at once.

A second hub declared in `drupack.caddy` fails. An unnamed block fails the start. A named block answers requests while `mercure_publish()` keeps publishing to Drupack's hub.
