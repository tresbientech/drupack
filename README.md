# Drupack

Drupack runs a Drupal CMS site from a single executable. It carries Drupal CMS with the Mercury Demo site template, PHP, Caddy, SQLite, MySQL and PostgreSQL drivers, Drush and Local MCP Tools. Everything it needs to serve a site travels inside that file.

## Install

Make a folder for your site and download Drupack into it. Site data lives beside the executable.

### Linux

```sh
mkdir my-site && cd my-site
curl -L -o drupack https://github.com/tresbientech/drupack/releases/latest/download/drupack-linux-amd64
chmod +x drupack
```

On a 64-bit Raspberry Pi or another ARM machine, use `drupack-linux-arm64`.

One Linux download runs everywhere. It carries a runtime built against each C
library and picks one when it starts.

| Your host | Runtime it runs | Why |
|---|---|---|
| glibc, which covers Debian, Ubuntu, Fedora, RHEL and Arch | glibc | serves a rendered page faster |
| musl, which covers Alpine and most slim containers | musl | the only one that runs there |
| anything the check cannot place | musl | runs on any host |

`drupack --version` names the runtime that ran. To run the other one, set
`DRUPACK_LIBC` to `musl` or `glibc`.

### macOS

```sh
mkdir my-site && cd my-site
curl -L -o drupack https://github.com/tresbientech/drupack/releases/latest/download/drupack-macos-arm64
chmod +x drupack
xattr -d com.apple.quarantine drupack
```

On an Intel Mac, use `drupack-macos-amd64`. Gatekeeper blocks a downloaded executable that carries no Apple signature, and the `xattr` command clears that mark.

### Windows

```powershell
mkdir my-site; cd my-site
curl.exe -L -o drupack.exe https://github.com/tresbientech/drupack/releases/latest/download/drupack-windows-amd64.exe
```

SmartScreen shows "Windows protected your PC" the first time, because the executable carries no code signature. Choose "More info", then "Run anyway".

### Keeping Drupack on your PATH

A folder per site keeps each site with its data. To run `drupack` from anywhere instead, move the executable into a directory on your `PATH`, such as `~/.local/bin`. Site data then lands in whichever directory you start it from, so pass `--data-dir` to choose one.

## Start your site

```sh
./drupack
```

Drupack installs your site and serves it on `http://localhost:7225`. It prints a one-time login link and opens your browser on it. The link logs you in as `admin` and lands you on your dashboard. Set your own password from there, under your account. The first start of each version also unpacks its runtime, which adds about a second.

Later starts need nothing:

```sh
./drupack
```

Each one prints its own link and opens your dashboard the same way. A link works once, so `./drupack dr user:login /admin/dashboard` prints a fresh one whenever you need it. `--no-browser` starts the site without opening anything, and still prints the link.

To choose the administrator name and password yourself, for a script or a fresh machine:

```sh
./drupack --admin-user admin --admin-password 'choose-a-password'
```

Drupack still prints the login link.

Stop the site with Ctrl+C.

The terminal shows the address, where Site data lives, the log file and how to stop. Caddy's messages, PHP warnings and PHP errors go to `data/logs/caddy.log`, so they stay out of your way.

Useful options:

- `--data-dir PATH` puts Site data somewhere else. `DRUPACK_DATA_DIR` sets a default.
- `--listen IP:PORT` serves on another address, `127.0.0.1:7225` by default. Each start records its address in Site data, so `dr` reaches the site without repeating the option.
- `--site-name NAME` names the site on a first start, `Drupal Mercury Demo` by default. `DRUPACK_SITE_NAME` sets it too. A later start never renames a site.
- `--no-browser` starts without opening a browser.
- `drupack --version` prints the release, `drupack version` names every component it carries, and `drupack --help` lists every option.

[The command line reference](docs/cli.md) covers every option, including the ones this list leaves out.

## Administer with Drush

`dr` runs the bundled Drush commands against your site. Drupack's own options come before the Drush command.

```sh
./drupack dr status
./drupack dr --data-dir ./data user:login
./drupack dr pm:list --status=enabled
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

- Linux: `~/.cache/Drupack/runtime`
- macOS: `~/Library/Caches/Drupack/runtime`
- Windows: `%LOCALAPPDATA%\Drupack\runtime`

One version takes about 400 MB on Linux and macOS, beside your Site data. The space is per version, and a successful start removes the versions it replaces, so upgrading does not stack them up.

`DRUPACK_CACHE_DIR` moves the cache, for a disk with more room. On Linux and macOS a home directory that refuses writes sends the runtime to the temporary directory instead. On Windows, a cache root PHP's startup cannot read sends the runtime to the temporary directory too, printing why.

## TLS trust

Drupack carries its own trust anchors. A copy of curl's `cacert.pem` sits beside the runtime, and PHP verifies HTTPS against it. Update checks and the project browser therefore work on a machine whose own trust store is missing, empty or out of reach.

`DRUPACK_CA_FILE` names a different bundle. Drupack keeps the value you set and points PHP at your file instead of the packed copy.

Behind a proxy that re-signs TLS, set it to a bundle holding your proxy's root certificate. Update checks then verify again, with no other change.

## Use MySQL or PostgreSQL

SQLite runs your site by default, with no setup. To use a database server instead, pass its details on the first start:

```sh
./drupack --database mysql \
  --db-host 127.0.0.1 --db-name drupal \
  --db-user drupal --db-password 'database-password' \
  --admin-user admin --admin-password 'choose-a-password'
```

Use `--database pgsql` for PostgreSQL. `--db-port` defaults to `3306` for MySQL and `5432` for PostgreSQL. Later starts read the connection from Site data.

Ten options read an environment variable when the option is absent: `DRUPACK_DATA_DIR`, `DRUPACK_DATABASE`, `DRUPACK_DB_HOST`, `DRUPACK_DB_PORT`, `DRUPACK_DB_NAME`, `DRUPACK_DB_USER`, `DRUPACK_DB_PASSWORD`, `DRUPACK_ADMIN_USER`, `DRUPACK_ADMIN_PASSWORD` and `DRUPACK_SITE_NAME`. `--listen`, `--host` and `--no-browser` have none.

## Local AI agents

Your site enables Local MCP Tools, so an AI agent on your computer can administer it. This command prints a configuration for Claude Code, Claude Desktop, Cursor or Windsurf:

```sh
./drupack dr mcp-tools:client-config
```

Drupack carries MCP Server as well, disabled, with no transport exposed.

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
(Get-FileHash drupack-windows-amd64.exe -Algorithm SHA256).Hash.ToLower()
```

GitHub records where each file was built. The GitHub CLI checks that record:

```sh
gh attestation verify drupack-linux-amd64 --repo tresbientech/drupack
```

A release also carries `release.json`, listing every file with its target, URLs, SHA-256 value and size, and `drupack.cdx.json`, a CycloneDX inventory of everything inside the executable.

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md) covers building Drupack, running its test suites and the development loop.
