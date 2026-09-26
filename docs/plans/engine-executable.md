# Plan: An engine executable serves a project folder

> Decision: `docs/adr/0022-engine-executable.md`.

## Architectural decisions

These hold across all phases:

- The engine executable is the launcher packed with the runtimes, the engine's
  files and no application. Its name is `drupack`, which also keys its runtime
  cache.
- A Linux engine executable carries both the glibc and musl runtimes.
- The words are `serve [DIR]`, `dr ...` and `php ...`. `serve` takes
  `--listen`, default `127.0.0.1:8888`.
- The folder mode has its own PHP entry script. Helpers it shares with
  `launch.php` move to one file both load.
- The folder's settings are read as they are. Drupack writes nothing into the
  folder, and Drupal writes only where those settings point.
- Caddy serves static files from the folder's docroot and sends the rest to
  Drupal's front controller.

---

## Phase 1: Serve a folder on Linux

### What to build

The pack step builds an engine executable with no application. `serve`
resolves the folder and reads its docroot. It checks the folder's
`composer.lock` against the runtime's PHP version and extensions. It then
starts FrankenPHP on the folder and prints a one-time login link.

### Acceptance criteria

- [ ] An engine executable builds for linux-amd64 in the job image.
- [ ] `serve` on a minimal Drupal project with SQLite settings returns 200 on `/`.
- [ ] `serve` with no argument serves the working directory.
- [ ] A scaffold web root other than `web` is served.
- [ ] A folder requiring a missing extension is refused, and the message names it.
- [ ] The start prints a one-time login link that signs in.
- [ ] After the run the folder changed only under its public files directory.

---

## Phase 2: Drush and PHP on the runtime

### What to build

`dr` runs the folder's `vendor/bin/drush` on the runtime's PHP. A `php` on
PATH forwards to the same PHP, so Drush's child processes never reach the
host's PHP. `php` runs the runtime's PHP with the reader's arguments.

### Acceptance criteria

- [ ] `dr status` reports the folder's database and Drupal version.
- [ ] `dr updatedb` runs its child Drush process on the runtime's PHP, with no PHP on the host PATH.
- [ ] `php -v` prints the runtime's version.
- [ ] A folder with no Drush gets a message that names the missing path.

---

## Phase 3: Release on every platform

### What to build

The release workflow packs an engine executable for each of its five targets.
It runs the folder case on each runner and publishes `drupack-<platform>`
assets beside the site's. The docs gain the engine executable and its words.

### Acceptance criteria

- [ ] A tag release lists `drupack-<platform>` for linux-amd64, linux-arm64, macos-arm64, macos-amd64 and windows-amd64.
- [ ] The folder case passes on each runner.
- [ ] A Windows run serves a folder given as a drive letter path.
- [ ] CONTEXT.md defines Engine executable and Project folder.
- [ ] The CLI doc covers `serve`, `dr` and `php` in folder mode.
