# PRD: `drupack new` creates a Drupal CMS project

Source: [ADR 0033](../adr/0033-drupack-new-creates-a-drupal-cms-project.md) and the design session of 2026-10-17.

## Problem Statement

A reader who wants to try Drupal CMS through Drupack has two choices today:

- Drupacked Demo, a site already installed from one recipe
- the Engine executable, which serves a Drupal project the reader already built with Composer

Neither reaches the Drupal CMS installer, where a reader picks a site template.
The Drupal CMS Launcher does reach it. It runs as an Electron app with its own
PHP, Composer and signing budget, and phenaproxima proposed that Drupack take
its place.

## Solution

`drupack new DIR` downloads a prebuilt Drupal CMS project, writes its settings
and serves it. The browser opens on the Drupal CMS installer. The reader picks
a template from the curated list, and the installer downloads it with the
project's own Composer. Project Browser and Package Manager work afterwards.

The folder then belongs to the reader. `drupack DIR` serves it as any Project
folder. Drupack never changes its code.

## User Stories

1. As a reader with a terminal, I want one command to create a Drupal CMS site, so that I need no PHP, Composer or Docker.
2. As a reader, I want the browser to open on the installer after `new`, so that I continue in the UI.
3. As a reader, I want to pick any template on the installer's curated list, so that I am not limited to the ones Drupack ships.
4. As a reader, I want Project Browser to install modules, so that I can extend the site without a terminal.
5. As a reader, I want Package Manager to work, so that Automatic Updates keeps the site current.
6. As a reader, I want `new` to refuse a folder that already holds files, so that it never mixes into existing work.
7. As a reader, I want a failed `new` to leave no folder behind, so that I can run it again at once.
8. As a reader, I want the download checked against its published SHA-256, so that a corrupted or replaced archive never runs.
9. As a reader behind a re-signing proxy, I want `new` to trust `DRUPACK_CA_FILE`, so that it works where `self-update` works.
10. As a reader, I want the database outside the docroot, so that no request can download it.
11. As a reader, I want a unique hash salt per site, so that two sites share no secret.
12. As a reader, I want `drupack DIR` to serve the created folder later, so that I keep working on it.
13. As a reader who left the installer unfinished, I want `drupack DIR` to print the installer address, so that I can resume it.
14. As a reader of an installed site, I want `drupack DIR` to print a login link, as for any Project folder.
15. As a reader, I want `drupack stop DIR` to stop the created site, as for any Project folder.
16. As a reader on Windows, I want `new` to work in PowerShell, so that I have the same path as on Linux and macOS.
17. As a reader on a server with no display, I want `new` to print the installer address and open nothing, so that it works over SSH.
18. As a reader, I want each new project to get the newest core security release, so that a fresh site starts patched.
19. As the maintainer, I want the archive to release from its own repository, so that a Drupal CMS lock move cuts no engine tag.
20. As the maintainer, I want the archive tagged only when its lock changes, so that idle days publish nothing.
21. As the maintainer, I want each archive served by a pinned Drupack tag before it is tagged, so that a broken archive never reaches `new`.
22. As the maintainer, I want the archive to be stock `drupal/cms`, so that the installer meets the project it expects.
23. As a Drupacked Demo reader, I want nothing to change, so that my site keeps working.
24. As an engine user serving my own project, I want starts to behave as before, so that `new` costs me nothing.

## Implementation Decisions

- D1. `new DIR` is an Engine executable word that the launcher handles before routing, as it handles `self-update`. A site executable passes the word to `launch.php` unchanged. Evidence: `launcher/main.go:47`, `launcher/route.go`.
- D2. `new` refuses with no DIR, and refuses a DIR that exists and holds any entry. Invariant: a refused `new` writes nothing.
- D3. `new` reads `release.json` from `https://github.com/tresbientech/drupal-cms-archive/releases/latest/download/release.json`, a launcher constant. The file follows the `release` schema, with one asset whose target is `any`, plus `cms` and `core` version fields. Evidence: `launcher/update.go:24`, `:38`, `:189`.
- D4. `new` downloads the asset through the `self-update` client and checks size and SHA-256 before extracting. `DRUPACK_CA_FILE` applies. Evidence: `launcher/update.go:131`, `:168`.
- D5. `new` extracts into a staging directory beside DIR with Go's `archive/tar` and `compress/gzip`. It refuses an entry with an absolute path, a `..` segment or a link leaving the staging directory. It renames staging to DIR last, and removes staging on any failure.
- D6. `new` writes `web/sites/default/settings.php` from the archive's `default.settings.php` plus one block:
  - SQLite at `db/site.sqlite` beside the docroot, written from `DRUPAL_ROOT`, since the server and Drush run from different directories
  - a hash salt of 55 random bytes, URL-safe base64
  - `config_sync_directory` at `../config`
  - Package Manager direct write and its testing flag
  - `skip_permissions_hardening`, with the installer's warning off
  - trusted hosts `127.0.0.1` and `localhost`

  It creates `db/` and `web/sites/default/files/`. Evidence: the Launcher's `settings.local.php`.
