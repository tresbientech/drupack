# Drupack

Drupack runs Drupal from a single executable. Each release publishes two:

- `drupack` serves a Drupal project you already have, with its own settings.
- `mercury-demo` is a Drupal CMS site built from the Mercury Demo template. It carries Drupal CMS, PHP, Caddy, SQLite, MySQL and PostgreSQL drivers and Drush, and needs nothing else to serve a site.

The first section covers `drupack`. Every later section covers the Mercury Demo.

## Serve a Drupal project you already have

```sh
curl -L -o drupack https://github.com/tresbientech/drupack/releases/latest/download/drupack-linux-amd64
chmod +x drupack
cd path/to/your/project
/path/to/drupack
```

`drupack` serves the project in the working directory, or in the directory you name after it. It serves Drupal alone, and refuses a folder without Drupal core. It reads the docroot from the scaffold web root in `composer.json`, `web` by default. It prints a one-time login link, then serves on `http://127.0.0.1:8888`. `--listen IP:PORT` picks another address.

The project's own `settings.php` names the database, the files paths and the hash salt. Drupack writes nothing into the project, and Drupal writes files where those settings put them. For a ddev project, point `settings.local.php` at the database's published port on `127.0.0.1`. The ddev host name `db` resolves only inside ddev.

`drupack drush COMMAND` runs the project's own Drush on the bundled PHP, from any directory inside the project. Drush's own child processes run on that PHP too. `drupack php SCRIPT` runs a PHP script.

A project whose `composer.lock` needs a PHP extension the bundled PHP lacks is refused, with the extension and the packages that need it.

The other downloads are `drupack-linux-arm64`, `drupack-macos-arm64`, `drupack-macos-amd64` and `drupack-windows-amd64.exe`. macOS and Windows show the same first-run warnings as the Mercury Demo below.

## Install the Mercury Demo

Make a folder for your site and download the Mercury Demo into it. Site data lives beside the executable.

### Linux

```sh
mkdir my-site && cd my-site
curl -L -o mercury-demo https://github.com/tresbientech/drupack/releases/latest/download/mercury-demo-linux-amd64
chmod +x mercury-demo
```

On a 64-bit Raspberry Pi or another ARM machine, use `mercury-demo-linux-arm64`.

One Linux download runs everywhere. It carries a runtime built against each C
library and picks one when it starts.

| Your host | Runtime it runs | Why |
|---|---|---|
| glibc, which covers Debian, Ubuntu, Fedora, RHEL and Arch | glibc | serves a rendered page faster |
| musl, which covers Alpine and most slim containers | musl | the only one that runs there |
| anything the check cannot place | musl | runs on any host |

`mercury-demo --version` names the runtime that ran. To run the other one, set
`DRUPACK_LIBC` to `musl` or `glibc`.

### macOS

```sh
mkdir my-site && cd my-site
curl -L -o mercury-demo https://github.com/tresbientech/drupack/releases/latest/download/mercury-demo-macos-arm64
chmod +x mercury-demo
xattr -d com.apple.quarantine mercury-demo
```

On an Intel Mac, use `mercury-demo-macos-amd64`. Gatekeeper blocks a downloaded executable that carries no Apple signature, and the `xattr` command clears that mark.

### Windows

```powershell
mkdir my-site; cd my-site
curl.exe -L -o mercury-demo.exe https://github.com/tresbientech/drupack/releases/latest/download/mercury-demo-windows-amd64.exe
```

SmartScreen shows "Windows protected your PC" the first time, because the executable carries no code signature. Choose "More info", then "Run anyway".

### Keeping the Mercury Demo on your PATH

A folder per site keeps each site with its data. To run `mercury-demo` from anywhere instead, move the executable into a directory on your `PATH`, such as `~/.local/bin`. Site data then lands in whichever directory you start it from, so pass `--data-dir` to choose one.

## Start your site

```sh
./mercury-demo
```

Drupack installs your site and serves it on `http://localhost:7225`. It prints a one-time login link and opens your browser on it. The link logs you in as `admin` and lands you on your dashboard. Set your own password from there, under your account. The first start of each version also unpacks its runtime, which adds about a second.

Later starts need nothing:

```sh
./mercury-demo
```

Each one prints its own link and opens your dashboard the same way. A link works once, so `./mercury-demo dr user:login /admin/dashboard` prints a fresh one whenever you need it. `--no-browser` starts the site without opening anything, and still prints the link.

To choose the administrator name and password yourself, for a script or a fresh machine:

```sh
./mercury-demo --admin-user admin --admin-password 'choose-a-password'
```

Drupack still prints the login link.

Stop the site with Ctrl+C.

The terminal shows the address, where Site data lives, the log file and how to stop. Caddy's messages, PHP warnings and PHP errors go to `data/logs/caddy.log`, so they stay out of your way.

Useful options:

- `--data-dir PATH` puts Site data somewhere else. `DRUPACK_DATA_DIR` sets a default.
- `--listen IP:PORT` serves on another address, `127.0.0.1:7225` by default. Each start records its address in Site data, so `dr` reaches the site without repeating the option.
- `--site-name NAME` names the site on a first start, `Drupal Mercury Demo` by default. `DRUPACK_SITE_NAME` sets it too. A later start never renames a site.
- `--no-browser` starts without opening a browser.
- `mercury-demo --version` prints the release, `mercury-demo version` names every component it carries, and `mercury-demo --help` lists every option.

[The command line reference](docs/cli.md) covers every option, including the ones this list leaves out.

## Administer with Drush

`dr` runs the bundled Drush commands against your site. Drupack's own options come before the Drush command.

```sh
./mercury-demo dr status
./mercury-demo dr --data-dir ./data user:login
./mercury-demo dr pm:list --status=enabled
```

