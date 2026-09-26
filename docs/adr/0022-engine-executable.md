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
- The Mercury Demo executable, which held the name `drupack`, becomes
  `mercury-demo`. The engine executable takes the project's name.
- `drupack [DIR]` serves the project folder DIR, the working directory by
  default, as a site's executable serves with no command. The docroot comes
  from `composer.json`'s scaffold web root, or `web` when unset.
- A start refuses a docroot without Drupal core. The Caddyfile carries
  Drupal's request guards and front controller.
- The folder's own settings own the database, the files paths and the hash
  salt. Drupack writes nothing into the folder.
- `drupack drush` runs the folder's Drush on the runtime's PHP. The word
  names Drush itself, since it adds nothing to it. `dr` keeps its one meaning,
  a site executable's command that selects Site data before a Drush command. A `php` on PATH
  sends Drush's child processes to the same PHP.
- `drupack php` runs a script, or `-r` code, on the runtime's PHP.
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
- `drupack-serve` for the engine executable, which keeps the demo's download
  links. The project's name then belongs to its demo rather than its tool.

## Consequences

- A folder mode start has no Site data, Serving lease, seeding or cron runner.
- A folder whose `composer.lock` requires an extension the runtime lacks is
  refused at start, with the missing names.
- Reaching a ddev database from the host means the project's own settings
  name its published port on `127.0.0.1`.
- Drupal writes public files into the folder, where its settings put them.
- The `latest` download links of the demo change name, and its cache moves
  to a `mercury-demo` directory.
- The runtime changes to no directory of its own for the engine executable,
  so relative paths in a start, `drush` and `php` resolve against the reader's.
- FrankenPHP's `php-cli` takes a script or `-r` code, and no PHP option such
  as `-v` or `-d`.
- `serve.php` loads no Composer autoloader, since the engine carries no
  `vendor/`. It resolves paths with `realpath` and `dirname`, in place of the
  `Path` class ADR 0010 names.
