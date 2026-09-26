# Drupack command line

Every option a site's executable accepts, what it sets, and when it takes
effect, then the commands of the engine executable, `drupack`. The parser in
`application/launch.php` is the contract the options record, and the usage
in `engine/serve.php` the contract the engine commands record. A test in
`application/tests/launch_test.php` fails when the two disagree.

## Synopsis

```
SITE [OPTIONS]
SITE dr [OPTIONS] DRUSH_COMMAND
SITE clean [--dry-run]
SITE --help
SITE --version
```

`SITE` is the site's executable, `mercury-demo` for the Mercury Demo.

An option takes `--name value` or `--name=value`. `--help` prints the option
set. `--version` prints the release. A rejected value prints its reason on
standard error and exits 1.

## Options

| Option | Value | Default | Environment | Applies |
|---|---|---|---|---|
| `--data-dir` | PATH | `./data` | `DRUPACK_DATA_DIR` | every start, `dr` |
| `--listen` | IP:PORT | `127.0.0.1` on the site's port, `7225` for the Mercury Demo | none | every start |
| `--host` | HOST | `localhost` | none | every start |
| `--files-dir` | PATH | `files` in Site data | none | every start, `dr` |
| `--database` | `sqlite`, `mysql`, `pgsql` | `sqlite` | `DRUPACK_DATABASE` | first start |
| `--db-host` | HOST | none | `DRUPACK_DB_HOST` | first start |
| `--db-port` | PORT | `3306` or `5432` | `DRUPACK_DB_PORT` | first start |
| `--db-name` | NAME | none | `DRUPACK_DB_NAME` | first start |
| `--db-user` | NAME | none | `DRUPACK_DB_USER` | first start |
| `--db-password` | PASSWORD | none | `DRUPACK_DB_PASSWORD` | first start |
| `--admin-user` | NAME | `admin` | `DRUPACK_ADMIN_USER` | first start |
| `--admin-password` | PASSWORD | generated | `DRUPACK_ADMIN_PASSWORD` | first start |
| `--site-name` | NAME | the site's name, `Drupal Mercury Demo` for the Mercury Demo | `DRUPACK_SITE_NAME` | first start |
| `--no-browser` | none | off | none | every start |

## Site data

`--data-dir` selects the Site data directory. A relative path resolves against
the directory you started Drupack in, never against the unpacked application.
`DRUPACK_DATA_DIR` sets the default.

The value must not contain a double quote. The log path reaches the Caddyfile
as raw text, before Caddy tokenizes it.

A site whose `drupack.yml` lists `writable` directories keeps its own
application in `app` in Site data. A start lays it there once per release.
`dr` refuses an `app` another release laid, until a start lays this one.
`clean` never removes it.

## Public files

`--files-dir` names the directory that holds the site's public files. The site
addresses them as `/sites/default/files`. By default they live in `files` in
Site data.

A relative path resolves against the directory you started Drupack in. The
value must not contain a double quote, for the same reason as `--data-dir`.

The Listener record keeps the directory a start names. Later starts and `dr`
use it without the option, and a new `--files-dir` replaces it. A start copies
the bundled translations into its `translations` directory, keeping any file
already there.

## The listener

`--listen` takes IP:PORT. An IPv6 address needs brackets. The port must fall
between 1 and 65535. The address must parse as an IP address, so a hostname is
rejected.

`--host` names the permitted request host, as a hostname or an IP address. It
becomes an exact Drupal trusted-host pattern. A request carrying any other Host
header gets 400.

Every start writes both values into the Listener record in Site data, beside the
files directory. A start reads only the files directory back, so `--listen` does
not become sticky. `dr` reads all three, which is how `SITE dr user:login`
prints a working link whatever port the site runs on.

## A first start

A first start turns an empty Site data directory into an installed site. It
consumes the nine options marked `first start`, and no later start reads them.
An interrupted first start resumes at the step it stopped on.

`--database` selects the backend. SQLite needs nothing further. MySQL and
PostgreSQL require `--db-host`, `--db-name`, `--db-user` and `--db-password`.
A missing one stops the start. `--db-port` defaults to 3306 for MySQL and 5432
for PostgreSQL.

`--admin-user` and `--admin-password` name the administrator account. Without
them a first start creates `admin` with a generated password, then prints a
one-time login link. The generated password is never shown.

`--site-name` names the site. A later start never renames a site.

## What `dr` accepts and ignores

`dr` runs the bundled Drush command set. Drupack's own options precede the
Drush command. The first word that is not one of them starts the command, and
everything after it reaches Drush unchanged.

`dr` acts on `--data-dir`, `--listen` and `--host`. It parses the other ten and
acts on none of them:

- `dr --admin-password x user:login` spends the secret for nothing
- `dr --site-name Foo status` renames nothing
- `dr --database bogus status` exits 1 over a value it never reads

`dr` takes no Serving lease and initializes nothing, so it works while the site
serves. It needs an installed site. An empty Site data directory stops it with
the directory named.

## `clean`

`clean` removes the unpacked applications from the cache. An entry a running
site holds is listed and kept, because the site reads its PHP files on every
request. `--dry-run` lists every entry with its size and removes none.

The unpacked runtimes are cleaned on their own schedule. A start dates the
entry it runs, and cleanup removes an entry unused for 30 days.

## Environment

Ten options read a variable when the option is absent. The table above names
each one.

Three variables have no option:

| Variable | Selects |
|---|---|
| `DRUPACK_CACHE_DIR` | where a release unpacks, for a disk with more room |
| `DRUPACK_CA_FILE` | the TLS trust bundle PHP verifies HTTPS against |
| `DRUPACK_LIBC` | `musl` or `glibc`, the runtime a Linux executable runs |

A Linux executable carries a runtime per C library and picks one per host, so
`DRUPACK_LIBC` is for a host where that choice needs overriding. A value naming
neither runtime stops the start. The macOS and Windows executables carry one
runtime and ignore the variable.

## The engine executable

```
drupack [DIR] [--listen IP:PORT]
drupack drush DRUSH_COMMAND
drupack php SCRIPT|-r CODE [ARGUMENTS]
drupack clean [--dry-run]
drupack --help
drupack --version
```

`drupack` serves a Drupal project folder under the folder's own settings. It
has no Site data, and it takes none of the options above.

`drupack` with no command serves `DIR`, the working directory by default. A
first word naming no command is taken as `DIR`.

- The docroot is the scaffold web root `composer.json` names, `web` when it
  names none.
- The start refuses a docroot without `core/lib/Drupal.php`. The Caddyfile's
  guards and front controller are Drupal's, and would not suit a WordPress site.
- `--listen` defaults to `127.0.0.1:8888`.
- The start refuses a folder whose PHP version or `composer.lock` extensions
  the bundled PHP does not meet, and names what is missing.
- The start prints a one-time login link for uid 1, through the folder's Drush,
  and still serves when that fails.
- Caddy's errors go to standard error.

`drush` runs the Drush of the nearest directory at or above the working directory
that holds `vendor/autoload.php`. Drush's child processes find a `php` on
`PATH` that runs the bundled PHP.

`php` runs a script, or `-r` code, on the bundled PHP, from the working
directory. It takes no PHP option such as `-v` or `-d`.

`clean` removes the unpacked engine files from the cache.

## Other words

`lay-app` is internal: a start or `dr` runs it to lay or check a site's own
application.

Any first word Drupack does not handle reaches the embedded server's own
command line. Nothing there carries a compatibility promise.