## Site data

Site data lives in the `data` directory. It holds your database, uploads, private files, configuration exports and generated settings. Copy that directory to back your site up, with the site stopped. Keep the copy private: it holds your content and your site's secrets.

A newer Drupack keeps working with existing Site data, which every release is tested for.

One start at a time prepares a site, and one server at a time serves it, whatever port a second start asks for. A second start of a site that already serves opens your browser on it, then stops, so a double-click always lands you on your dashboard. A second start with no terminal, and a start whose port another program holds, stop with a message naming the port. Drush keeps working while a site serves.

Drupack refuses to serve in three cases, each naming the directory and the command to run:

- an interrupted setup left the administrator account unset, so the site would still accept the published seed password
- a site exists that Drupal cannot start
- the database already holds a site that Drupack did not install

An interrupted setup resumes where it stopped. Drupack never installs Drupal over a database that already holds a site, and never copies its starting site over an existing one.

## The unpacked runtime

Your download carries Drupal, PHP and Caddy compressed. The first start of a version unpacks them into a cache directory, which takes about a second. Every later start of that version uses what is already there.

- Linux: `~/.cache/mercury-demo/runtime`
- macOS: `~/Library/Caches/mercury-demo/runtime`
- Windows: `%LOCALAPPDATA%\mercury-demo\runtime`

`drupack` unpacks the same way, under `drupack` in place of `mercury-demo`.

Releases up to 0.4.0 named the Mercury Demo executable `drupack`, and unpacked it under `drupack`. `drupack clean` removes the applications those releases unpacked there. Releases up to 0.2.0 unpacked into `~/.cache/Drupack/runtime` on Linux. Nothing reads that directory any more, so delete it after upgrading.

One version takes about 400 MB on Linux and macOS, beside your Site data. The space is per version, and a successful start removes the versions it replaces, so upgrading does not stack them up.

`DRUPACK_CACHE_DIR` moves the cache, for a disk with more room. On Linux and macOS a home directory that refuses writes sends the runtime to the temporary directory instead. On Windows, a cache root PHP's startup cannot read sends the runtime to the temporary directory too, printing why.

## TLS trust

Drupack carries its own trust anchors. A copy of curl's `cacert.pem` sits beside the runtime, and PHP verifies HTTPS against it. Update checks and the project browser therefore work on a machine whose own trust store is missing, empty or out of reach.

`DRUPACK_CA_FILE` names a different bundle. Drupack keeps the value you set and points PHP at your file instead of the packed copy.

Behind a proxy that re-signs TLS, set it to a bundle holding your proxy's root certificate. Update checks then verify again, with no other change.

## Use MySQL or PostgreSQL

SQLite runs your site by default, with no setup. To use a database server instead, pass its details on the first start:

```sh
./mercury-demo --database mysql \
  --db-host 127.0.0.1 --db-name drupal \
  --db-user drupal --db-password 'database-password' \
  --admin-user admin --admin-password 'choose-a-password'
```

Use `--database pgsql` for PostgreSQL. `--db-port` defaults to `3306` for MySQL and `5432` for PostgreSQL. Later starts read the connection from Site data.

Ten options read an environment variable when the option is absent: `DRUPACK_DATA_DIR`, `DRUPACK_DATABASE`, `DRUPACK_DB_HOST`, `DRUPACK_DB_PORT`, `DRUPACK_DB_NAME`, `DRUPACK_DB_USER`, `DRUPACK_DB_PASSWORD`, `DRUPACK_ADMIN_USER`, `DRUPACK_ADMIN_PASSWORD` and `DRUPACK_SITE_NAME`. `--listen`, `--host` and `--no-browser` have none.

## Hosting

A server with 512 MB of memory runs the demo site. The site uses about 170 MB. Your own modules and content can use more.

## Local AI agents

Drupack carries the MCP Tools and MCP Server modules, both disabled, with no transport exposed.

## Updates

Drupal core security fixes reach you through a new Drupack release, because a packaged executable cannot update its own Drupal in place. Download the new executable, put it in your site's folder, and start it.

Your site ships without `automatic_updates` and `package_manager` enabled. `drupal/automatic_updates` 4.1.0 stalls a request for four minutes when cron runs, and its development branch carries the same code, so enabling either module brings that stall back.

Drupack runs Drupal's cron itself, in a separate process, a couple of minutes after your site answers and every three hours it keeps serving. The `automated_cron` module stays installed, as Drupal CMS installs it, with its interval set to 0 so it runs nothing at the end of a page request. A reader never waits on a queue or on a fetch that cannot reach drupal.org.

[The backlog](docs/backlog.md) records how releases will follow upstream security fixes, and how a newer executable will guide you through a database update.

## Verify a download

Each release publishes `checksums.txt`, which covers both the versioned and the unversioned file names.

```sh
sha256sum --ignore-missing -c checksums.txt
```

On Windows, compare your file against the entry in `checksums.txt`:

```powershell
(Get-FileHash mercury-demo-windows-amd64.exe -Algorithm SHA256).Hash.ToLower()
```

GitHub records where each file was built. The GitHub CLI checks that record:

```sh
gh attestation verify drupack-linux-amd64 --repo tresbientech/drupack
```

A release also carries `release.json`, listing every file with its target, URLs, SHA-256 value and size, and `drupack.cdx.json`, a CycloneDX inventory of everything inside the executable.

## Build your own site

Drupack builds a Linux executable of any Drupal site from its Composer project and a recipe, on GitHub, Gitea or any CI that runs a container. [docs/build-your-site.md](docs/build-your-site.md) has the setup for each.

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md) covers building Drupack, running its test suites and the development loop.
