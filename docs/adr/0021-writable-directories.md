# A site with writable directories gets its own Application root

Accepted on 2026-09-25.

## Context

WordPal converts a WordPress theme with a Drush command. The command writes a
Drupal theme under the docroot's `themes/custom` and a recipe under `recipes/`,
beside the docroot. A converter executable runs that command through `dr` and
serves the result.

Drupal finds a theme only under its root. The root comes from the kernel file's
own location unless `index.php` passes one, and `DRUPAL_ROOT` resolves
symlinks. ADR 0002 gives every site of a release one shared Application root
that none writes to.

## Decision

- `drupack.yml` gains `writable`, a list of directories relative to the
  project root that the site writes at runtime.
- A site with a non-empty list gets its own Application root, `app` in Site
  data, extracted from the executable on first start. `DRUPACK_RUNTIME_APP_DIR`
  and the Caddy docroot point there, so Drupal and Caddy read one root and a
  written theme serves with no new route.
- A start by a newer release extracts the new application beside the old one,
  copies every entry of a writable directory that the new release does not
  ship, then swaps. An entry the release ships takes the release's version.
- `clean` leaves Site data alone, as before.
- A site without the field keeps the shared root of ADR 0002.

## Considered options

- A link farm in Site data: links to the shared release, real directories for
  the writable ones. `DRUPAL_ROOT` and `__DIR__` resolve through the links to
  the shared copy, so `index.php` and Drush's kernel would both have to pass
  the root. Windows needs junctions or a privilege for the links.
- Writing into the shared root. A second Site data of the same release sees
  the writes, `clean` deletes them, and a new release loses them.
- One boolean instead of the list. An upgrade could not tell the site's writes
  from the release's files.

## Consequences

- A converter site pays ADR 0002's extraction once per Site data: about
  200 MB, and 20 s on Windows.
- Two Site data directories of such a site share no application files.
- `deployment_identifier` stays keyed by the application path, which is now
  stable across starts of one Site data and changes on upgrade.
- Node on PATH is the converter site's requirement. `dr` passes the
  environment through and checks nothing.
