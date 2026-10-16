# Plan: `drupack new` and the Drupal CMS archive

> Source: [ADR 0033](../adr/0033-drupack-new-creates-a-drupal-cms-project.md), from the design session of 2026-10-17.

## Architectural decisions

- The Drupal CMS archive releases from `tresbientech/drupal-cms-archive`. This repository never publishes it.
- The archive repository pins a Drupack tag for its tests, as the demo repository does.
- `new` is a launcher word, handled before routing, as `self-update` is. It reuses the `release.json` fetch and checksum code of `launcher/update.go`.
- Phase 1 decides whether phases 3 and 4 need runtime changes. Phases 2 and 3 touch different repositories and can run in parallel after it.

---

## Phase 1: Composer runs under a served project

### What to build

A spike, kept out of the repository. Make a folder with `composer create-project drupal/cms` and write the ADR's settings into it. Serve it with a local `drupack` build, then run the installer in a browser. Pick a template from the curated list, then install one module through Project Browser.

`ComposerExecutor` starts `php` through Symfony's `PhpExecutableFinder` from the web worker. Package Manager starts Composer the same way.

### Acceptance criteria

- [ ] The installer downloads and applies a curated template.
- [ ] Project Browser installs a module.
- [ ] Each failure is recorded with its cause, such as no `php` on the worker's `PATH`, or a `-d` option that `php-cli` refuses.
- [ ] Each fix is listed as a task in phase 3 or 4.

---

## Phase 2: the archive repository

### What to build

Create `tresbientech/drupal-cms-archive` on the Forge, mirrored to GitHub. Its daily job runs four steps:

1. Resolve `composer create-project drupal/cms` with `extra.drupal-scaffold.gitignore` set to false.
2. Compare the lock with the last release's, and stop when they match.
3. Pack `drupal-cms-VERSION.tar.gz`, and write `release.json` and `checksums.txt`.
4. Serve the archive with the pinned Drupack tag, check that `/core/install.php` answers 200, then tag and publish.

### Acceptance criteria

- [ ] A run with no lock change tags nothing.
- [ ] A lock change publishes `2026.MM.N` with the archive, `release.json` and `checksums.txt`.
- [ ] `release.json` names the cms version, the core version, the size and the SHA-256.
- [ ] A failed serve check publishes nothing and leaves `main` unchanged.

---

## Phase 3: `drupack new DIR`

### What to build

The launcher word `new`. It refuses a DIR that exists and is not empty. It reads `release.json` from the archive repository's `latest` release, downloads the archive into a staging directory beside DIR, checks size and SHA-256, and extracts it. It then writes `web/sites/default/settings.php` from a template the engine carries, renames the staging directory to DIR, and serves DIR.

### Acceptance criteria

- [ ] `drupack new site` creates `site`, serves it, and opens the installer.
- [ ] A non-empty DIR, a checksum mismatch or a failed download leaves no DIR behind.
- [ ] `settings.php` holds SQLite at `db/site.sqlite`, a fresh hash salt, `config_sync_directory` at `../config` and Package Manager direct write.
- [ ] Two `new` runs write different hash salts.
- [ ] A conformance case runs `new` against a local archive URL.

---

## Phase 4: a start on an uninstalled project opens the installer

### What to build

`engine/serve.php` checks whether the project holds an installed site before it mints a login link. With none, it prints `/core/install.php` and opens the browser there.

### Acceptance criteria

- [ ] A start on a fresh `new` folder opens `/core/install.php` and prints no mint failure.
- [ ] A start on an installed project prints the login link, as before.

---

## Phase 5: documentation

### What to build

- A README section after "Serve a Drupal project you already have": create a Drupal CMS site.
- `docs/cli.md`: the `new` word, its refusals and the settings it writes.
- `CONTEXT.md`: the Drupal CMS archive.

### Acceptance criteria

- [ ] Each command in the README section runs as written on Linux.
