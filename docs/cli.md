# Drupack command line

Every option a site's executable accepts, what it sets, and when it takes
effect, then the commands of the engine executable, `drupack`. The parser in
`application/launch.php` is the contract the options record, and the usage
in `engine/serve.php` the contract the engine commands record. A test in
`application/tests/launch_test.php` fails when the two disagree.

## Synopsis

```
SITE [start] [OPTIONS]
SITE stop [--data-dir PATH]
SITE drush [OPTIONS] DRUSH_COMMAND
SITE node|npm|npx [ARGUMENTS]
SITE clean [--dry-run]
SITE --help
SITE --version
```

`SITE` is the site's executable, `drupacked-demo` for the Drupacked Demo.

An option takes `--name value` or `--name=value`. `--help` prints the option
set. A rejected value prints its reason on standard error and exits 1.

`--version` prints the release on its first line, with the C library on Linux.
One line per component follows, each naming the component and its release:

- FrankenPHP, PHP, Caddy and SQLite, from the runtime build
- Node, when the file carries it
- Drupal core and Drush, from the site's `composer.lock`

## Options

| Option | Value | Default | Environment | Applies |
|---|---|---|---|---|
| `--data-dir` | PATH | `./data` | `DRUPACK_DATA_DIR` | every start, `drush` |
| `--listen` | IP:PORT | `127.0.0.1` on the site's port, `7225` for the Drupacked Demo | none | every start |
| `--host` | HOST | `localhost` | none | every start |
| `--files-dir` | PATH | `files` in Site data | none | every start, `drush` |
| `--database` | `sqlite`, `mysql`, `pgsql` | `sqlite` | `DRUPACK_DATABASE` | first start |
| `--db-host` | HOST | none | `DRUPACK_DB_HOST` | first start |
| `--db-port` | PORT | `3306` or `5432` | `DRUPACK_DB_PORT` | first start |
| `--db-name` | NAME | none | `DRUPACK_DB_NAME` | first start |
| `--db-user` | NAME | none | `DRUPACK_DB_USER` | first start |
| `--db-password` | PASSWORD | none | `DRUPACK_DB_PASSWORD` | first start |
| `--admin-user` | NAME | `admin` | `DRUPACK_ADMIN_USER` | first start |
| `--admin-password` | PASSWORD | generated | `DRUPACK_ADMIN_PASSWORD` | first start |
| `--site-name` | NAME | the site's name, `Drupacked Demo` for the Drupacked Demo | `DRUPACK_SITE_NAME` | first start |
| `--no-browser` | none | off | none | every start |
| `--foreground` | none | off | none | every start |

## Site data

`--data-dir` selects the Site data directory. A relative path resolves against
the directory you started Drupack in, never against the unpacked application.
`DRUPACK_DATA_DIR` sets the default.

The value must not contain a double quote. The log path reaches the Caddyfile
through the environment, which Caddy splices in before it reads quotes.

A site whose `drupack.yml` lists `writable` directories keeps its own
application in `app` in Site data. A start lays it there once per release.
`drush` refuses an `app` another release laid, until a start lays this one.
`clean` never removes it.

## Public files

`--files-dir` names the directory that holds the site's public files. The site
addresses them as `/sites/default/files`. By default they live in `files` in
Site data.

A relative path resolves against the directory you started Drupack in. The
value must not contain a double quote, for the same reason as `--data-dir`.

The Listener record keeps the directory a start names. Later starts and `drush`
use it without the option, and a new `--files-dir` replaces it. A start copies
the bundled translations into its `translations` directory, keeping any file
already there.

## The listener

`--listen` takes IP:PORT. An IPv6 address needs brackets. The port must fall
between 1 and 65535. The address must parse as an IP address, so a hostname is
rejected.

`--host` names the permitted request host, as a hostname or an IP address. It
becomes an exact Drupal trusted-host pattern. A request carrying any other Host
header gets 400. Printed URLs put an IPv6 host in brackets.

A browser opens only on a loopback listener, 127.0.0.0/8 or `::1`. Any other
listener, `0.0.0.0` included, prints the login link and opens nothing.

Every start writes both values into the Listener record in Site data, beside the
files directory. A start reads only the files directory back, so `--listen` does
not become sticky. `drush` reads all three, which is how `SITE drush user:login`
prints a working link whatever port the site runs on.

## A first start

A first start turns an empty Site data directory into an installed site. It
consumes the nine options marked `first start`, and no later start reads them.
An interrupted first start resumes at the step it stopped on. On MySQL or PostgreSQL, a first start cut off after it began installing leaves the next start refusing while the database holds tables, and installing once it is empty.

`--database` selects the backend. SQLite needs nothing further. MySQL and
PostgreSQL require `--db-host`, `--db-name`, `--db-user` and `--db-password`.
A missing one stops the start. `--db-port` defaults to 3306 for MySQL and 5432
for PostgreSQL.

`--admin-user` and `--admin-password` name the administrator account. Without
them a first start creates `admin` with a generated password, then prints a
one-time login link. The generated password is never shown.

`--site-name` names the site. A later start never renames a site.

## What `drush` accepts and ignores

`drush` runs the bundled Drush command set. Drupack's own options precede the
Drush command. The first word that is not one of them starts the command, and
everything after it reaches Drush unchanged.

`drush` acts on `--data-dir`, `--listen` and `--host`. It parses the other ten and
acts on none of them:

