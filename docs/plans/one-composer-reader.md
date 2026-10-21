# Plan: One reader for a project's composer.json settings

**Spec:** docs/prd/one-composer-reader.md

## Architectural decisions

- D1: `siteconfig.ReadComposer(project, mode)` returns `Composer{Docroot, Mercure, Subdomain}`; `Read` calls it with `Packed`.
- D2: both modes share the object, `web-root`, `mercure` and `subdomain` rules.
- D3: `Folder` defaults a missing file and a missing `web-root` to `web`, and takes `.` as the project root. `Packed` refuses all three.
- D4: a refusal reads `<project>/composer.json: <field> must <rule>`.
- D5: the engine's launcher word `composer-settings DIR` prints `{"docroot", "mercure", "subdomain"}` or refuses with exit 1.
- D6: `engine/serve.php` reads the settings through that word alone.
- D7: ADR 0044 and `docs/cli.md` name the reader and the word.

---

## Phase 1: Go reader and the launcher word

**User stories**: 2, 5, 6, 7
**Decisions**: D1, D2, D3, D4, D5

### What to build

`siteconfig` gains the exported reader with its two modes and the D4 wording.
`Read` uses it. The engine's launcher answers `composer-settings DIR` before
any cache work. A new `testdata/composer.json` table drives the Go tests over
both modes. `application/tests/composer-extra.json` stays until phase 2, read
by PHP alone.

### Acceptance criteria

- [ ] `bash build/go-tests.sh` passes.
- [ ] `go test ./internal/siteconfig/ -run Composer -v` in `launcher/` runs
      table rows for: an absolute `web-root`, `C:/web`, `./` per mode, no
      `web-root` key per mode, no `composer.json` per mode, the subdomain
      `demo\n`, a non-object `composer.json`, a non-object `extra.drupack`.
- [ ] A test in `launcher/` checks the word's JSON for a folder and its exit
      status and stderr for a refusal.
- [ ] `go vet ./...` in `launcher/` is clean.

---

## Phase 2: The engine reads through the word

**User stories**: 1, 3, 4, 5
**Decisions**: D6, D7

### What to build

`engine/serve.php` runs the word once per start and once per `drush`, and
keeps only the Drupal core check of the docroot. The PHP composer readers and
`application/tests/composer-extra.json` go. Conformance gains the absolute
`web-root` refusal and the folder without `composer.json`. ADR 0044 and
`docs/cli.md` record the word.

### Acceptance criteria

- [ ] `grep -cE 'drupackExtra|mercureKey|folderLabelSource' engine/serve.php`
      prints 0.
- [ ] `test ! -e application/tests/composer-extra.json` succeeds.
- [ ] `php application/tests/serve_test.php a b` (with
      `DRUPACK_RUNTIME_NAME=drupack`) passes.
- [ ] `bash build/go-tests.sh` passes.
- [ ] The conformance `EngineExecutable` cases pass against an engine built
      from the branch, including the two new cases.
- [ ] `docs/adr/0044-*.md` exists and `grep -c composer-settings docs/cli.md`
      prints at least 1.
