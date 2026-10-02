# Drupack

Drupack runs Drupal from a single executable. Each release publishes two:

- `drupack` serves a Drupal project you already have, with its own settings.
- `drupacked-demo` is a Drupal CMS site built on the Mercury Demo recipe, with WordPal and Agent Access. It carries Drupal CMS, PHP, Caddy, SQLite, MySQL and PostgreSQL drivers and Drush, and needs nothing else to serve a site.

The first section covers `drupack`. Every later section covers the Drupacked Demo.

## Serve a Drupal project you already have

Install `drupack` on Linux or macOS:

```sh
curl -fsSL https://drupack.tresbien.tech/install.sh | sh
mkdir -p ~/.local/bin && mv drupack ~/.local/bin/
cd path/to/your/project
drupack
```

On Windows, in PowerShell:

```powershell
irm https://drupack.tresbien.tech/install.ps1 | iex
```

The script downloads the build for your system into the current directory and checks its SHA-256. It then prints the command that moves it onto your `PATH`.

In a Composer project, pin `drupack` to the project instead:

```sh
composer require --dev drupal/drupack
vendor/bin/drupack-install
./drupack
```

`drupack-install` runs the install script of the release matching the package's version, in the project root. `DRUPACK_LIBC` reaches that script, as the Linux section below describes. It adds `/drupack` to the project's `.gitignore`, and creates that file in a git project without one. Run it again after `composer update` changes the package's version.

`drupack` serves the project in the working directory, or in the directory you name after it. It serves Drupal alone, and refuses a folder without Drupal core. It reads the docroot from the scaffold web root in `composer.json`, `web` by default. It prints a one-time login link through the project's Drush, returns the prompt once the project answers on `http://127.0.0.1:8888`, and keeps serving in the background. `drupack stop` ends it, and `--foreground` serves in the terminal instead. A project without Drush serves with no link. `--listen IP:PORT` picks another address.

The project's own `settings.php` names the database, the files paths and the hash salt. Drupack writes nothing into the project, and Drupal writes files where those settings put them. For a ddev project, point `settings.local.php` at the database's published port on `127.0.0.1`. The ddev host name `db` resolves only inside ddev.

`drupack drush COMMAND` runs the project's own Drush on the bundled PHP, from any directory inside the project. Drush's own child processes run on that PHP too. `drupack dr COMMAND` runs Drupal core's own command line the same way, on Drupal 11.4 and later. `drupack php SCRIPT [ARGUMENTS]` and `drupack php -r CODE` run the bundled PHP. Any other PHP option stops with a message naming it.

A project whose `composer.lock` needs a PHP extension the bundled PHP lacks is refused, with the extension and the packages that need it.

## Install the Drupacked Demo

Make a folder for your site and install the Drupacked Demo into it. Site data lives in `data` inside the folder you start it from.

On Linux or macOS:

```sh
mkdir my-site && cd my-site
curl -fsSL https://drupack.tresbien.tech/demo.sh | sh
```

On Windows, in PowerShell:

```powershell
mkdir my-site; cd my-site
irm https://drupack.tresbien.tech/demo.ps1 | iex
```

The script picks the build for your system and processor, and checks its SHA-256. Each short URL redirects to the latest release's `install-drupacked-demo.sh` or `install-drupack.sh` on GitHub. `https://github.com/tresbientech/drupack/releases/download/VERSION/install-drupacked-demo.sh` installs one release instead.

### Linux

A Linux release publishes two builds per processor. `linux-amd64` carries a
runtime built against glibc, and `linux-amd64-musl` one built against musl. The
script checks the host for the glibc loader and installs the matching build.

| Your host | Build it installs | Why |
|---|---|---|
| glibc, which covers Debian, Ubuntu, Fedora, RHEL and Arch | glibc | serves a rendered page faster |
| musl, which covers Alpine and most slim containers | musl | the only one that runs there |
| anything without the glibc loader | musl | runs on any host |

