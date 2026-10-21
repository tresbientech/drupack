# One reader takes a project's composer.json settings

Accepted on 2026-10-21. Amends [ADR 0022](0022-engine-executable.md) and
[ADR 0030](0030-the-launcher-routes-the-readers-words.md).

## Context

A project's `composer.json` gives Drupack its web root, `extra.drupack.mercure`
and `extra.drupack.subdomain`. `drupack-build` read them in Go, and every
engine start read them again in `engine/serve.php`. The two readers drifted:

- PHP served a web root of `/var/www/html` from inside the project, and took
  `C:/web` as a folder name. Go refused both.
- PHP accepted the subdomain `demo\n`. Go refused it.
- A `composer.json` that is not JSON gave PHP the `web` docroot in one function
  and a refusal in another.

A shared table of cases covered `extra.drupack` and missed each of these.

## Decision

- `siteconfig.ReadComposer` is the one reader. It takes a mode:
  - `Packed`, for a build. It refuses a missing `composer.json`, a missing web
    root, and a web root of `.`, since a packed site's `settings.php` loads the
    engine's support files from the docroot's parent.
  - `Folder`, for the engine. It takes a missing file or web root as `web`, and
    `.` as the project itself.
- Every other rule holds in both modes. A refusal names the file, the field,
  its rule and the value.
- The engine's launcher answers the internal word `composer-settings DIR`
  before it prepares the cache, as it answers `lay-app`. It prints the settings
  as one JSON object, or the refusal with exit 1.
- `engine/serve.php` reads the settings through that word alone. It keeps the
  check that the docroot holds Drupal core.

## Consequences

- An engine start and a `drupack drush` in a folder whose last start served a
  Mercure hub each run one more short process.
- The engine refuses a web root that is absolute or names a drive, as a build
  does.
- A `composer.json` rule changes in one Go function and one table,
  `launcher/internal/siteconfig/testdata/composer.json`.

## Considered options

- Two readers kept in step by a wider table of cases. A table catches the
  inputs it lists, and the drifts above passed the existing one.
- The launcher finds the project folder and passes the settings in the
  environment. Go would parse the start's arguments and the `drush` walk a
  second time, against [ADR 0014](0014-the-parser-owns-the-command-line.md).
