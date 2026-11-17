# The engine executable serves a project folder as it is, installs through Composer and updates itself

## Context

A developer with a Drupal project on disk, such as a ddev checkout, needs to serve it without building it first. The project's own settings must stay in charge. Its Drupack settings must read the same whether the engine serves the folder or a build packs it.

## Decision

### Serving a folder

- Each release publishes a generic `drupack` executable per platform. It carries the runtimes and the engine's files, and no application.
- `drupack [DIR]` serves the project folder DIR, the working directory by default. The docroot comes from `composer.json`'s scaffold web root, or `web` when unset.
- A start refuses a docroot without Drupal core, and a `composer.lock` that requires an extension the runtime lacks. The refusal names the missing extensions.
- A start that finds no Drupal core in a folder with a `composer.json` and no `vendor/` asks, on a terminal, to run `drupack composer install`, then serves. `--yes` runs it without asking. Without a terminal or `--yes`, or after a no, it names that command. It checks the docroot before the lock.
- The folder's own settings own the database, the files paths and the hash salt. Drupack writes nothing into the folder, apart from the `composer install` a start runs after a yes or with `--yes`.
- The engine serves on `127.0.0.1:3787`. It runs no cron and has no Site data. A cache entry keyed by the folder's path holds its [lease and stop channel](0006-one-server-per-site-data.md).
- `drupack drush` runs the folder's Drush on the runtime's PHP. `drupack dr` runs Drupal core's `vendor/bin/dr`. A site executable names its Drush command `drush`.
- `drupack php` takes `SCRIPT [ARGUMENTS]` or `-r CODE [ARGUMENTS]`, after any `-d KEY[=VALUE]`. It refuses any other option by name before PHP starts.
- FrankenPHP's `php-cli` reads no PHP option before PHP 8.6. The runtime writes the `-d` settings to an ini file in a temporary directory for that run.
- Every child environment names the runtime's `php` as the PHP binary, which Composer's `@php` runs. On Windows it is a `php.exe`, because PHP refuses a batch file.
- A reader's `php` line, `drush`, `export` and the server also lead `PATH` with `bin/`, so a `vendor/bin` script's `#!/usr/bin/env php` runs the runtime's PHP.
- Relative paths resolve against the reader's directory. `restart` resolves them against the directory of the start it replays.
- [A 1.x release keeps](0019-the-stable-surface.md) these commands, their options and the `extra.drupack` keys.

### The composer command

- `drupack composer ARGS` runs the engine's `composer.phar` on the runtime's `php-cli`, with the environment `drupack php` gets. A site executable has no such command.
- The engine build copies the phar the job image pins, the one site builds install with, beside the engine's application files.
- The working directory holds a `composer.json`. Its `composer.lock` names a `drupal/core` release. With no lock, its `type` does not start with `drupal-`, which core's own `composer.json` and each module's carry. A folder made with `create-project --no-install` holds no lock, and its project package may require core through a profile only.
- The command refuses `-d`, `--working-dir` and a non-blank `COMPOSER`, before the options end at `--`. Each points Composer at another project than the one checked. `-d` counts inside a cluster such as `-nd`. A short option that takes a value in any Composer command reads the rest of its word, as `-s` does in `-sdev`.
- The command word is the first word with no leading dash, also after `--`, as Composer reads it.
- It refuses `global`, `self-update` and `selfupdate`, under any prefix in any case, since Composer runs a command from a unique abbreviation.
- `create-project`, from a prefix of two letters on, runs from any working directory with no check of its package. A distribution's project package, such as `farmos/project`, requires Drupal core through its profile only.
- The project's scripts and plugins run as the reader's own code.

### composer.json settings

- One reader of a project's `composer.json` takes a mode, packed for a build and folder for the engine.
- A packed build refuses a missing file, a missing web root and a web root of `.`. A packed site's `settings.php` loads the engine's support files from the docroot's parent.
- The engine takes a missing file or web root as `web`, and `.` as the project itself.
- Every other rule holds in both modes. A refusal names the file, the field, its rule and the value.
- `extra.drupack` holds what an engine start reads: `mercure`, `subdomain` and `static`. `drupack.yml` holds what only packing needs, and the build copies what a packaged start needs into `site.json`.
- A choice made for one start is an option of that start, in neither file. `--listen`, `--static-listen`, `--host`, `--access-log`, `--error-log` and `--trusted-proxies` are such options.
- `drupack.yml` refuses a key that belongs in `composer.json` and names its place there.
- Composer hashes `extra` into the lock, so a change to an `extra.drupack` key needs `composer update --lock`.

### The Composer package

- `drupal/drupack` comes from the drupal.org project `drupack`. It is a Composer plugin with one bin script, `vendor/bin/drupack-install`. `.gitattributes` keeps every other file out of the package.
- The plugin runs `drupack-install` after each `composer install` and `composer update`, in a PHP process of its own, which loads the project's autoloader. A failed run fails the Composer command.
- Composer runs a plugin only once `allow-plugins` names it, so a project trusts `drupal/drupack` once. Composer's own root scripts would take two `composer config` lines per project.
- `drupack-install` reads the package's installed version and fetches that release's `install-drupack.sh`, or the `.ps1` on Windows. It runs the script from a temporary file with the project root as the working directory.
- It adds `/drupack` to the project's `.gitignore` when the project root or a parent holds `.git`.
- A `./drupack` whose SHA-256 the release's `checksums.txt` lists is left alone. `drupack-install` never runs that file.
- A `dev-main` install is refused.

### Self-update

- `drupack self-update` reads `release.json` from GitHub's `latest` link, downloads this file's target beside it, checks the size and SHA-256, and renames it over the file. `--check` reports alone.
- A site executable, a Composer copy and a local build refuse. Composer and the site's publisher own those updates.
- A version is newer by `X.Y.Z`, then by prerelease, with digit runs compared as numbers. The command never installs an older release.
- A musl file takes the musl build. A glibc file takes the glibc build on glibc 2.28 or later, and the musl build otherwise.
- Windows moves the running file to `.old`, and a later run removes it.
- Once a day an engine run starts `self-update --check` detached. The `update-check` file in the cache root holds the newest version, and its modification time is the last check.
- Each run prints one line while that version is newer. The check stays off in CI, without a terminal, with `DRUPACK_NO_UPDATE_CHECK=1`, on a local build and on a Composer copy.

## Reason

A per-site build would still need a build. Writing an include line into the folder's `settings.php` changes a folder the reader asked to serve unchanged.

Two readers of `composer.json`, one in Go and one in PHP, drift apart. An absolute web root, a subdomain with a newline and invalid JSON are the inputs they disagree on. One reader with a mode removes the drift. The placement rule rests on three facts: the engine never reads `drupack.yml`, a plain Composer project has none, and a site behaves the same served or packed.

A fresh clone has no `vendor/`, and its host often has no PHP. The engine carries the PHP Composer needs, so it carries Composer too. A `composer.phar` beside the engine would age apart from it, and self-update moves the engine with its phar. The lock check keeps the command on Drupal projects. The type check keeps it out of core's and each module's own `composer.json`. A check of the project package would refuse each distribution whose package requires core through its profile.

A package cannot carry five 90 MB builds, so the install script downloads one. `release.json` is host-neutral, and its checksum and asset come from one origin over TLS.