- `drush --admin-password x user:login` spends the secret for nothing
- `drush --site-name Foo status` renames nothing
- `drush --database bogus status` exits 1 over a value it never reads

`drush` takes no Serving lease and initializes nothing, so it works while the site
serves. It needs an installed site. An empty Site data directory stops it with
the directory named.

## `node`, `npm` and `npx`

A site that carries Bundled Node runs its release under these three words.
Each runs in the reader's directory with the reader's arguments. It exits with
the program's status.

The first start of a release unpacks it into the cache, under `node`, once per
version. npm keeps its own cache and registry settings in the reader's home.
The server and `drush` find the same release first on `PATH`.

A musl file carries no Node. There each word exits 1 and names the glibc
file. A site without Node answers each word as an unknown command.

## `start` and `stop`

`SITE` and `SITE start` are one command. A start takes the Serving lease, then
runs itself again in the background with `--foreground`. It shows that
server's output until the site answers, then prints the log file and the
command that stops the site, and returns 0. A server that exits before it
answers makes the start exit with its code. Its output after that goes to
`logs/server.log` in Site data, which each start empties.

On Windows the printed command names the executable as `.\NAME.exe` when the
start ran in its folder. A path that needs quotes gets PowerShell's `& ` in
front, which cmd does not accept.

`--foreground` serves in the terminal until Ctrl+C or a signal, as a container
or a systemd unit needs. A Windows start from a file manager owns its window,
so it serves in the foreground too.

Once the site answers, the server listens on a random port of `127.0.0.1`,
whatever `--listen` names. It writes that port, a random token and its PID to
`stop.json` in Site data. On Unix only its owner can read the file; on Windows
it takes the access of the Site data directory. `stop` sends the token
there, and the server stops the way Ctrl+C stops it. `stop` then waits for the
Serving lease to free, 15 seconds at most, and exits 1 past that. With no
server running it says so and exits 0.

`stop` takes `--data-dir` and no other option. It works on a foreground site
too.

## `clean`

`clean` removes the unpacked applications and Node releases from the cache. An entry a running
site holds is listed and kept, because the site reads its PHP files on every
request. `--dry-run` lists every entry with its size and removes none.

The unpacked runtimes are cleaned on their own schedule. A start dates the
entry it runs, and cleanup removes an entry unused for 30 days.

## Environment

Ten options read a variable when the option is absent. The table above names
each one.

Two variables have no option:

| Variable | Selects |
|---|---|
| `DRUPACK_CACHE_DIR` | where a release unpacks, for a disk with more room |
| `DRUPACK_CA_FILE` | the TLS trust bundle PHP verifies HTTPS against |

## The engine executable

```
drupack [start] [DIR] [--listen IP:PORT] [--foreground]
drupack stop [DIR]
drupack drush DRUSH_COMMAND
drupack dr DRUPAL_COMMAND
drupack php SCRIPT [ARGUMENTS]
drupack php -r CODE
drupack clean [--dry-run]
drupack --help
drupack --version
```

`drupack` serves a Drupal project folder under the folder's own settings. It
has no Site data, and it takes none of the options above.

`drupack --version` names FrankenPHP, PHP, Caddy and SQLite after its release.
It carries no Drupal, Drush or Node, so it prints no line for them.

`drupack` with no command serves `DIR`, the working directory by default. A
first word naming no command is taken as `DIR`. `drupack start` does the same
as `drupack`, and `start` and `stop` are reserved: a folder with either name
takes `./start`.

The server runs in the background. The start prints its progress and the login
link, returns once the site answers, and names the log file. `--foreground`
serves in the terminal until a signal stops it. `drupack stop [DIR]` ends the
server of `DIR`, the working directory by default. The server's lease, stop
record and log live in the cache, keyed by the folder, so the folder gains no
file. A second start on a Project folder that a server already serves exits 1
and says it already serves.

- The docroot is the scaffold web root `composer.json` names, `web` when it
  names none.
- The start refuses a docroot without `core/lib/Drupal.php`. The Caddyfile's
  guards and front controller are Drupal's, and would not suit a WordPress site.
- `--listen` defaults to `127.0.0.1:8888`.
- The start refuses a folder whose PHP version or `composer.lock` extensions
  the bundled PHP does not meet, and names what is missing.
- The start prints a one-time login link for uid 1, through the folder's Drush.
  It still serves when that fails, or when the folder has no Drush.
- Caddy's errors go to standard error.

`drush` runs the Drush of the nearest directory at or above the working directory
that holds `vendor/autoload.php`. Drush's child processes find a `php` on
`PATH` that runs the bundled PHP.

`dr` runs Drupal core's own command line, `vendor/bin/dr`, of the same
project, the same way. Drupal 11.4 and later ship it.

`php` runs PHP from the working directory, in one of two forms:

- `SCRIPT [ARGUMENTS]` runs the script with its arguments, and exits with its status.
- `-r CODE` runs the code, which gets no `$argv`.

Any other option stops before PHP starts, with a message naming it and the two
forms. That covers `-d`, `-l`, `-v`, `-m`, `-i`, `--ini`, `--ri`, `-f`, `-h`
and `-S`. An argument after `-r CODE` stops the same way. Drush's child
processes reach the same command through the `php` on `PATH`.

`clean` removes the unpacked engine files from the cache.

## Other words

`lay-app` is internal: a start or `drush` runs it to lay or check a site's own
application.

Any first word Drupack does not handle reaches the embedded server's own
command line. Nothing there carries a compatibility promise.
