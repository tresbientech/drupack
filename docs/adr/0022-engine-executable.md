# An engine executable serves a project folder as is

Accepted on 2026-09-26. Amended on 2026-10-21 by
[ADR 0044](0044-one-reader-takes-a-projects-composer-settings.md): the launcher
reads the folder's `composer.json` settings, by the rules a build applies.
Amended on 2026-10-25 by
[ADR 0047](0047-request-guards-match-htaccess.md): the request guards reproduce
Drupal's `.htaccess` family.

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
  names Drush itself, since it adds nothing to it.
- `drupack dr` runs Drupal core's own command line, `vendor/bin/dr`, which
  core ships from Drupal 11.4 on.
- A site executable's Drush command is renamed from `dr` to `drush`, with no
  alias. `dr` is core's name, and Drupack's command runs Drush. A `php` on PATH
  sends Drush's child processes to the same PHP.
- `drupack php` takes two forms, `SCRIPT [ARGUMENTS]` and `-r CODE`, and
  passes each to the runtime's `php-cli` unchanged. It refuses any other
  option by name, and `-r CODE` followed by an argument, before PHP starts.
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
- A script calling `SITE dr` breaks, and gets the embedded server's unknown
  command error.
- The `latest` download links of the demo change name, and its cache moves
  to a `mercury-demo` directory.
- The runtime changes to no directory of its own for the engine executable,
  so relative paths in a start, `drush` and `php` resolve against the reader's.
- FrankenPHP's `php-cli` takes a script or `-r` code, and no PHP option. It
  passes `-r` code no `$argv`. The runtime's entry point checks the form, so
  the launcher and both `php` aliases share one rule.
- `serve.php` loads no Composer autoloader, since the engine carries no
  `vendor/`. It resolves paths with `realpath` and `dirname`, in place of the
  `Path` class ADR 0010 names.

## Amendment, 2026-09-30

A folder mode start holds a Serving lease on its cache entry, keyed by the
folder's path, beside that entry's `stop.json` and `server.log`. The lease
guards `drupack stop` and a second start on the same folder, and the folder
gets no new file. It runs the site's readiness runner without cron. ADR 0028
records the detached start and the stop channel.

## Amendment, 2026-10-01

The demo executable becomes `drupacked-demo`, and its cache moves to a
`drupacked-demo` directory. Its release assets and `latest` links change name
again. Nothing reads the `mercury-demo` caches of releases 0.5.0 to 0.8.0.

## Amendment, 2026-10-16

A folder mode start listens on `127.0.0.1:3787` by default, in place of
`127.0.0.1:8888`. Jupyter and MAMP's Apache both default to 8888, so a start
often found the port taken. 3787 spells DRUP on a phone keypad, as the demo's
7225 spells PACK in ADR 0004. IANA lists 3787 for Fintrx, a 2003 registration
that no known software uses.

A project started with no `--listen` answers on the new port after an upgrade.
