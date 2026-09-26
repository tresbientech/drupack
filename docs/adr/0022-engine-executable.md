# An engine executable serves a project folder as is

Accepted on 2026-09-26.

## Context

Every Drupack executable today carries one site's codebase. A developer with a
Drupal project on disk, such as a ddev checkout, has to build that project
before Drupack can serve it. The build also replaces the project's
`sites/default/settings.php` with the engine's, which loads settings from Site
data.

## Decision

- Each engine release publishes a generic `drupack` executable per platform.
  It carries the runtimes and the engine's files, and no application.
- `drupack serve [DIR]` serves the project folder DIR, the working directory
  by default. The docroot comes from `composer.json`'s scaffold web root, or
  `web` when unset.
- The folder's own settings own the database, the files paths and the hash
  salt. Drupack writes nothing into the folder.
- `drupack dr` runs the folder's Drush on the runtime's PHP. A `php` on PATH
  sends Drush's child processes to the same PHP.
- `drupack php` runs the runtime's PHP.
- A start prints a one-time login link, as a packaged site's does.
- The folder mode has its own PHP entry script. It shares process and path
  helpers with `launch.php`.

## Considered options

- A per-site build without the codebase. Each site would still need a build.
- Drupack's Site data settings loaded through a replaced front controller and
  Drush bootstrap. Both wrap Drupal's kernel start, which changes across core
  versions.
- An include line written into the folder's `settings.php`. It changes the
  folder the reader asked to serve as is.
- A folder mode inside `launch.php`. Most of its Site data lifecycle would
  gain a folder condition.

## Consequences

- A folder mode start has no Site data, Serving lease, seeding or cron runner.
- A folder whose `composer.lock` requires an extension the runtime lacks is
  refused at start, with the missing names.
- Reaching a ddev database from the host means the project's own settings
  name its published port on `127.0.0.1`.
- Drupal writes public files into the folder, where its settings put them.
