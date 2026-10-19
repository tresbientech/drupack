# composer.json names the site's subdomain

Accepted on 2026-10-19. Amends [ADR 0037](0037-a-loopback-site-answers-on-its-drupack-site-name.md).

## Context

ADR 0037 takes a Packaged site's label from `name` and an engine start's from
the folder name. The Drupacked Demo answers `drupacked-demo.drupack.site`, and
the owner wants `demo.drupack.site`. `name` also names the executable, the
release assets, the updater's match and the data directory, so renaming the
site costs every reader an update path.

## Decision

- `composer.json` `extra.drupack.subdomain`, a string, sets the label for a
  Packaged site and for a project the engine serves. Without it the label is
  ADR 0037's.
- A subdomain is one DNS label: `a` to `z`, `0` to `9` and dashes, at most 63
  characters, no dash at either end. A build refuses another value, and so
  does an engine start.
- `drupack.yml` refuses a `subdomain` key and names the `composer.json` one.
  The build writes the value into `site.json`.
- The Drupacked Demo sets `"subdomain": "demo"`.

## Consequences

- The key sits beside `extra.drupack.mercure`, so a project carries the same
  name in development and once packed.
- Two sites with one subdomain on one machine share cookies again.
- Composer hashes `extra` into the lock, so `composer update --lock` follows
  the change.