- D7. After writing settings, `new` continues as `drupack DIR`, with `DRUPACK_NEW_PROJECT=1` in the environment. `serve.php` reads and clears it. Evidence: `engine/serve.php:154`.
- D8. With `DRUPACK_NEW_PROJECT` set and a person watching, `serve.php` sets `DRUPACK_RUNTIME_OPEN` to the installer address. A person is watching under the rule a Packaged site's start applies. Evidence: `application/launch.php:206`, `runtime/entrypoint.go:309`.
- D9. A start on a project with no installed site prints `Install: URL/core/install.php` and mints no login link. A `php-cli` probe asks the project's Database API whether the `key_value` table exists, before the mint. A connection error mints as before. Drush's `bootstrap` and `db-status` are both empty for an uninstalled project and for an unreachable database alike, and `drush sql:query` needs the host's `sqlite3` or `mysql` client. Evidence: `engine/serve.php:196`, the phase 1 spike.
- D10. `serve()` puts `engine/bin` first on `PATH` before it starts the server. The installer's `ComposerExecutor` and Package Manager then run the runtime's PHP. Today only `drush` and `dr` get it. Evidence: `engine/serve.php:242`, `engine/bin/php`.
- D11. Phase 1 runs a curated template download and a Project Browser install under a served project. Every failure it finds becomes a task in the phase that owns the file.
- D12. The archive repository is `tresbientech/drupal-cms-archive`, canonical on the Forge and mirrored to GitHub. Its daily job resolves `composer create-project drupal/cms` with the scaffold's `gitignore` off. Evidence: ADR 0001, ADR 0032.
- D13. The job tags `YYYY.MM.N` only when the lock differs from the last release's. It publishes `drupal-cms-VERSION.tar.gz`, `release.json` and `checksums.txt`.
- D14. A tag follows a run that serves the archive with the pinned Drupack tag and gets 200 from `/core/install.php`. A failed run publishes nothing.
- D15. Drupack never writes into a created folder after `new`. Automatic Updates and the project's Composer own its code.
- D16. README, `docs/cli.md` and `CONTEXT.md` describe `new` and the Drupal CMS archive.

### Considered options

- A Packaged site with a seed-less first start. Its read-only project root limits the installer to built-in templates.
- A Packaged site whose whole project is a Writable directory. Its upgrade lays a release over code Drupal owns.
- A separate `drupal-cms` executable carrying the archive. A third executable and release line for one file.
- Composer run on the reader's machine. About 180 downloads per create, and the Windows antivirus and proxy failures that made the Launcher prebuild.
- The archive embedded in the engine. About 30 MB more, and an engine tag per lock move.
- Archive tags named after the cms release. Core security fixes would wait for the next cms release.
- `drupal/mercury_demo` added to the archive. The archive would stop being stock.
- `new` written in `serve.php`. The download and checksum code would exist in Go and PHP.
- Every uninstalled start opening the browser. A ddev checkout with its database down would open the installer.
- `new` printing the installer address alone. One more step than the Launcher.

## Testing Decisions

A test drives the executable or the Go function through its inputs and checks what a reader sees: files on disk, printed lines, HTTP answers. No test reads internal state.

- Go unit tests in `launcher` cover `new` against an `httptest` server: refusals, checksum mismatch, traversal entries, the settings block, distinct salts, and no DIR after a failure. Prior art: `launcher/update_test.go:32`.
- Conformance cases in `tests/conformance/engine_cases.py` cover a start on an uninstalled project and the `php` a request's child process runs. Prior art: `test_drush_child_processes_run_on_the_runtime_php_with_no_php_on_path`.
- A conformance case in `browser_cases.py` covers the browser open after `new`. Prior art: that file's open cases.
- The launcher constant stays fixed. No environment variable redirects `new` to another archive, so the end-to-end download runs in Go tests alone.
- The archive repository tests its own archive, as D14 states.

## Out of Scope

- Signing macOS and Windows executables
- A double-click that creates a site
- MySQL and PostgreSQL in `new`
- Choosing an archive version at `new`
- Updating a created project's code
- Any change to Packaged sites or Drupacked Demo

## Further Notes

The archive repository is a new remote repository. Creating it, and its first tag, are owner steps.
