# Plan: An engine executable serves a project folder

> Decision: `docs/adr/0022-engine-executable.md`.

## Status, 2026-09-26

All three phases are built on branch `engine-executable`, which is not
merged or pushed. `bash build/qa.sh` passes on linux-amd64, with the engine
case run against Mercury Demo's application. The release workflow, the macOS
build and the Windows build are edited and have not run: their criteria wait
on a CI run.

Notes:

- The Mercury Demo executable is renamed `mercury-demo`, since the demo held
  the name `drupack`. ADR 0022 records it.
- The launcher sets no application directory for the engine executable, so
  the runtime keeps the reader's working directory. A start execs Caddy's
  `run` on `engine/Caddyfile`, which skips the packaged site's readiness line
  and cron runner.
- Composer's own platform check tests the PHP version alone by default. The
  extension check reads `composer.lock`, as the build's check does.
- `drush` finds the nearest `vendor/autoload.php`, since Drupal core carries a
  `composer.json` of its own.
- `php` takes a script or `-r` code. FrankenPHP's `php-cli` accepts no PHP
  option such as `-v`.
- The request guards moved to `application/guards.caddy`, which both
  Caddyfiles import. `engine/` links to it and to `application/process.php`.
- The engine case skips by name unless `DRUPACK_TEST_ENGINE` names the engine
  executable. It serves a copy of the site's own application.

## Architectural decisions

These hold across all phases:

- The Mercury Demo executable is renamed `mercury-demo`, and the engine
  executable takes the name `drupack`, as the owner chose during phase 1.
- The engine executable is the launcher packed with the runtimes, the engine's
  files and no application. Its name is `drupack`, which also keys its runtime
  cache.
- A Linux engine executable carries both the glibc and musl runtimes.
- `drupack [DIR]` serves, and the words are `drush ...` and `php ...`. A start takes
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

The pack step builds an engine executable with no application. A start
resolves the folder and reads its docroot. It checks the folder's
`composer.lock` against the runtime's PHP version and extensions. It then
starts FrankenPHP on the folder and prints a one-time login link.

### Acceptance criteria

- [x] An engine executable builds for linux-amd64 in the job image.
- [x] A start on a minimal Drupal project with SQLite settings returns 200 on `/`.
- [x] A start with no argument serves the working directory.
- [x] A start refuses a folder without Drupal core, such as a WordPress site.
- [x] A scaffold web root other than `web` is resolved, in the unit cases.
- [x] A folder requiring a missing extension is refused, and the message names it.
- [x] The start prints a one-time login link that signs in.
- [x] After the run the folder changed only under its public files directory.

---

## Phase 2: Drush and PHP on the runtime

### What to build

`drush` runs the folder's `vendor/bin/drush` on the runtime's PHP. A `php` on
PATH forwards to the same PHP, so Drush's child processes never reach the
host's PHP. `php` runs the runtime's PHP with the reader's arguments.

### Acceptance criteria

- [x] `drush status` reports the folder's database and Drupal version.
- [x] A `php` that Drush starts runs the runtime's PHP, with no PHP on the host PATH.
- [x] `php -r` prints the runtime's version. `php -v` is not accepted.
- [x] A folder with no Drush gets a message that names the missing path.

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
- [x] CONTEXT.md defines Engine executable and Project folder.
- [x] The CLI doc covers the start, `drush` and `php` of the engine executable.
