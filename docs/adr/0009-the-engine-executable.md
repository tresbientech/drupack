# The engine executable serves a project folder as it is, installs through Composer and updates itself

## Context

A developer with a Drupal project on disk, such as a ddev checkout, needs to serve it without building it first. The project's own settings must stay in charge. Its Drupack settings must read the same whether the engine serves the folder or a build packs it.

## Decision

### Serving a folder

- Each release publishes a generic `drupack` executable per platform. It carries the runtimes and the engine's files, and no application.
- `drupack [DIR]` serves the project folder DIR, the working directory by default. The docroot comes from `composer.json`'s scaffold web root, or `web` when unset.
- A start refuses a docroot without Drupal core, and a `composer.lock` that requires an extension the runtime lacks. The refusal names the missing extensions.
- The folder's own settings own the database, the files paths and the hash salt. Drupack writes nothing into the folder.
- The engine serves on `127.0.0.1:3787`. It runs no cron and has no Site data. A cache entry keyed by the folder's path holds its [lease and stop channel](0006-one-server-per-site-data.md).
- `drupack drush` runs the folder's Drush on the runtime's PHP. `drupack dr` runs Drupal core's `vendor/bin/dr`. A site executable names its Drush command `drush`.
- `drupack php` takes `SCRIPT [ARGUMENTS]` or `-r CODE`. It refuses any other option by name before PHP starts.
- Relative paths resolve against the reader's directory.

### composer.json settings

- `siteconfig.ReadComposer` is the one reader of a project's `composer.json`. It takes a mode.
- `Packed` is for a build. It refuses a missing file, a missing web root and a web root of `.`. A packed site's `settings.php` loads the engine's support files from the docroot's parent.
- `Folder` is for the engine. It takes a missing file or web root as `web`, and `.` as the project itself.
- Every other rule holds in both modes. A refusal names the file, the field, its rule and the value.
- `extra.drupack` holds what an engine start reads: `mercure`, `subdomain` and `static`. `drupack.yml` holds what only packing needs, and the build copies what a packaged start needs into `site.json`.
- A choice made for one start is an option of that start, in neither file. `--listen`, `--static-listen`, `--access-log`, `--error-log` and `--trusted-proxies` are such options.
- `drupack.yml` refuses a key that belongs in `composer.json` and names its place there.
- Composer hashes `extra` into the lock, so a change to an `extra.drupack` key needs `composer update --lock`.

### The Composer package

- `drupal/drupack` comes from the drupal.org project `drupack`. Its root `composer.json` has one bin script, `vendor/bin/drupack-install`. `.gitattributes` keeps every other file out of the package.
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

A package cannot carry five 90 MB builds, so the install script downloads one. `release.json` is host-neutral, and its checksum and asset come from one origin over TLS.
