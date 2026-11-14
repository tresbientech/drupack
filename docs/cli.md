# Drupack command line

The commands, options and environment variables of `drupack`, the engine
executable, then those of a packaged site's executable.

## Stability

From 1.0.0, every 1.x release keeps this much of `drupack`, the engine
executable:

- its commands and their options
- the variables under [Environment](#environment), and the three a
  [Mercure hub](#mercure-hub) start exports
- the `extra.drupack` keys an engine start reads from `composer.json`
- exit 0 for a success and another code for a failure
- the default listener, `127.0.0.1:3787`
- refusals on standard error
- the `drupal/drupack` package and `vendor/bin/drupack-install`
- the release file names and the fields of `release.json`
- the install addresses, `https://drupack.tresbien.tech/install.sh` and
  `install.ps1`

A minor release adds to that list. A removal, a rename or a changed default
waits for 2.0.0.

Any release may change:

- the wording of a message
- the layout of [the cache](#the-cache)
- the format of a log line
- the versions of FrankenPHP, Caddy and Composer a release carries

A patch release keeps the PHP minor it carries, such as 8.5. A minor release
may move to the next one, and its release notes say so.

Packaged sites are experimental. A minor release may change the build,
`drupack.yml`, a site's commands and options, and Site data, and its release
notes name each change. A patch release changes none of them. The sections
from [A packaged site](#a-packaged-site) on describe them.

## The engine executable

```
drupack [start] [DIR] [--listen IP:PORT] [--static-listen IP:PORT] [--host HOST] [--foreground] [--access-log[=FILE]] [--error-log[=FILE]] [--trusted-proxies CIDR[,CIDR]]
drupack stop [DIR]
drupack drush DRUSH_COMMAND
drupack dr DRUPAL_COMMAND
drupack composer COMPOSER_COMMAND
drupack export [DRUSH_OPTIONS]
drupack php [-d KEY[=VALUE]]... SCRIPT [ARGUMENTS]
drupack php [-d KEY[=VALUE]]... -r CODE [ARGUMENTS]
drupack clean [--dry-run]
drupack self-update [--check]
drupack trust [--uninstall]
drupack --help
drupack --version
```

`drupack` serves a Drupal project folder under the folder's own settings. It
has no Site data and takes none of the site options. Its state lives in the
cache, keyed by the folder, so the folder gains no file. `drupack --version`
names FrankenPHP, PHP, Caddy and SQLite alone.

An option takes `--name value` or `--name=value`. A rejected value prints its
reason on standard error and exits 1.

### Serving

`drupack` and `drupack start` serve `DIR`, the working directory by default, in
the background. `--foreground` serves in the terminal. `drupack stop [DIR]`
ends the server. After a stop, Ctrl+C or SIGTERM, a server has 10 seconds to
finish its running requests. Past that it ends them, says so on standard error
and exits 0. A second start on a folder a server already serves hands over:
it prints the served URL with the login link, the command that mints one or the
install address, opens the browser and exits 0. With no terminal it exits 1. A
first word naming no command is taken as `DIR`, so a folder named `start` or
`stop` takes `./start`.

- `--listen` defaults to `127.0.0.1:3787`. It takes IP:PORT, with IPv6 in
  brackets, and refuses a hostname, as a site's does. The URL names the
  folder's [Site host](#site-hosts) when it resolves.
- `--host` names one more request host a loopback listener accepts, such as
  the public name a proxy on the same machine forwards. The URL names that
  host. [HTTPS](#https) states which hosts serve HTTPS.
- The docroot is the scaffold web root `composer.json` names, `web` when it
  names none.
- The start refuses a docroot without Drupal core.
- A folder with no Drupal core, a `composer.json` and no `vendor/` is refused
  with `drupack composer install`, before the `composer.lock` checks.
- The start refuses a folder whose PHP version or `composer.lock` extensions
  the bundled PHP does not meet, and names what is missing.
- A start a person watches prints a one-time login link through the folder's
  Drush and opens the browser on it. A folder without Drush serves with no
  link and opens the URL.
- A folder whose settings name no database, or whose database holds no
  installed site, prints `Install:` with `/core/install.php` and opens it
  instead.
- [HTTPS](#https), [Mercure hub](#mercure-hub), [Logs](#logs) and [Trusted
  proxies](#trusted-proxies) each have a section below. `DIR` goes before
  `--access-log` or `--error-log` written bare.
- The engine runs no Drupal cron. [Run the engine as a service](run-as-a-service.md)
  schedules it, with a systemd unit, an OpenRC service and a proxy in front.
- With `extra.drupack.static`, the start serves the project's
  `tome_static_directory` on `--static-listen`, or `html` beside the docroot
  when `settings.php` sets none.

For a DDEV project, point `settings.local.php` at the database's published port
on `127.0.0.1`. The DDEV host name `db` resolves only inside DDEV.

### Commands

`drupack drush` runs the Drush of the nearest directory at or above the working
directory that holds `vendor/autoload.php`, on the bundled PHP. Drush's child
processes run on that PHP too. It refuses a project another account owns.

`drupack dr` runs Drupal core's own command line, `vendor/bin/dr`, the same
way. Drupal 11.4 and later ship it.

`drupack composer` runs the Composer the engine carries, on the bundled PHP, in
the working directory. The working directory holds a Drupal project:

- its `composer.lock` names a `drupal/core` release
- with no lock, its `composer.json` requires `drupal/core`,
  `drupal/core-recommended` or `drupal/core-composer-scaffold`, or sets
  `extra.drupal-scaffold`, and its `type` names no Drupal package, as
  `drupal-core` or `drupal-module` do

It refuses `-d`, `--working-dir` and a non-blank `COMPOSER`, which point
Composer at another project. It refuses `global`, `create-project` and
`self-update`, under any prefix, in any case. A template or a distribution starts as a
clone or a download, then `drupack composer install`. A Drupack release moves
the Composer it carries.

`drupack export` runs Tome at the static URL the last start served. A project
no start has served needs `--uri`.

`drupack php` runs the bundled PHP, as [`php`](#php-node-npm-and-npx)
describes.

`drupack clean` removes the unpacked engine files, and the state of each folder
no server runs.

`drupack trust` installs the local root, as [HTTPS](#https) describes.

### Updates

`drupack self-update` installs the newest release over the file, after checking
its size and SHA-256. `--check` names the newest release and changes nothing.
On a site, `self-update` exits 1 and says the site's publisher ships its
releases.

- A file in a directory you cannot write is refused before any download.
- A copy installed through Composer updates with
  `composer update drupal/drupack`. A project whose `composer.json` does not
  run `drupack-install` as a `post-update-cmd` script runs
  `vendor/bin/drupack-install` after it.
- A release older than the file is never installed.

Once a day, a run checks for a newer release in the background. While one
exists, each run prints one line naming it. `DRUPACK_NO_UPDATE_CHECK=1` turns
the check off. It stays off under `CI`, without a terminal, and for a Composer
copy.

## `php`, `node`, `npm` and `npx`

`drupack php` and `SITE php` run the bundled PHP from the working directory,
in one of two forms:

- `SCRIPT [ARGUMENTS]` runs the script and exits with its status.
- `-r CODE [ARGUMENTS]` runs the code, with the arguments in `$argv`.

`-d KEY=VALUE` before either form sets a PHP setting for that run, as PHP's own
`-d` does. `-d KEY` sets it to `1`. Any other PHP option stops with a message
naming it.

A `php` line, `drush`, `export` and the server find the same PHP as the `php`
on their `PATH`, so a script's `#!/usr/bin/env php` runs it. `PHP_BINARY`
names it for every process Drupack starts, and Composer runs its `@php`
scripts with it. On Windows `PHP_BINARY` names `php.exe` beside the runtime.

Two limits apply to `-d`:

- `max_execution_time`, `output_buffering`, `html_errors`, `implicit_flush`
  and `max_input_time` keep the values FrankenPHP sets.
- A PHP outside Drupack, started while the script runs, reads its `-d`
  settings. A PHP that Drupack runs reads none of them.

A packaged site that carries Bundled Node runs it as `node`, `npm` and `npx`,
in the working directory. The server and `drush` find that Node first on `PATH`. A
musl file carries no Node: each word exits 1 and names the glibc file. A site
without Node answers each word as an unknown command.

## Site hosts

Each site has a Site host under `drupack.site`, whose public DNS answers every
name with `127.0.0.1`. Separate names keep each site's cookies apart, which
`localhost` cannot, since browsers ignore the port.

- `extra.drupack.subdomain` in `composer.json` sets the label:
  `"subdomain": "demo"` gives `demo.drupack.site`. It must be one DNS label.
- Without it, a Packaged site's label is the `name` in `drupack.yml`.
- Without it, an engine start's label is the folder's name: `~/DEV/My Shop`
  gives `my-shop.drupack.site`.

A start on `127.0.0.1` without `--host` looks the name up and waits at most one
second. When the answer holds `127.0.0.1`, the URL and the login link use the
name. Otherwise they use `localhost`, or the bind address for the engine, and
the start prints the answer:

```
Name: demo.drupack.site did not resolve to 127.0.0.1 (no answer), so the URL uses localhost.
```

An offline machine gets no answer, and a DNS filter may return another address.

A loopback start answers the name, `localhost`, `127.0.0.1`, `[::1]`, its bind
address and its `--host`. Over plain HTTP, a request for any other name gets
status 421 and one sentence that names `--host`, and nothing of the site.
A name of your own in `/etc/hosts` needs `--host`. A start on any other address
answers every name.

A Project folder keeps its own settings. When they set `trusted_host_patterns`,
add `'^.+\.drupack\.site$'` so Drupal accepts the name.

## HTTPS

Run `drupack trust` or `SITE trust` once, and every engine start and Drupack
site of your account serves HTTPS on loopback. `trust` creates a local root
and installs it in the system's trust store and in Firefox's:

- Linux asks for your password through `sudo`. Firefox gets the root only when
  `certutil` is installed.
- macOS asks for your password and writes the System keychain.
- Windows writes your account's root store after one confirmation dialog.

The root can vouch only for servers named `localhost`, `drupack.site`, their
subdomains, `127.0.0.0/8` or `::1`. Chromium and Firefox enforce that limit;
Safari and Edge are unchecked. `trust --uninstall` removes the root and deletes
it.

A machine where [mkcert](https://github.com/FiloSottile/mkcert) is installed
and trusted, as DDEV sets up, serves HTTPS with no `trust`. A start prefers
mkcert's root over Drupack's.

A start serves HTTPS when its bind is loopback, its host is `localhost`,
`127.0.0.1`, `::1` or the Site host, and the machine trusts one of the two
roots. Any other start serves HTTP, and a loopback start on HTTP prints a line
naming `trust`. The login link, `drush` and the Mercure hub's address use the
scheme the start served.

## Static site

A site or project whose `composer.json` sets `extra.drupack.static` serves its
Tome export on a second listener. That listener answers with the export's
files and hands the paths `dynamic` lists to Drupal.
[The build guide](build-your-site.md#a-static-site) describes the key.

`--static-listen IP:PORT` names the listener. Its default is the site
listener's address on the next port. The two ports differ, and a start refuses
a static port another program holds. On a site without the key the option
exits 1.

- A request answers the path's file, or `index.html` under it.
- A miss answers the export's own `404/index.html` with status 404.
- A path `dynamic` matches reaches Drupal, even when the export holds a page
  under it.
- A `.php` path answers 403.

`SITE export` writes the export into `static` in Site data. It runs Drush's
`tome:static` with the static URL the start printed as `Static:`, then every
word after `export` unchanged. A `--uri` or `-l` among those words replaces the
URL. The exit code is Drush's. `export` takes the options `drush` takes and
needs an installed site. Without the key it exits 1. With `tome_static` not
enabled it exits 1 and names the `drush pm:install` command that enables it.

## Mercure hub

A site or project whose `composer.json` sets `extra.drupack.mercure` to `true`
serves a Mercure hub at `/.well-known/mercure`. A start creates the hub's two
secrets, and the hub keeps its last 1000 updates. For the engine, both live in
the folder's cache entry, so a moved folder gets new secrets. For a packaged
site, both live in Site data.

A start exports three variables to the server, to the PHP it runs and to
`drush`:

| Variable | Holds |
|---|---|
| `DRUPACK_MERCURE_URL` | the hub's address, and the `iss` and `aud` of every token |
| `DRUPACK_MERCURE_PUBLISHER_SECRET` | the HS256 key a publisher token is signed with |
| `DRUPACK_MERCURE_SUBSCRIBER_SECRET` | the HS256 key a subscriber token is signed with |

[The build guide](build-your-site.md#a-site-that-pushes-live-updates) describes
how a site publishes and subscribes.

## Logs

A background start keeps two logs in a folder it prints as `Logs:`. For the
engine, the folder is the cache entry of the project folder. For a packaged
site, it is `logs` in Site data.

- `server.log`: what a background server prints. Each start empties it.
- `caddy.log`: Caddy's errors and PHP's fatal errors.

`--access-log` logs each request of the site port and of the static port, as
one JSON line. Its `logger` field holds `http.log.access.site` or
`http.log.access.static`. `--error-log` logs PHP's and Caddy's warnings and
errors. A PHP notice, a deprecation and an `error_log()` call write no line.

Both options take their file the same way:

- `--access-log` alone writes `access.log` in that folder, and `--error-log`
  alone `error.log`.
- `--access-log=FILE` writes FILE, relative to the working directory.
- `--access-log FILE` is refused, since the file takes the `=`.

A start refuses one FILE for both options, a FILE that holds `{` or `}`, and a
FILE inside a directory the server answers requests from. `caddy.log` and both
optional logs roll at 100 MiB, and 10 rolled files stay.

## Trusted proxies

A server behind a reverse proxy sees the proxy as the sender of every request.
`--trusted-proxies` names the proxies, as addresses or CIDR ranges with commas
between them:

```
drupack --trusted-proxies 192.168.0.58,10.0.0.0/8
```

For a request one of them sent, the server takes the client's address from
`X-Forwarded-For`. The access log's `client_ip` then holds the client, and
`remote_ip` the proxy.

- No proxy is trusted without the option.
- The start refuses `0.0.0.0/0` and `::/0`, which would let any client name
  its own address.
- PHP's `REMOTE_ADDR` stays the proxy's address. Drupal reads the client from
  the header once `settings.php` sets `reverse_proxy` and
  `reverse_proxy_addresses`.

## The cache

An executable carries PHP and Caddy compressed, and a packaged site carries
Drupal too. The first start of a
release unpacks them into a cache directory, which takes about a second. Later
starts of that release use what is there.

- Linux: `~/.cache/NAME/runtime`
- macOS: `~/Library/Caches/NAME/runtime`
- Windows: `%LOCALAPPDATA%\NAME\runtime`

`NAME` is the executable's name, such as `drupack`.
A start keeps the runtime it runs and removes the
others. An application goes once no start has used it for 30 days.

`clean` removes the unpacked runtimes, applications and Node releases. An entry
a running site holds is listed and kept. `--dry-run` lists every entry with its
size and removes none.

`DRUPACK_CACHE_DIR` moves the cache. A start runs the PHP and Caddy it
unpacked, so it refuses a cache folder that fails this rule:

- Unix: the folder is yours, and readable and writable by you alone.
- Windows: the folder belongs to you, to SYSTEM or to Administrators.

A cache folder a start creates passes. The check reads no parent folder, so
point `DRUPACK_CACHE_DIR` at a folder under your home. A home directory that
refuses writes sends the cache to the temporary directory. So does a Windows
cache root PHP cannot read, and the start prints why.

## Environment

Five variables have no option:

| Variable | Selects |
|---|---|
| `DRUPACK_CACHE_DIR` | where a release unpacks |
| `DRUPACK_CA_FILE` | the CA bundle PHP and `self-update` verify HTTPS against |
| `DRUPACK_TLS_DIR` | the folder holding the local root, `drupack/tls` in your config folder by default |
| `DRUPACK_DNS_ANSWER` | a fixed answer for the [Site host](#site-hosts) lookup: a comma list of addresses, or `none` |
| `DRUPACK_NO_UPDATE_CHECK` | `1` turns off the engine executable's daily release check |

Drupack carries a copy of curl's `cacert.pem`, and PHP verifies HTTPS against
it, whatever the machine's own trust store holds. Behind a proxy that re-signs
TLS, set `DRUPACK_CA_FILE` to a bundle holding the proxy's root certificate.

Ten options of a packaged site read a variable when the option is absent. Its
[options table](#options) names each one.

## A packaged site

A build packs a Composer project into an executable of its own, `SITE` below.
This part of Drupack is experimental, as [Stability](#stability) states.

## Synopsis

```
SITE [start] [OPTIONS]
SITE stop [--data-dir PATH]
SITE trust [--uninstall]
SITE drush [OPTIONS] DRUSH_COMMAND
SITE export [OPTIONS] [DRUSH_OPTIONS]
SITE php [-d KEY[=VALUE]]... SCRIPT [ARGUMENTS]
SITE php [-d KEY[=VALUE]]... -r CODE [ARGUMENTS]
SITE node|npm|npx [ARGUMENTS]
SITE clean [--dry-run]
SITE --help
SITE --version
```

`SITE` is the executable of a site a build packed, named in its `drupack.yml`.

Any word Drupack does not handle answers `Unknown command`.

`--version` prints the release on its first line, with the C library on Linux.
One line per component follows: FrankenPHP, PHP, Caddy, SQLite, Node when the
file carries it, Drupal core and Drush.

## Options

| Option | Value | Default | Environment | Applies |
|---|---|---|---|---|
| `--data-dir` | PATH | `./data` | `DRUPACK_DATA_DIR` | every start, `drush` |
| `--listen` | IP:PORT | `127.0.0.1` on the port `drupack.yml` names, `7225` by default | none | every start, `drush` |
| `--static-listen` | IP:PORT | the site listener's address on its port plus one | none | every start, `drush` and `export` of a site with a static export |
| `--host` | HOST | the [Site host](#site-hosts) when it resolves to 127.0.0.1, `localhost` otherwise | none | every start, `drush` |
| `--files-dir` | PATH | `files` in Site data | none | every start, `drush` |
| `--database` | `sqlite`, `mysql`, `pgsql` | `sqlite` | `DRUPACK_DATABASE` | every start |
| `--db-host` | HOST | none | `DRUPACK_DB_HOST` | every start |
| `--db-port` | PORT | `3306` or `5432` | `DRUPACK_DB_PORT` | every start |
| `--db-name` | NAME | none | `DRUPACK_DB_NAME` | every start |
| `--db-user` | NAME | none | `DRUPACK_DB_USER` | every start |
| `--db-password` | PASSWORD | none | `DRUPACK_DB_PASSWORD` | every start |
| `--admin-user` | NAME | `admin` | `DRUPACK_ADMIN_USER` | first start |
| `--admin-password` | PASSWORD | generated | `DRUPACK_ADMIN_PASSWORD` | first start |
| `--site-name` | NAME | the `site_name` in `drupack.yml` | `DRUPACK_SITE_NAME` | first start |
| `--no-browser` | none | off | none | every start |
| `--foreground` | none | off | none | every start |
| `--access-log` | FILE, optional | off | none | every start |
| `--error-log` | FILE, optional | off | none | every start |
| `--trusted-proxies` | CIDR[,CIDR] | none | none | every start |

## `start` and `stop`

`SITE` and `SITE start` are one command. A start prints its progress, the
site's address and a login link. A path it prints writes the home directory as
`~`. It returns once the site answers, and the site keeps serving in the
background. A server that exits before it answers makes the start exit with its
code.

`--foreground` serves in the terminal until Ctrl+C or a signal, as a container
or a systemd unit needs. A Windows start from a file manager owns its window,
so it serves in the foreground too.

One server at a time serves a Site data directory, whatever port a second start
asks for. A second start of a site that already serves opens the browser on it,
under the rules of [A first start](#a-first-start), and exits. With no
terminal, or while the first start still prepares, it exits 1 and names the
URL. A start whose port another program holds exits 1 and names the port.

`SITE stop` stops the server of the Site data directory. It waits 15 seconds at
most and exits 1 past that. With no server running it says so and exits 0. It
takes `--data-dir` and no other option, and works on a foreground site too.

## A first start

A first start turns an empty Site data directory into an installed site. It
reads the three options marked `first start`, and no later start reads them. An
interrupted first start resumes at the step it stopped on.

`--database` selects the backend. SQLite needs nothing further. MySQL and
PostgreSQL require `--db-host`, `--db-name`, `--db-user` and `--db-password`:

```sh
SITE --database mysql --db-host 127.0.0.1 --db-name drupal \
  --db-user drupal --db-password 'database-password'
```

Site data records the connection. A later start takes each `--db-*` option it
names in place of the recorded value, and records it once the server accepts
the connection. It refuses a `--database` other than the recorded one. A start
whose MySQL or PostgreSQL server refuses the connection exits 1 with the
server's reason, before it serves.

`--admin-user` and `--admin-password` name the administrator account. Without
them a first start creates `admin` with a generated password, which is never
shown. `--site-name` names the site, and a later start never renames it.

A start a person watches prints a one-time login link and opens the browser on
it. When the mint fails, it prints `No login link:` with Drush's reason, prints
the command that mints a link as its `Login:` line, and opens the site URL.
`--no-browser` prints the link alone. A start with no terminal, such as a
systemd unit or a container, prints the command that mints a link, so no log
holds one:

    Login:     run demo drush --data-dir /var/lib/demo user:login /admin/dashboard

A start never installs Drupal over a database that already holds a site. It
refuses to serve in four cases, and names the directory and the command to
run:

- an interrupted setup left the administrator account unset
- a site exists that Drupal cannot start
- the database holds a site that Drupack did not install
- a setup cut off while installing into an empty database finds tables there

## Site data

`--data-dir` selects the Site data directory. A relative path resolves against
the directory you started Drupack in. Site data holds the database, the
uploads, the private files, the configuration exports, the generated settings
and the logs. Copy the directory to back the site up, with the site stopped.
Keep the copy private, since it holds the site's secrets.

A start creates a missing Site data directory readable and writable by you
alone. Every start, `drush` and `export` warn when another account owns Site
data or can write to it, and go on. The warning names the folder and a fix:

- a folder open on Unix: `chmod -R go-w FOLDER`
- a folder another account owns: move Site data into a folder you own

The check reads the folder alone, and no parent. On Windows, keep Site data out
of a folder whose access list lets other accounts write, such as a new folder
under `C:\`.

A site whose `drupack.yml` lists `writable` directories keeps its own copy of
the application in `app` in Site data. A start lays it there once per release.
`drush` refuses an `app` another release laid, until a start lays this one.

## Public files

`--files-dir` names the directory that holds the site's public files, which the
site addresses as `/sites/default/files`. A relative path resolves against the
directory you started Drupack in.

Site data records the directory a start names. Later starts and `drush` use it
without the option, and a new `--files-dir` replaces it.

`--data-dir` and `--files-dir` refuse a path that contains `{` or `}`.

## The listener

`--listen` takes IP:PORT. An IPv6 address needs brackets, and a hostname is
rejected. Each start records its address in Site data, so `drush` and `export`
reach the site without the option. A later start does not read it back.

`--host` names a request host the site accepts, as a hostname or an IP
address, beside `localhost`, `127.0.0.1`, `[::1]` and the Site host. A request
with any other Host header gets the 421 that [Site hosts](#site-hosts)
describes. Without `--host`, the
start picks the host as [Site hosts](#site-hosts) describes.

A listener beyond loopback, such as `--listen 0.0.0.0:7225`, serves plain HTTP.
The login link, the password and the session cookie cross the network
unencrypted. Put a TLS proxy in front of the site before anyone signs in from
another computer. Such a start prints the login link and opens no browser.

## `drush`

`SITE drush` runs the bundled Drush against the site. Drupack's own options
precede the Drush command, and everything after the command reaches Drush
unchanged:

```sh
SITE drush status
SITE drush --data-dir ./data user:login
```

`drush` acts on `--data-dir`, `--listen`, `--static-listen`, `--host` and
`--files-dir`. It parses the other 14 options and acts on none of them. It
works while the site serves, and needs an installed site.

## Cron and updates

A packaged site runs Drupal's cron in a process of its own, two minutes after the site
answers and every three hours after that. No page request runs cron.

A packaged executable cannot update its own Drupal in place. A Drupal core
security fix reaches a site through a new release of its executable: install
it over the old file and start it on the same Site data.