An Alpine image made on a glibc host needs the musl build. `DRUPACK_LIBC` set
to `musl` or `glibc` overrides the script's pick:

```sh
curl -fsSL https://drupack.tresbien.tech/demo.sh | DRUPACK_LIBC=musl sh
```

The script refuses any other value. `drupacked-demo --version` names the C
library of the build you run.

### Downloading by hand

The [releases page](https://github.com/tresbientech/drupack/releases) lists every build as `NAME-VERSION-TARGET`, such as `drupacked-demo-0.5.1-macos-arm64`. A Linux target without a suffix names the glibc build, and `-musl` the musl one. A browser marks what it downloads, and the scripts avoid both marks below.

- macOS Gatekeeper blocks a marked executable that carries no Apple signature. `xattr -d com.apple.quarantine drupacked-demo` clears the mark.
- Windows SmartScreen shows "Windows protected your PC" the first time. Choose "More info", then "Run anyway".

### Keeping the Drupacked Demo on your PATH

A folder per site keeps each site with its data. To run `drupacked-demo` from anywhere instead, move the executable into a directory on your `PATH`, as the script's last lines show. Site data then lands in whichever directory you start it from, so pass `--data-dir` to choose one.

## Start your site

```sh
./drupacked-demo
```

Drupack installs your site and serves it on `http://localhost:7225`. It prints a one-time login link and opens your browser on it. The link logs you in as `admin` and lands you on your dashboard. Set your own password from there, under your account. The first start of each version also unpacks its runtime, which adds about a second.

Later starts need nothing:

```sh
./drupacked-demo
```

Each one prints its own link and opens your dashboard the same way. A link works once, so `./drupacked-demo drush user:login /admin/dashboard` prints a fresh one whenever you need it. `--no-browser` starts the site without opening anything, and still prints the link.

To choose the administrator name and password yourself, for a script or a fresh machine:

```sh
./drupacked-demo --admin-user admin --admin-password 'choose-a-password'
```

Drupack still prints the login link.

A start returns your prompt once the site answers, and the site keeps serving in the background, so Drush runs in the same terminal. Stop the site with:

```sh
./drupacked-demo stop
```

`--foreground` serves in the terminal until Ctrl+C instead, for a container or a systemd unit.

The terminal shows the address, where Site data lives, the log files and how to stop. What a detached start's server prints later goes to `data/logs/server.log`. A `--foreground` start prints it to the terminal. Caddy's messages, PHP warnings and PHP errors go to `data/logs/caddy.log`, so they stay out of your way.

Useful options:

- `--data-dir PATH` puts Site data somewhere else. `DRUPACK_DATA_DIR` sets a default.
- `--listen IP:PORT` serves on another address, `127.0.0.1:7225` by default. Each start records its address in Site data, so `drush` reaches the site without repeating the option.
- `--host HOST` names the address readers type, `localhost` by default. An IPv6 address works too, such as `--host ::1`.
- `--site-name NAME` names the site on a first start, `Drupacked Demo` by default. `DRUPACK_SITE_NAME` sets it too. A later start never renames a site.
- `--no-browser` starts without opening a browser.
- `drupacked-demo --version` prints the release and the release of each component it carries: FrankenPHP, PHP, Caddy, SQLite, Node, Drupal core and Drush. `drupacked-demo --help` lists every option.

[The command line reference](docs/cli.md) covers every option, including the ones this list leaves out.

### Serving other computers

The site speaks plain HTTP. A listener beyond loopback, such as `--listen 0.0.0.0:7225`, sends the login link, the password and the session cookie over the network unencrypted. Put a TLS proxy in front of the site before anyone signs in from another computer. On such a listener a start prints the login link and opens no browser.

## Administer with Drush

`drush` runs the bundled Drush commands against your site. Drupack's own options come before the Drush command.

```sh
./drupacked-demo drush status
./drupacked-demo drush --data-dir ./data user:login
./drupacked-demo drush pm:list --status=enabled
```

## Convert a WordPress theme

The Drupacked Demo carries WordPal, which converts a WordPress block theme into a Drupal theme and Canvas pages. Convert a theme, then start the site:

```sh
./drupacked-demo drush wpc twentytwentyfour --target=canvas
./drupacked-demo
```

The argument is a wordpress.org theme slug, a path to a theme zip, or an https URL to one. The next start serves the converted theme as the default theme.

The theme lands in `web/themes/custom` and its recipe in `recipes`. Both sit in the site's own copy of the application, in Site data.

Conversion runs WordPress in WordPress Playground, so it needs network access to wordpress.org and npm. It runs on the Node the demo carries, and the host needs none. On Linux it needs the glibc build, because the musl build carries no Node.

## Site data

Site data lives in the `data` directory. It holds your database, uploads, private files, configuration exports and generated settings. Copy that directory to back your site up, with the site stopped. Keep the copy private: it holds your content and your site's secrets.

A newer Drupack keeps working with existing Site data, which every release is tested for.

One start at a time prepares a site, and one server at a time serves it, whatever port a second start asks for. A second start of a site that already serves opens your browser on it, then stops, so a double-click always lands you on your dashboard. A second start with no terminal, and a start whose port another program holds, stop with a message naming the port. Drush keeps working while a site serves.

Drupack refuses to serve in four cases, each naming the directory and the command to run:

- an interrupted setup left the administrator account unset, so the site would still accept the published seed password
- a site exists that Drupal cannot start
- the database already holds a site that Drupack did not install
- a setup cut off after it began installing into an empty database finds tables there now

An interrupted setup resumes where it stopped. Drupack never installs Drupal over a database that already holds a site, and never copies its starting site over an existing one. A setup cut off after it began installing into a database it found empty never counts as a site: the next start names that database and installs once it is empty again.

## The unpacked runtime

Your download carries Drupal, PHP and Caddy compressed. The first start of a version unpacks them into a cache directory, which takes about a second. Every later start of that version uses what is already there. `drupacked-demo` also lays its application in Site data on the first start of each version, since WordPal writes themes and recipes into it.

- Linux: `~/.cache/drupacked-demo/runtime`
- macOS: `~/Library/Caches/drupacked-demo/runtime`
- Windows: `%LOCALAPPDATA%\drupacked-demo\runtime`

`drupack` unpacks the same way, under `drupack` in place of `drupacked-demo`.

Releases up to 0.4.0 named the demo executable `drupack`, and unpacked it under `drupack`. `drupack clean` removes the applications those releases unpacked there. Releases up to 0.2.0 unpacked into `~/.cache/Drupack/runtime` on Linux. Nothing reads that directory any more, so delete it after upgrading.

Releases 0.5.0 to 0.8.0 named it `mercury-demo`, and unpacked it under `mercury-demo`. `drupacked-demo` starts their Site data. Nothing reads their cache directory any more, so delete it after upgrading.

One version takes about 400 MB on Linux and macOS, beside your Site data. The space is per version, and a successful start removes the versions it replaces, so upgrading does not stack them up.

`DRUPACK_CACHE_DIR` moves the cache, for a disk with more room. On Linux and macOS a home directory that refuses writes sends the runtime to the temporary directory instead. On Windows, a cache root PHP's startup cannot read sends the runtime to the temporary directory too, printing why.

The cache holds code the executable runs, so no other account may write to it, apart from administrators and the system. A cache directory the executable creates is made private. An existing one that another account can write to stops the start.

## TLS trust

Drupack carries its own trust anchors. A copy of curl's `cacert.pem` sits beside the runtime, and PHP verifies HTTPS against it. Update checks and the project browser therefore work on a machine whose own trust store is missing, empty or out of reach.

`DRUPACK_CA_FILE` names a different bundle. Drupack keeps the value you set and points PHP at your file instead of the packed copy.

Behind a proxy that re-signs TLS, set it to a bundle holding your proxy's root certificate. Update checks then verify again, with no other change.

## Use MySQL or PostgreSQL

SQLite runs your site by default, with no setup. To use a database server instead, pass its details on the first start:

```sh
./drupacked-demo --database mysql \
  --db-host 127.0.0.1 --db-name drupal \
  --db-user drupal --db-password 'database-password' \
  --admin-user admin --admin-password 'choose-a-password'
```

Use `--database pgsql` for PostgreSQL. `--db-port` defaults to `3306` for MySQL and `5432` for PostgreSQL. Later starts read the connection from Site data.

Ten options read an environment variable when the option is absent: `DRUPACK_DATA_DIR`, `DRUPACK_DATABASE`, `DRUPACK_DB_HOST`, `DRUPACK_DB_PORT`, `DRUPACK_DB_NAME`, `DRUPACK_DB_USER`, `DRUPACK_DB_PASSWORD`, `DRUPACK_ADMIN_USER`, `DRUPACK_ADMIN_PASSWORD` and `DRUPACK_SITE_NAME`. `--listen`, `--host` and `--no-browser` have none.

## Hosting

A server with 512 MB of memory runs the demo site. The site uses about 170 MB. Your own modules and content can use more.

## Connect an AI agent

The Drupacked Demo applies [Agent Access](https://www.drupal.org/project/agent_access), which serves an MCP endpoint behind OAuth. Add this address to an agent that runs on your machine:

```text
http://localhost:7225/mcp
```

The agent registers itself, then sends you to the site to sign in and approve it. Sign in as the administrator. The agent then acts with that account's permissions.

Each site generates its own OAuth key pair on its first served request, and keeps it in Site data under `oauth-keys`.

The demo serves plain HTTP. OAuth allows it on a loopback address, and some agents refuse it anyway. A hosted connector, such as the ones in claude.ai, calls from its vendor's servers and cannot reach `localhost`.

## Updates

Drupal core security fixes reach you through a new Drupack release, because a packaged executable cannot update its own Drupal in place. Run the install script again in your site's folder, and start it.

A site installed before `drupacked-demo` keeps its own configuration, so WordPal and Agent Access stay off. Turn them on from the site's folder:

```sh
./drupacked-demo drush pm:install -y wordpal_canvas
./drupacked-demo drush recipe "$PWD/data/app/recipes/agent_access"
```

Your site ships without `automatic_updates` and `package_manager` enabled. `drupal/automatic_updates` 4.1.0 stalls a request for four minutes when cron runs, and its development branch carries the same code, so enabling either module brings that stall back.

Drupack runs Drupal's cron itself, in a separate process, a couple of minutes after your site answers and every three hours it keeps serving. The `automated_cron` module stays installed, as Drupal CMS installs it, with its interval set to 0 so it runs nothing at the end of a page request. A reader never waits on a queue or on a fetch that cannot reach drupal.org.

[The backlog](docs/backlog.md) records how releases will follow upstream security fixes, and how a newer executable will guide you through a database update.

## Verify a download

The install scripts check each download's SHA-256. For a file downloaded by hand, each release publishes `checksums.txt`:

```sh
sha256sum --ignore-missing -c checksums.txt
```

On Windows, compare your file against the entry in `checksums.txt`:

```powershell
(Get-FileHash drupacked-demo-0.5.1-windows-amd64.exe -Algorithm SHA256).Hash.ToLower()
```

GitHub records where each file was built. The GitHub CLI checks that record:

```sh
gh attestation verify drupack-0.5.1-linux-amd64 --repo tresbientech/drupack
```

A release also carries `release.json`, listing every executable with its target, URL, SHA-256 value and size, and `drupack.cdx.json`, a CycloneDX inventory of everything inside the executable.

## Build your own site

Drupack builds a Linux executable of any Drupal site from its Composer project and a recipe, on GitHub, Gitea or any CI that runs a container. [docs/build-your-site.md](docs/build-your-site.md) has the setup for each.

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md) covers building Drupack, running its test suites and the development loop.
