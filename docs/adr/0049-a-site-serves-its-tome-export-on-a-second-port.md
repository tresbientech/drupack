# A site serves its Tome export on a second port

Accepted on 2026-11-06. Amends [ADR 0004](0004-listener-record-and-default-port.md),
[ADR 0022](0022-engine-executable.md), [ADR 0040](0040-a-start-plans-its-listener.md)
and [ADR 0044](0044-one-reader-takes-a-projects-composer-settings.md).

## Context

A 2022 post, [Can Drupal scale down?](https://read.theodoreb.net/2022/can-drupal-scale-down.html),
describes one executable that creates a site, exports it to HTML and runs only
to edit. Drupack has the executable, the first start that creates Site data and
the detached start. It has no export directory, and nothing serves one.

Tome's `tome_static` renders every page through Drupal's kernel and writes the
files into `tome_static_directory`, `../html` beside the docroot by default. A
second run rewrites only the pages whose cache tags changed. `drush tome:static`
splits the paths across child Drush processes. It rebuilds their command line
from `$_SERVER['argv']`. Under Drupack's `php-cli` that line starts with
`drush.php`, whose shebang asks for a `php` on PATH, so the children fail or
run another PHP. Tome's form exports in one process.

Some site owners want a purely static site. Others want a form or an API on it.
Tome ships no recipe, and drupal.org has none.

## Decision

### The toggle

- `composer.json` `extra.drupack.static`, an object, turns the static listener
  on for a Packaged site and for a project the engine serves. `dynamic`, an
  optional list of path patterns, names the paths the listener hands to Drupal.
  Without `dynamic` the listener runs no PHP.
- The build writes the value into `site.json`, as it does `mercure`. A build and
  an engine start refuse the key when `composer.lock` lacks `drupal/tome`.

### The static listener

- The listener binds the address of `--listen` on the site's port plus one:
  7226 for a site on 7225, 3788 for the engine. `--static-listen IP:PORT`
  overrides it. The Listener record keeps it for `export`, and a start never
  reads it back, as with `--listen`.
- It takes the site's TLS source on its own port.
- A `dynamic` path reaches `php_server` on the Drupal docroot, any method,
  inside the same Caddy process. Every other path is a file from the export,
  else the export's 404 page. Tome may still export a page under a dynamic
  prefix; the static port never serves that file.
- On a Packaged site the export lives in `static` in Site data, and the
  generated `settings.php` sets `tome_static_directory` to it. On the engine the
  listener serves the project's own `tome_static_directory`, or `html` beside
  the docroot, Tome's default, when the project sets none.

### The export

- A new word, `export`, runs `drush tome:static --yes --uri URL`. The URL is the
  static URL, the static listener's scheme, host and port; a `--uri` after
  `export` replaces it. Every other
  word after `export` reaches Drush unchanged.
- `drush` and `export` launch Drush through Drupack's own entry, `drush`
  beside the application, with the runtime's `php` shim on PATH, and the `php`
  word answers on both executables. Tome's child processes then start from the
  command line it rebuilds, which names that entry.
- The export runs when the reader asks, through the word or Tome's form. The
  server runs none of its own.

### The demo

- Drupacked Demo requires `drupal/tome`, ships a recipe that enables
  `tome_static` and core content search, and sets `static` with one `dynamic`
  path, `/search*`. The engine cases run on the same checkout as a project
  folder.

## Considered options

- A PHP script driving `tome_static.generator` in one process, the form's path.
  The owner keeps the export on Drush's command.
- Rewriting `$_SERVER['argv'][0]` for Tome's finder. Tome joins argv into one
  shell string, so an executable path with a space breaks.
- A method rule: every POST on the static port reaches Drupal. Forms in exported
  pages would post to their own URL, and the backend's login, password and
  register forms would answer on the static port.
- Rules for the static port in the project's `drupack.caddy`. The engine would
  then serve a second block it cannot see.
- Mapping `dynamic` to `tome_static_path_exclude`. Tome matches that list by
  exact path, and the patterns are prefixes.
- An export on a timer in the watch runner, beside cron. It boots Drupal on a
  period with nothing to do most of the time.
- A third Site repository as a static template. The demo's lock already carries
  every conformance case.

## Consequences

- The demo's lock gains `drupal/tome` and the five projects it requires: token,
  ctools, pathauto, redirect and book.
- On Windows Tome's children start through the `drush.cmd` twin of the entry,
  which runs `php` from PATH on the entry. Only a tag run tests it.
- Both Caddyfiles gain a `static-site.caddy` snippet with a `none.caddy`
  default, named through `DRUPACK_RUNTIME_STATIC_SNIPPET` as the Mercure snippet
  is.
- `siteconfig.go`, the listener plan in `process.php`, `launch.php`,
  `serve.php`, `application/settings.php`, the parser's word table,
  `docs/cli.md` and `docs/build-your-site.md` change. A `static_site_cases.py`
  module and new engine cases cover both modes.
- An exported page's absolute URLs name the static listener. A copy published
  elsewhere needs `export --uri` with its address.
- A change to a page reaches the export at the next `export`. An export that
  follows cache-tag invalidation, and the Mercure hub on the static port, are
  open in `docs/backlog.md`.
