# Drupack packages an existing site

Accepted on 2026-09-24.

## Context

Drupack builds a site from a recipe. The first existing site to
package, a client Site Studio site, breaks five assumptions of that model:

- Its docroot is `docroot/`. The Caddyfile, `launch.php` and `build/seed.sh`
  hardcode `web/`.
- It has no recipe. A Site Studio site installs from its config sync
  directory, and its asset build needs an API key and the network.
- Its content lives in a MySQL database that DDEV runs. The executable serves
  the code and reads that database.
- Its pages need the Site Studio templates and styles that DDEV compiled into
  `docroot/sites/default/files`, which must match the database.
- Its builds run on one developer's machine. No CI file records the targets.

The adoption path already exists. A first start on a database that holds a site
keeps it, enables nothing and keeps its administrator account.

## Decision

- The parser reads the docroot from composer.json's
  `extra.drupal-scaffold.locations.web-root` and writes it into `site.json`. A
  project without the key fails the build: the scaffold then writes to the
  project root, which no default can guess. The Caddyfile, `launch.php` and the seed step read it there.
- `recipe` is optional. Without one, the build installs no seed. A first start
  then needs `--database mysql` or `pgsql`, and a SQLite start refuses in a
  sentence naming both.
- An optional `settings` field names a PHP file in the site. The generated
  `settings.php` requires it last.
- A `--files-dir` launch option moves the public files directory out of Site
  data. Caddy, the settings and `DRUPACK_RUNTIME_*` take the path from
  `launch.php`.
- `platforms` and `libc` in `drupack.yml` give the site's default targets, with
  the names and values of `--platform` and `--libc`. The parser holds the
  engine default, `linux-amd64` and `both`. Flags and CI inputs override the
  file, and `build.yml`'s inputs lose their own defaults.
- For a site without a recipe, the conformance cases that need a seed report as
  skipped by name, as Docker-only cases do.

## Considered options

- A `docroot` field in `drupack.yml`. It restates composer.json, and the two
  can drift.
- Seeding from config sync with `site:install --existing-config`. SQLite first
  starts would work, but Site Studio's install needs its key and the network.
- Loading the site's own `settings.php`. It runs the host's includes,
  `$databases = []` and each developer's `settings.local.php`.
- Copying DDEV's files into Site data. The copy goes stale on the next Site
  Studio rebuild, and pages then render against templates the database no
  longer matches.
- Targets on the command line only. A local-only site would keep them in shell
  history, and two commands would repeat the same flags.

## Consequences

- `drupack.yml` gains `settings`, `platforms` and `libc`, and `recipe` becomes
  optional. The contract grows without a breaking change.
- An executable without a recipe runs only against a database that already
  holds its site.
- An executable and DDEV share one database as peers. Both run cron, and a
  cache clear on one side empties the other's caches.
- A site's extra PHP extensions are decided in
  `docs/adr/0020-site-extensions.md`.

## Amendment, 2026-09-24

The files directory reaches the public stream wrapper, the Caddyfile and a Twig
loader for templates stored as public files, through
`DRUPACK_RUNTIME_FILES_DIR`. The generated settings keep `file_public_path` at
the address `sites/default/files`. The Listener record keeps the directory, so
later starts and `dr` need no option, as the amended
`docs/adr/0004-listener-record-and-default-port.md` records.
