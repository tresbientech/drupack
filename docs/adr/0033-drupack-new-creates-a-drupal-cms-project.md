# `drupack new` creates a Drupal CMS project

Accepted on 2026-10-17.

## Context

The Drupal CMS Launcher (`drupal/cms-launcher` v1.0.9) is an Electron app. It
bundles PHP 8.4 and Composer, and its Drupal Association budget pays for macOS
and Windows signing. On first run it does four things:

1. It extracts a prebuilt `create-project drupal/cms` codebase into the
   system's app data folder.
2. It writes `settings.php` with SQLite, a hash salt and Package Manager direct
   write.
3. It serves the folder with `php -S` and opens the browser on the Drupal CMS
   installer.
4. The reader picks a site template there. The folder is writable, so the
   installer downloads it with the project's own Composer.

phenaproxima proposed that Drupack drop a reader into that installer and
replace the Launcher. A Packaged site cannot: its application is read-only and
its first start uninstalls `package_manager`. With a read-only project root,
`drupal_cms_installer` lists only templates already on disk.

The Engine executable already serves a writable Project folder unchanged
([ADR 0022](0022-engine-executable.md)). drupal.org's `cms` release is an
11 KB `composer.json` with no `vendor`, so no prebuilt codebase exists.

## Decision

- `drupack new DIR` creates a Project folder from a Drupal CMS archive, then
  serves it. It refuses a DIR that exists and is not empty.
- The archive is a stock `composer create-project drupal/cms`, one file for
  every platform. Its own repository, `tresbientech/drupal-cms-archive`,
  publishes it. The Forge is canonical, and GitHub mirrors it.
- That repository's daily job resolves `create-project drupal/cms` and tags
  when the lock differs from the last release. Tags are `YYYY.MM.N`, as the
  demo's are. `release.json` names the archive's cms and core versions.
- A tag follows a run that serves the archive with a pinned Drupack tag.
- `drupack new` reads `release.json` from the `latest` release, downloads the
  archive and checks its size and SHA-256, as `self-update` does.
- `new` writes `web/sites/default/settings.php` once:
  - SQLite at `db/site.sqlite`, outside the docroot
  - a fresh hash salt
  - `config_sync_directory` at `../config`
  - Package Manager direct write
  - the installer's hardening warning turned off
- After `new`, serving writes nothing into the folder, as before.
- A start on a project with no installed site opens `/core/install.php` and
  mints no login link.
- Drupack never updates a created codebase. Automatic Updates and the
  project's Composer own it.
- The command line is the only entry point. A double-click keeps its current
  behaviour.
- Signing stays out of scope.

## Considered options

- A Packaged site from a new Site repository, with a seed-less first start.
  The installer could then offer only templates built in, and Project Browser
  could add nothing.
- A Packaged site whose whole project is a Writable directory. Its upgrade
  lays a new release over a codebase that Drupal owns.
- A separate `drupal-cms` executable carrying the archive. A third executable
  and release line for one embedded file.
- Composer run on the reader's machine at `new`. About 180 package downloads,
  and the Windows antivirus and proxy failures that made the Launcher prebuild.
- The archive embedded in the engine. About 30 MB more, and an engine tag per
  lock move, the coupling [ADR 0032](0032-drupacked-demo-own-repository.md)
  removed.
- Archive tags named after the cms release. A core security release would
  reach new projects only with the next cms release.
- `drupal/mercury_demo` added to the archive. The archive would differ from
  the stock project the installer expects.
- A double-click that creates and serves a per-user default site. Deferred
  with signing, since both serve readers without a terminal.

## Consequences

- A reader with a terminal gets the Launcher's result: the installer, the
  curated template list, Project Browser and Package Manager.
- A reader without a terminal still has no Drupack path. The Launcher's
  signing budget pays for that path, and this decision does not use it.
- `new` and template downloads need the network.
- A third repository releases daily and carries its own bumper.
- The installer and Package Manager run the project's Composer through the
  served PHP. The first phase checks that path before anything else ships.
