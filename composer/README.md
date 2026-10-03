# Drupack

Drupack serves a Drupal project you already have from one executable. The executable carries PHP, FrankenPHP and Caddy. It uses the project's own settings, database and Drush.

This package installs the `drupack` executable into a Composer project.

## Install

```sh
composer require --dev 'drupal/drupack:^1.0@alpha'
vendor/bin/drupack-install
./drupack
```

`drupack-install` downloads the `drupack` release matching the package's version into the project root, and checks its SHA-256. It adds `/drupack` to the project's `.gitignore`, and creates that file in a git project without one. On Windows the executable is `drupack.exe`.

Run `vendor/bin/drupack-install` again after `composer update` changes the package's version. A dev branch of the package names no release, so `drupack-install` refuses it.

## Serve the project

`./drupack` serves the project in the working directory, or in the directory you name after it. It refuses a folder without Drupal core. It reads the docroot from the scaffold web root in `composer.json`, `web` by default.

The start prints a one-time login link through the project's Drush. It returns the prompt once the project answers on `http://127.0.0.1:8888`, and keeps serving in the background. A project without Drush serves with no link.

- `./drupack stop` ends it.
- `--foreground` serves in the terminal instead.
- `--listen IP:PORT` picks another address.
- `--version` names the release and the FrankenPHP, PHP, Caddy and SQLite it carries.

The project's own `settings.php` names the database, the files paths and the hash salt. Drupack writes nothing into the project. For a ddev project, point `settings.local.php` at the database's published port on `127.0.0.1`.

## Drush and PHP

- `./drupack drush COMMAND` runs the project's own Drush on the bundled PHP, from any directory inside the project.
- `./drupack dr COMMAND` runs Drupal core's command line the same way, on Drupal 11.4 and later.
- `./drupack php SCRIPT [ARGUMENTS]` and `./drupack php -r CODE` run the bundled PHP.

A project whose `composer.lock` needs a PHP extension the bundled PHP lacks is refused. The message names the extension and the packages that need it.

## Linux builds

A Linux release publishes a glibc build and a musl build per processor. `drupack-install` installs the glibc build when the host has the glibc loader, and the musl build otherwise. `DRUPACK_LIBC` set to `glibc` or `musl` overrides that pick:

```sh
DRUPACK_LIBC=musl vendor/bin/drupack-install
```

## The unpacked runtime

The first start of a version unpacks PHP and Caddy into a cache directory. Later starts of that version reuse it.

- Linux: `~/.cache/drupack/runtime`
- macOS: `~/Library/Caches/drupack/runtime`
- Windows: `%LOCALAPPDATA%\drupack\runtime`

`DRUPACK_CACHE_DIR` moves the cache. `DRUPACK_CA_FILE` names a CA bundle for PHP's HTTPS requests, such as one holding a re-signing proxy's root certificate.

## More

The [Drupack repository](https://github.com/tresbientech/drupack) covers installing without Composer, verifying a download and building an executable of your own site.
