# composer.json turns on the Mercure hub

Accepted on 2026-10-18. Amends [ADR 0034](0034-a-site-turns-on-a-mercure-hub.md).

## Context

ADR 0034 put the hub's key in `drupack.yml` and kept the hub off the Engine
executable. The engine never reads `drupack.yml`, and a plain Composer project
has none. A site author who develops with the engine had a hub only once the
site was packed.

## Decision

- `composer.json` `extra.drupack.mercure`, a boolean, turns the hub on for a
  Packaged site and for a project the engine serves. It is false when absent.
- `drupack.yml` drops `mercure`. A build refuses the key and names the new one.
  The build still writes the value into `site.json`.
- The Engine executable mounts the same `mercure.caddy`, linked into `engine/`.
- The engine keeps both secrets, `mercure.db` and the hub's address in the
  folder's cache entry. A start creates each missing secret.
- The engine's `drush` exports the three variables once a start created them,
  with the address that start recorded.
- `mercure.caddy` names its history file through `DRUPACK_RUNTIME_MERCURE_DB`.

## Consequences

- The project folder gains no file. Moving it gives it new secrets and an empty
  history.
- A `drupack.yml` that sets `mercure` stops its build until the key moves.
- Composer hashes `extra` into the lock, so `composer update --lock` follows
  the change.
