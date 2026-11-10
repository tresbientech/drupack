# A project tunes its Mercure hub

Accepted on 2026-11-10. Amends [ADR 0034](0034-a-site-turns-on-a-mercure-hub.md)
and [ADR 0048](0048-a-project-adds-its-own-caddy-rules.md).

## Context

Drupack fixed every directive of the hub in one fragment. A page from Drupal's
page cache carries no token, so it could not subscribe. A collaboration screen
could not list who else had a page open. A `mercure` block in `drupack.caddy`
gave no way out. An unnamed block fails the start. A named block answers the
hub's requests while `mercure_publish()` keeps publishing to Drupack's hub, as
an engine test showed on 2026-11-10.

## Decision

- Every hub turns on `subscriptions`: Mercure's subscription events and
  subscription API. Both are private. A token reads them through a grant the
  site signs, and Drupack mints no token.
- Subscription events share the hub's history, which stays at 1000 updates.
- A project keeps its hub directives in `drupack.mercure.caddy`, beside
  `drupack.caddy`. The engine reads the folder's file. A packaged site reads the
  copy in its payload.
- `mercure.caddy` imports the file as the first line of the `mercure` block.
  Mercure keeps the last value of a repeated setting, so Drupack's
  `resource_identifier`, `cookie_name` and `transport` win. An `issuer` block of
  the project's joins Drupack's.
- A start that finds the file while it serves no hub fails. Its message names
  the file and `extra.drupack.mercure`.
- The server word takes every project file the start found. On an adapter error
  it prints one line per file.
- The guards refuse `/drupack.mercure.caddy` by name.
- Drupack checks no directive in the file. Only the project's author writes it,
  as ADR 0048 holds for `drupack.caddy`.

## Considered options

- Keys under `extra.drupack.mercure`, one per option. Each key crosses the
  composer reader, `site.json`, the template and the tests. The file takes every
  directive at once.
- A second hub in `drupack.caddy` that shares Drupack's transport. Two hubs
  share one only under the same `name` and an identical `bolt` block, so the
  project would hardcode the cache entry's `mercure.db` path.
- Refusing `playground` on the adapted configuration. The owner chose ADR 0048's
  trust on 2026-11-10.

## Consequences

- A tab adds two history entries per topic it matches, one when it opens and
  one when it closes.
- A hub file with `playground` hands every visitor an all-access token. The
  docs say so.
- A site cannot serve a file named `drupack.mercure.caddy` from its docroot.
- A change to the hub file takes a restart.
