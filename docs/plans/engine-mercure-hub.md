# Plan: The Mercure hub key moves to composer.json, and the engine serves it

**Spec:** docs/prd/engine-mercure-hub.md

## Architectural decisions

- **D1**: `composer.json` `extra.drupack.mercure`, a boolean, false when absent, turns the hub on in both modes.
- **D2**: `drupack.yml` drops `mercure`, and `Parse` refuses it with the new key's name.
- **D3**: `siteconfig.Read` sets `Site.Mercure` from `composer.json`; `site.json` and `launch.php` keep their shape.
- **D4**: `engine/Caddyfile` imports the Mercure snippet; `engine/mercure.caddy` and `engine/none.caddy` link to `application/`.
- **D5**: `mercure.caddy` names its history file through `DRUPACK_RUNTIME_MERCURE_DB`.
- **D6**: The engine keeps both secrets, `mercure.db` and `mercure_url` in the folder's cache entry.
- **D7**: `exportMercure` moves to `application/mercure.php`, linked into `engine/`.
- **D8**: An engine start exports the three variables and records the URL in `mercure_url`.
- **D9**: `drupack drush` exports the three variables once a start created them, and creates nothing.
- **D10**: A non-boolean engine key stops `start` and `drush`.
- **D11**: The variant fixture sets the key in `composer.json`.
- **D12**: ADR 0035 amends ADR 0034; `build-your-site.md` and `cli.md` follow.

---

## Phase 1: The key moves to composer.json for Packaged sites

**User stories**: 1, 2, 3, 4
**Decisions**: D1, D2, D3, D11

### What to build

A Packaged site build reads `extra.drupack.mercure` from the site's
`composer.json` and writes it to `site.json`. A `drupack.yml` that sets
`mercure` stops the build with the moved message. The variant fixture sets the
key in its `composer.json`, so the variant `MercureHub` cases keep a hub.

### Acceptance criteria

- [ ] `cd launcher && go test ./internal/siteconfig/` passes, with cases for `drupack.yml` `mercure` refused with `mercure moved to composer.json extra.drupack.mercure`, `extra.drupack.mercure` true, false and absent, and a string refused.
- [ ] `cd launcher && go test ./...` passes.
- [ ] `git grep -n "mercure" build/variant-fixture.sh` shows the key written into `composer.json` and no `drupack.yml` line.
- [ ] `git grep -n -E "Mercure +bool .*yaml|mercure:" launcher/internal/siteconfig/siteconfig.go` prints no YAML field for `mercure`.

---

## Phase 2: The engine serves the hub

**User stories**: 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18
**Decisions**: D1, D4, D5, D6, D7, D8, D9, D10

### What to build

`exportMercure` moves to a shared file both `launch.php` and the engine load.
`mercure.caddy` reads its history path from `DRUPACK_RUNTIME_MERCURE_DB`. An
engine start of a project with the key mounts the hub, creates the secrets in
the folder's cache entry, exports the variables and records the URL. `drupack
drush` exports them once they exist. A new engine conformance class covers the
hub end to end, and `build/qa.sh` and `release.yml` run it beside
`EngineExecutable`.

### Acceptance criteria

- [ ] `bash build/qa.sh` passes, in the main checkout with `DRUPACK_QA_WORK` and `TMPDIR` on disk.
- [ ] Its output lists the new engine Mercure class's cases as `ok`: request publish, HTTP publish, `drush` variables after a non-default `--listen`, secrets in the cache entry with mode 0600 and no new project file, 404 without the key, and a string value refused.
- [ ] Its output lists the variant `MercureHub` cases as `ok`.
- [ ] `python3 -m unittest discover -s tests/conformance -p test_environment.py` passes with the `MERCURE_DB` line.
- [ ] `git grep -n "function exportMercure"` prints one definition, in `application/mercure.php`.
- [ ] `ls -l engine/mercure.caddy engine/none.caddy engine/mercure.php` shows three links into `application/`.

---

## Phase 3: Docs and ADR

**User stories**: 1, 4, 6
**Decisions**: D12

### What to build

ADR 0035 records the moved key and the engine hub, and amends ADR 0034. The
build guide and the CLI reference name the `composer.json` key and the engine
hub.

### Acceptance criteria

- [ ] `test -f docs/adr/0035-*.md` succeeds, and `grep -n "Amends \[ADR 0034\]" docs/adr/0035-*.md` prints one line.
- [ ] `grep -n "Amended on 2026-10-18 by \[ADR 0035\]" docs/adr/0034-a-site-turns-on-a-mercure-hub.md` prints one line.
- [ ] `git grep -n -E "^mercure:|drupack.yml. sets .mercure" docs/` prints nothing.
- [ ] `git grep -n "extra.drupack.mercure" docs/build-your-site.md docs/cli.md` prints a line in each file.
