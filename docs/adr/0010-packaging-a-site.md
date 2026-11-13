# A build packs an existing site with its extensions and Node

## Context

A site that already exists differs from one built from a recipe. It has its own docroot, a database it already holds, and no recipe. It may need PHP extensions the engine omits, or Node for its Drush commands. The Site contract (`drupack.yml`) carries each difference as a field.

## Decision

### Existing site

- The docroot comes from `extra.drupal-scaffold.locations.web-root` in `composer.json`. The build writes it to `site.json`. A build refuses a project without it.
- `recipe` is optional. A build without one packs no Seed site.
- A first start of such a site needs `--database mysql` or `--database pgsql`. A SQLite start refuses and names both. A database that holds no site refuses too.
- `settings` names a PHP file in the site. The generated `settings.php` requires it last. The build refuses a file the site lacks.
- `--files-dir` moves the public files out of Site data. The settings keep `file_public_path` at `sites/default/files`. The Listener record keeps the directory.
- `platforms` and `libc` give the site's default targets, with the values of `--platform` and `--libc`. The defaults are `linux-amd64` and `both`. The flags and the CI inputs override the file.

### Added extensions

- `extensions` lists PHP extensions beyond `runtime/extensions/php-extensions.txt`. The engine list stays the base, so a site cannot drop a database driver.
- `build/pack/site-runtimes.sh SITE OUTPUT` merges both lists and compiles the Linux runtimes on a Docker host. `drupack-build --runtimes OUTPUT` packs the site against them.
- The script compiles for the host's architecture only. It refuses a target of another one.
- A site that adds extensions cannot name a macOS or Windows target. The published runtimes there carry the engine list alone.
- `check-extensions.py` checks the lock against the merged list.

### Bundled Node

- `node` takes `true`, a major such as `24`, or an exact `MAJOR.MINOR.PATCH`. Absent or `false` means no Node.
- `true` takes the newest LTS in nodejs.org's release index. A major takes that line's newest release. An exact version must appear in the index.
- The build records the exact version in `site.json`.
### Node verification and delivery

- The build verifies the OpenPGP signature on the release's `SHASUMS256.txt` against `launcher/internal/node/release-keys.asc`. It then checks each archive's SHA-256 against that file. Any failure stops the build.
- The keyring is an armored export of the `gpg-only-active-keys` keyring of nodejs/release-keys. A signature by an unknown releaser stops the build with a message naming that file. A fresh export overwrites it.
- A glibc Linux, macOS or Windows amd64 file carries the release beside its runtime. A musl file carries none, because Node publishes no official musl build for arm64.
- The launcher unpacks the release into the cache once per version and puts its directory first on `PATH` for the server and Drush. `clean` removes it.
- `node`, `npm` and `npx` are commands of a site that carries Node. On a musl file they exit 1 and name the glibc file.
- The engine executable carries no Node.

## Reason

A `docroot` field in `drupack.yml` would restate `composer.json`, and the two could drift. Loading the site's own `settings.php` would run the host's includes and each developer's `settings.local.php`. A named `settings` file is the one input the site author controls.

Node comes with the file because a download at first start fails offline and ends the single-download promise. Trusting the HTTPS download alone passes a tampered mirror. Signed checksums against a kept keyring stop it. Fetching the keyring during the build would trust whatever key the network returns.
