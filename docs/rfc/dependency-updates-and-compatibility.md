# Dependency updates and Site data compatibility

Proposed on 2026-09-18. Not implemented.

## Question

Drupack bundles Drupal core, Drupal CMS with the Mercury Demo site template, contributed modules, PHP, FrankenPHP and Caddy. Each moves on its own schedule, and a site owner runs whatever their executable carries. How does a new release happen, what is it called, and what protects Site data when two executables meet it?

## Release triggers

A security release in Drupal core, PHP, or a bundled contributed module starts a Drupack release within a few days. One refresh a month picks up everything else. A month with nothing new ships nothing.

## Watching

A Forge Actions workflow runs daily. It runs `composer audit` against the lock, then checks for a newer Drupal core, PHP, FrankenPHP and Caddy release. It fails when any of them moved, and the run log names what changed. A failed scheduled run in the Forge's Actions tab is the notification. It files nothing on a remote forge, which [ADR 0001](../adr/0001-forge-canonical-github-packaging-mirror.md) keeps free of issues and pull requests.

## Naming

Drupack keeps its own version. Tags stay `0.2.0`, `0.3.0`, without a `v` prefix.

Every release states what it carries:

- `drupack --version` prints `Drupack 0.2.0 (Drupal 11.4.8, Drupal CMS with Mercury Demo, PHP 8.5.10, FrankenPHP 1.12.7, Caddy 2.11.4)`
- `release.json` carries the same values
- the release notes lead with the Drupal version

## Version record

Site data holds a version record: the Drupack and Drupal versions that last served it. A first start writes it, and every later start rewrites it.

Each release pins every component, so the Drupack version orders everything inside it. The guard compares that version:

- A release build older than the record refuses to serve. Its message names both versions and where the newer executable lives.
- A release build newer than the record refuses to serve until `drupack update` has run, and its message names that command.
- A `dev-` build carries no order. It refuses to open Site data written by a release build, and names the flag that overrides it. Two development builds warn and continue.
- `--force` overrides the refusal for someone who knows why.

Site data written before this exists carries no record. The first start that finds none writes one and continues.

## The update command

`drupack update` applies Drupal's database updates, rebuilds caches, prints what changed, then writes the new version record. It imports no configuration: the configuration directory holds the owner's own exports, and importing them could revert their site.

Before touching anything it prints the Site data directory and says to copy it first. Drupal's own guidance is to back up before an update, and Drupack cannot take that backup for a remote MySQL or PostgreSQL database.

## Replacing a running executable

Replacing the file under a running server works on every target: Linux and macOS keep the old copy mapped, Windows allows the rename. The running process keeps serving the old code either way, because neither Caddy nor FrankenPHP passes a listening socket to a new binary.

A database update also has to run with the site quiet. So the sequence stays: stop the site, replace the executable, run `drupack update`, start it again. A later release may print those commands when it finds a newer executable beside it. Drupack never swaps itself while serving.

## Update check

Drupack checks the GitHub releases feed at most once a day, in the background. A newer release prints one line on start and adds an entry to Drupal's status report. It downloads nothing and replaces nothing.

`--no-update-check` and an environment variable turn it off. A failed check stays silent, so `tests/offline.sh` keeps passing. The check stays off by default until its privacy line is decided, because it contacts GitHub from a site owner's machine.

## Acceptance checks

- A build older than the version record refuses to serve, and names both versions.
- A build newer than the record refuses to serve until `drupack update` runs, and names that command.
- `drupack update` applies database updates, rebuilds caches, and writes the new record.
- Site data with no record gains one on the next start, and the site serves.
- `--force` opens Site data in either direction.
- A development build refuses Site data from a release build.
- The daily watch job fails when a bundled component has a newer release.
- `drupack --version` names every bundled component and its version.
- The update check makes no network call when it is off, and `tests/offline.sh` passes with it on.

## Open questions

- Whether the update check ships on by default, which decides whether a first start contacts GitHub.
- Whether contributed module schema updates inside one core version deserve their own comparison, or whether the Drupack version covers them.
