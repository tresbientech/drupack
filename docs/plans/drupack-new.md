# Plan: `drupack new` and the Drupal CMS archive

**Spec:** [docs/prd/drupack-new.md](../prd/drupack-new.md)

## Architectural decisions

Every phase starts from the PRD's decisions:

- D1: `new DIR` is an Engine executable word the launcher handles before routing.
- D2: `new` refuses with no DIR, or a DIR holding any entry.
- D3: `new` reads the archive repository's `latest` `release.json`, one asset with target `any`.
- D4: the download goes through the `self-update` client, with size and SHA-256 checked.
- D5: extraction into staging refuses escaping entries, and staging becomes DIR last.
- D6: `new` writes `settings.php` from `default.settings.php` plus Drupack's block, and creates `db/` and `files/`.
- D7: `new` continues as `drupack DIR` with `DRUPACK_NEW_PROJECT=1`, which `serve.php` reads and clears.
- D8: with that variable and a person watching, the browser opens on the installer.
- D9: a start on an uninstalled project prints the installer address and mints no link.
- D10: `serve()` puts `engine/bin` first on `PATH` before the server starts.
- D11: the Composer spike runs first, and its findings become tasks.
- D12: `tresbientech/drupal-cms-archive` builds stock `create-project drupal/cms` daily.
- D13: it tags `YYYY.MM.N` on a lock change alone.
- D14: a tag follows a 200 from `/core/install.php` under the pinned Drupack tag.
- D15: Drupack never writes into a created folder after `new`.
- D16: README, `docs/cli.md` and `CONTEXT.md` describe `new` and the archive.

Commands the criteria name:

- Go: `(cd launcher && go test ./...)`
- conformance: `DRUPACK_TEST_ENGINE=dist/drupack-linux-amd64 python3 tests/conformance EXECUTABLE RESULTS -k CASE`, from the main checkout

---

## Phase 1: a served project runs Composer

**User stories**: 3, 4, 5
**Decisions**: D10, D11

### What to build

Put `engine/bin` first on `PATH` for the server, as `drush` already has it. Then run the spike outside the repository. Create a project with the host's `composer create-project drupal/cms`, add the D6 settings by hand, and serve it with a local engine build. Drive the installer with Playwright through a curated template, then install one module with Project Browser. Record each failure and add its fix to the phase that owns the file.

### Acceptance criteria

- [ ] A new `engine_cases.py` case requests a project page that runs `php -r 'echo PHP_VERSION;'` in a child process, and gets the runtime's version back.
- [ ] Playwright completes the installer with a curated template, then `drupack drush status --field=bootstrap` in the spike folder prints `Successful`.
- [ ] After the Project Browser install, `drupack drush pm:list --status=enabled` lists the module.
- [ ] The Drush field that tells an uninstalled SQLite project from an unreachable database is written into phase 3.
- [ ] `(cd launcher && go test ./...)` passes.

---

## Phase 2: `drupack new` creates a project from an archive

**User stories**: 1, 6, 7, 8, 9, 10, 11, 16, 24
**Decisions**: D1, D2, D3, D4, D5, D6, D15

### What to build

The launcher word `new DIR` on the Engine executable. It reads `release.json` from a constant URL, downloads the single asset, and checks size and SHA-256. It extracts into staging beside DIR, writes the settings block and the two directories, then renames staging to DIR. The function takes the release URL as a parameter, so tests point it at a local server.

### Acceptance criteria

- [ ] Go tests against `httptest` show that `new` refuses a missing DIR argument and a non-empty DIR, and writes nothing.
- [ ] Go tests show a size or SHA-256 mismatch leaves no DIR and no staging directory.
- [ ] Go tests show an archive entry with an absolute path, a `..` segment or an escaping link is refused, with no DIR left.
- [ ] A Go test reads the written `settings.php` and finds every setting D6 lists.
- [ ] A Go test runs `new` twice and finds two different hash salts.
- [ ] Every existing `engine_cases.py` case passes.

---

## Phase 3: the created project opens on the installer

**User stories**: 2, 12, 13, 14, 15, 17
**Decisions**: D7, D8, D9

### What to build

After writing the folder, `new` continues as `drupack DIR` with `DRUPACK_NEW_PROJECT=1`. `serve.php` reads and clears it. With a person watching on a loopback listener, it sets the runtime's open variable to the installer address. A start on an uninstalled project prints the installer address in place of the login line, under the Drush field phase 1 named.

### Acceptance criteria

- [ ] An `engine_cases.py` case starts a project whose settings name an empty SQLite path, and finds `Install:` with `/core/install.php` and no mint failure.
- [ ] The existing login-link case in `engine_cases.py` still finds `Login:` on an installed project.
- [ ] A `browser_cases.py` case shows a start with `DRUPACK_NEW_PROJECT=1` and a person present opens the installer address.
- [ ] A `browser_cases.py` case shows the same start with no person present opens nothing.
- [ ] An `engine_cases.py` case shows a request's environment holds no `DRUPACK_NEW_PROJECT`.

---

## Phase 4: the archive repository

**User stories**: 18, 19, 20, 21, 22
**Decisions**: D12, D13, D14

### What to build

A local repository at `../drupal-cms-archive`, laid out like the demo repository, with a Gitea workflow that runs four steps:

1. Resolve `composer create-project drupal/cms` with the scaffold's `gitignore` off.
2. Compare the lock with the last release's, and stop when they match.
3. Serve the archive with the pinned Drupack tag and request `/core/install.php`.
4. Tag `YYYY.MM.N` and publish `drupal-cms-VERSION.tar.gz`, `release.json` and `checksums.txt`.

Creating the Forge repository, its GitHub mirror and the first push are owner steps.

### Acceptance criteria

- [ ] The workflow's build script, run locally with a dry-run flag, writes an archive, `release.json` and `checksums.txt` into a scratch directory.
- [ ] Its `release.json` holds one asset with target `any` and the `cms` and `core` fields, which phase 2's Go types decode in a unit test.
- [ ] The script run twice with no upstream change reports no lock change and tags nothing.
- [ ] The serve check, run locally against the archive with a local engine build, gets 200 from `/core/install.php`.

---

## Phase 5: documentation

**User stories**: 23
**Decisions**: D16

### What to build

A README section after "Serve a Drupal project you already have". The `new` word in `docs/cli.md`. The Drupal CMS archive in `CONTEXT.md`. The ADR's "Order" or status line, if the build changed a decision.

### Acceptance criteria

- [ ] `grep -n 'drupack new' README.md docs/cli.md` finds both sections.
- [ ] `grep -n 'Drupal CMS archive' CONTEXT.md` finds the term.
- [ ] After the archive's first release, the README's `drupack new` command run with a local engine build prints the installer address.
