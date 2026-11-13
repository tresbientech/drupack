# Drupack

Drupack runs Drupal from a single executable that carries PHP, FrankenPHP and Caddy. It does two things:

- `drupack` serves a Drupal project you already have, with the project's own settings, database and Drush.
- A build packs your Composer project into an executable of its own, which installs and serves the site with nothing else on the machine. [Build your own site](docs/build-your-site.md) covers it.

## Serve a Drupal project you already have

Install `drupack` in the project's root on Linux or macOS, then start it there:

```sh
cd path/to/your/project
curl -fsSL https://drupack.tresbien.tech/install.sh | sh
./drupack
```

On Windows, in PowerShell:

```powershell
cd path\to\your\project
irm https://drupack.tresbien.tech/install.ps1 | iex
.\drupack.exe
```

The script downloads the build for your system into the current directory and checks its SHA-256. Each project keeps its own `drupack`, so each one stays on the version it installed.

A Composer project can pin `drupack` instead:

```sh
composer require --dev drupal/drupack
vendor/bin/drupack-install
./drupack
```

`drupack` serves the project in the working directory on `http://127.0.0.1:3787` and prints a one-time login link. It keeps serving in the background, and `drupack stop` ends it. The project's `settings.php` names the database and the files paths, and Drupack writes nothing into the project.

- `drupack composer install` installs a fresh clone with the Composer `drupack` carries.
- `drupack drush COMMAND` runs the project's own Drush on the bundled PHP.
- `drupack php SCRIPT` runs the bundled PHP.
- `drupack self-update` installs the newest release.

[The engine executable](docs/cli.md#the-engine-executable) lists every command and option.

## Linux

A Linux release publishes two builds per processor, one for each C library. The install script takes the glibc build on a host with glibc 2.28 or later, and the musl build on any other host, Alpine included. `DRUPACK_LIBC` set to `glibc` or `musl` overrides the pick:

```sh
curl -fsSL https://drupack.tresbien.tech/install.sh | DRUPACK_LIBC=musl sh
```

## Downloading by hand

The [releases page](https://github.com/tresbientech/drupack/releases) lists every build as `drupack-VERSION-TARGET`. A Linux target without a suffix names the glibc build, and `-musl` the musl one. A browser marks what it downloads, and the install scripts avoid both marks below.

- macOS Gatekeeper blocks a marked executable that carries no Apple signature. `xattr -d com.apple.quarantine drupack` clears the mark.
- Windows SmartScreen shows "Windows protected your PC" the first time. Choose "More info", then "Run anyway".

## Build your own site

A site repository holds a Composer project and a `drupack.yml`. A build on GitHub, Gitea, GitLab or any CI that runs a container turns it into one executable per platform, for Linux, macOS and Windows. A first start of that executable installs the site, and its data stays in one directory beside it.

[Build your own site](docs/build-your-site.md) has the setup. The [Drupacked Demo](https://github.com/tresbientech/drupacked-demo) is a complete site built this way.

## Read more

[The command line reference](docs/cli.md) covers:

- every option, command and environment variable of `drupack` and of a built site
- [HTTPS](docs/cli.md#https) and [serving other computers](docs/cli.md#the-listener)
- [the static export](docs/cli.md#static-site) and [the Mercure hub](docs/cli.md#mercure-hub)
- [the cache](docs/cli.md#the-cache) a release unpacks into

## Verify a download

The install scripts check each download's SHA-256. For a file downloaded by hand, each release publishes `checksums.txt`:

```sh
sha256sum --ignore-missing -c checksums.txt
```

On Windows, compare the file's hash against its entry in `checksums.txt`:

```powershell
(Get-FileHash drupack-VERSION-windows-amd64.exe -Algorithm SHA256).Hash.ToLower()
```

GitHub records where each file was built, and the GitHub CLI checks that record:

```sh
gh attestation verify drupack-VERSION-linux-amd64 --repo tresbientech/drupack
```

A release also carries `release.json`, which lists every executable with its SHA-256 and size, and a CycloneDX inventory of what the executable holds.

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md) covers the build, the test suites and the release.
