# One cache holds each release's runtime and application, extracted once

## Context

A release carries its runtime and application files compressed. Extracting them for every site, or on every process start, costs minutes on Windows and fills the disk. Several sites and releases share one machine.

## Decision

### Layout

- The cache root is `DRUPACK_CACHE_DIR` when set. Otherwise it is `<user cache>/<name>/runtime`. On Unix a second candidate is `<tmp>/<name>-<uid>/runtime`.
- A runtime entry is named from the version and 12 hex digits of the payload digest. Two builds with one version string keep separate entries.
- The root also holds `app/` (applications), `node/` (Node releases) and `folders/` (the engine's per-folder state).
- FrankenPHP is built without its embedded `app.tar`, so it extracts nothing. The launcher extracts the application once per release into `app/`, and every Site data of that release serves from it.
- The application directory is read-only. Nothing writes into it, and a site's public files live in Site data under the root-relative address `sites/default/files`.
- `deployment_identifier` hashes the application path, so each release gets its own Drupal cache key.

### Stores

- One store type in `launcher/internal/cache` owns each kind. A kind sets its directory, its readiness test and its retention rule.
- A fill writes a staging directory, writes the completion marker last, then moves the result into place. A directory without its marker is never used.
- A warm entry is reused after a size check. A start never runs another release's runtime.
- A start holds a usage marker inside the entry it runs. A removal moves the entry to a staging name and tests the marker again before it deletes.
- A lock serialises staging across processes. A wait over 5 seconds says why, and a wait over 10 minutes fails.

### Retention

- A runtime stays while it is the active one. Activation removes the other versions' runtimes that no running site holds.
- An application stays for 30 days after the last start that ran it. Each start writes `.used` in the entry and sweeps the others.
- A Node release and a folder entry stay until `clean`.
- `drupack clean` removes every runtime, application, Node release and folder entry. `--dry-run` lists them instead.
- `clean` keeps an entry a running site uses: the usage marker for the first three kinds, the Serving lease for a folder entry.
- After `clean`, the next start unpacks its runtime again, and a stopped folder's Mercure hub state is gone.

### Windows root

- PHP resolves its `PHPRC`-derived path through the ANSI code page, so the Windows cache root must be ASCII.
- The root is tried in order: the named or default root, its 8.3 short name, then the temporary directory and its short name.
- A root with no ASCII form stops the start with a message naming `DRUPACK_CACHE_DIR`.

### Interrupted installs

- A first start on MySQL or PostgreSQL writes `install-started` in Site data after it finds the database empty, before `site:install` writes to it.
- A resumed install with that record never adopts a site. A database holding tables stops the start with a message naming the database and the Site data directory. An empty one installs.
- The resume refuses when `settings.php` is gone, because the command line could then name any database.
- Nothing drops a table. A person empties the database once.

## Reason

One extraction per release makes a new site start in seconds. FrankenPHP's own extraction runs in `init` on every process start and leaves its directory behind on a killed run. A shared, read-only application removes the per-site copy.

A 30-day window keeps both releases warm on a machine that alternates between them.

An interrupted `site:install` can leave a database that boots as a site while a recipe stopped halfway. Adopting it serves an incomplete site. Dropping its tables is unsafe. The record names an address, and a tunnel, an alias or a restored dump can put another database behind it.
