# Plan: A site serves its Tome export on a second port

**Spec:** docs/prd/static-site.md

## Architectural decisions

Durable decisions that apply across all phases.

### The toggle, the listener and the directory

- **D1**: `composer.json` `extra.drupack.static`, an object with an optional
  `dynamic` list of Caddy `path` patterns, read by `ReadComposer` in both modes
  into `site.json` and `SiteContract`.
- **D2**: with `static` set, a `composer.lock` without `drupal/tome` is refused
  in both modes.
- **D3**: the static listener is the site listener's address on its port plus
  one, or `--static-listen IP:PORT`. The Listener record keeps it for `drush`
  and `export`. The engine keeps the static URL in the folder's cache entry.
- **D4**: `staticPlan()` and `staticServerEnvironment()` in
  `application/static.php` give the plan and the five
  `DRUPACK_RUNTIME_STATIC_*` variables.
- **D5**: `static-site.caddy` defines `(static_site)`; three
  `static-site-<tls>.caddy` files name the addresses; `none.caddy` is the off
  state.
- **D6**: the static block serves files with `try_files` and `file_server`,
  answers `404/index.html` on a miss, and hands `dynamic` paths to
  `php_server` behind `guards.caddy`.
- **D7**: the export lives in `static` in Site data, set as
  `tome_static_directory` from `DRUPACK_RUNTIME_STATIC_ROOT`; the engine serves
  `html` beside the docroot.
### The words, the demo and the tests

- **D8**: `export` runs Drush `tome:static --yes --uri URL` plus the reader's
  words, URL being the recorded static URL unless the reader passed one.
- **D9**: `drush` and `export` launch `vendor/bin/drush` with `bin/` on PATH;
  `engine/bin` moves to `application/bin`.
- **D10**: the `php` word answers on a Packaged site too.
- **D11**: the build writes `vendor/bin/drush.cmd` into the staged application.
- **D12**: Drupacked Demo requires Tome, applies a `static_site` recipe and sets
  `static` with `dynamic: ["/search*"]`; the demo lands first.
- **D13**: Go tests for the reader and the lock check; PHP unit tests for the
  plan and environment functions.
- **D14**: `StaticSite` conformance cases, engine cases on the copied project,
  and the class in `build/qa.sh`.
- **D15**: `docs/cli.md`, `docs/build-your-site.md`, README, ADR 0049.
- **Tests**: the PHP unit files run as `php application/tests/launch_test.php a b`,
  with `application/vendor` linked to a built site's vendor. `go test ./...` in
  `launcher/` runs the Go tests. The conformance cases run on the QA host with
  `bash build/qa.sh`, or one class at a time with
  `python3 tests/conformance EXECUTABLE RESULTS -k CLASS`.

---

## Phase 1: Drupacked Demo carries Tome

**User stories**: 7
**Decisions**: D12

### What to build

The demo repository requires `drupal/tome`, gains `recipes/static_site` that
installs `tome_static`, applies it from `drupacked_demo_site`, and sets
`extra.drupack.static` to `{"dynamic": ["/search*"]}`. Its README names the
two ports. The commit is pushed to the demo's forge repository, with the
owner's approval, and `build/demo-pin.sh` names that commit with an empty
`demo_release`. The demo still builds and its existing cases pass on the
current engine, which ignores the new key.

### Acceptance criteria

- [x] `composer show drupal/tome` in the demo checkout prints version 8.x-1.16
      or later.
- [x] `recipes/static_site/recipe.yml` installs `tome_static`, and
      `recipes/drupacked_demo_site/recipe.yml` lists `static_site`.
- [x] `python3 -c` reading the demo's `composer.json` prints
      `{"subdomain": "demo", "static": {"dynamic": ["/search*"]}}` for
      `extra.drupack`.
- [x] `build/demo-pin.sh` sets `demo_ref` to the pushed commit and
      `demo_release` to the empty string.
- [x] The `drupack-build --site "$demo"` step of `build/qa.sh` builds the
      pinned demo, and `python3 tests/conformance EXECUTABLE RESULTS -k
      SeededSite` passes on it.

---

## Phase 2: The toggle is read and refused

**User stories**: 1, 2, 3
**Decisions**: D1, D2, D13 (Go)

### What to build

`ReadComposer` reads `extra.drupack.static` in both modes, refuses a wrong
value by field, and refuses a lock without `drupal/tome` when the key is set.
The value reaches `site.json` through `Write`, the engine's `composer-settings`
word prints it, and `SiteContract` exposes it. A build of a site without the
key, and an engine start on a project without it, change nothing.

### Acceptance criteria

- [x] `go test ./internal/siteconfig/...` in `launcher/` passes with cases for
      `static` absent, `{}` and with `dynamic`.
- [x] The same run covers each refusal. A non-object `static`. A non-list
      `dynamic`. A pattern without a leading `/`, one with whitespace, and one
      with a double quote. A lock without `drupal/tome`.
- [x] `drupack composer-settings DIR` on the demo checkout prints a `static`
      member with `dynamic: ["/search*"]`.
- [x] `drupack composer-settings DIR` on a copy whose lock lacks `drupal/tome`
      exits 1 naming `composer.lock` and `drupal/tome`.
- [x] `dist/site.json` of a demo build holds `static`, and
      `SiteContract::read()` returns it.

---

## Phase 3: A Packaged site serves its static directory

**User stories**: 1, 3
**Decisions**: D3 (Packaged), D4, D5, D6 (files and 404), D7 (Packaged),
D13 (PHP), D14 (first cases)

### What to build

A Packaged site with `static` on serves a second port: the site listener's
address on its port plus one, or `--static-listen`. The port serves the files
of `static` in Site data, created on start, with `try_files` of the path and
its `index.html` and no redirect. A miss answers `404/index.html` with status
404 when the file exists. The Listener record keeps `static-listen`. The
generated settings point `tome_static_directory` at the directory. A site
without the key serves no second port. The three TLS variants each get their
static address file.

### Acceptance criteria

- [x] `application/tests/launch_test.php` covers `staticPlan()` and
      `staticServerEnvironment()` for `static` absent, `{}` and with `dynamic`,
      and passes.
- [x] `StaticSite` in `tests/conformance/static_site_cases.py` passes. A start
      with `--static-listen 127.0.0.1:PORT` answers 404 on `/` of that port
      before any export.
- [x] A file written to `static/hello/index.html` in Site data answers 200 on
      `/hello` and `/hello/index.html` with no redirect.
- [x] A `static/404/index.html` is the body of a 404 on `/missing`.
- [x] The Listener record of that site holds `static-listen` with the given
      address, and `drush` runs after the start with no option.
- [x] A start without `--static-listen` serves the static port on the site's
      port plus one, asserted by a request to it.
- [x] A site whose `site.json` has no `static` refuses connections on its port
      plus one. `StaticSiteOff` asserts it on the no-node build.
- [x] `TlsSources` still passes, and a `--static-listen` start under the
      `drupack` TLS source answers HTTPS on the static port.

---

## Phase 4: `export` writes the site and the static port serves it

**User stories**: 1, 5, 6
**Decisions**: D8 (Packaged), D9, D10, D11, D14 (export cases)

### What to build

`drush` and `export` on a Packaged site launch Drupack's Drush entry, laid
over the site from `application/drush` with its `.cmd` twin, with
`application/bin` on PATH, and the entry point answers the `php` word on a
Packaged site. `SITE export` runs `tome:static --yes --uri URL` with the
recorded static URL and the reader's words. It adds no `--uri` when the reader
passed one, and it needs an installed site. After an export the static port
serves the front page with the static URL in its markup.

### Acceptance criteria

- [x] `StaticSite` passes: `SITE export` exits 0 and `static/index.html` exists
      in Site data.
- [x] `/` on the static port answers 200 with the static URL in the body.
- [x] `SITE export --uri https://example.test/` writes markup naming
      `https://example.test/`.
- [x] `SITE export` on an empty Site data exits 1 naming the directory.
- [x] `SITE php -r 'echo PHP_VERSION;'` prints the runtime's version, and
      `SITE drush php:eval "passthru('php -r \"echo 1;\"');"` prints 1 with an
      empty PATH.
- [x] `tar -tf dist/work/payload/app-payload.tar | grep -cE '^\./drush(\.cmd)?$'`
      prints 2.
- [x] `test_drush_child_processes_run_on_the_runtime_php_with_no_php_on_path`
      and `test_dr_runs_drupal_cores_command_line` still pass.

---

## Phase 5: Dynamic paths reach Drupal on the static port

**User stories**: 3, 4
**Decisions**: D6 (dynamic)

### What to build

`static-dynamic.caddy` matches the `dynamic` patterns, roots at the Drupal
docroot, imports `guards.caddy` and runs `php_server`. The start exports the
patterns and names the snippet only when the list has one. On the demo,
`/search/node?keys=drupal` on the static port answers from Drupal, core content
search, and `/user/login` answers 404 from the export.

### Acceptance criteria

- [x] `StaticSite` passes: `/search/node?keys=drupal` on the static port
      answers 200 with `X-Generator: Drupal` in its headers, with an exported
      copy under `static/search/node/` left unserved.
- [x] `/user/login` on the static port answers 404.
- [x] `/search/x.php` on the static port answers 403, the guards' answer.
- [x] `launch_test.php` asserts `staticServerEnvironment()` names
      `none.caddy` for the dynamic snippet and omits
      `DRUPACK_RUNTIME_STATIC_DYNAMIC` when `dynamic` is absent.

---

## Phase 6: The engine serves and exports a project folder

**User stories**: 2, 5
**Decisions**: D3 (engine), D7 (engine), D8 (engine), D14 (engine cases)

### What to build

An engine start on a project with `static` serves `html` beside the docroot on
the static port, `--static-listen` included. It writes the static URL into the
folder's cache entry, and refuses a lock without Tome. `drupack export` reads
that URL, runs the project's Drush through `vendor/bin/drush`, and writes
`html/`. A project without the key serves no second port.

### Acceptance criteria

- [ ] `EngineExecutable` passes: a start on the copied project answers 404 on
      the static port before an export.
- [ ] `drupack export` exits 0 and writes `html/index.html`, and `/` on the
      static port answers 200 with the static URL in the body.
- [ ] The folder gains `html/` and nothing else, asserted by the existing
      snapshot helper with `html` excluded.
- [ ] A start with `--static-listen 127.0.0.1:PORT` serves the export there,
      and `drupack export` after it names that URL.
- [ ] A copy whose lock lacks `drupal/tome` exits 1 on start naming
      `drupal/tome`.
- [ ] `test_a_detached_start_and_stop_leave_the_project_unchanged` still
      passes.

---

## Phase 7: Docs and QA wiring

**User stories**: 1, 2, 3, 4, 5, 6, 7
**Decisions**: D14 (`build/qa.sh`), D15

### What to build

`docs/cli.md` gains `export` and `--static-listen` in the synopsis and the
options table, a "Static site" section, and the `php` word on a site.
`docs/build-your-site.md` gains "A static site" after "A site that pushes live
updates". The README names `export`. `build/qa.sh` runs `StaticSite` on the
demo build. The plan is deleted once its last criterion is ticked.

### Acceptance criteria

- [ ] `application/tests/launch_test.php` passes its assertion of `docs/cli.md`,
      `HELP` and the entry point's usage against `options()`.
- [ ] `grep -c 'StaticSite' build/qa.sh` prints 1.
- [ ] `grep -n '^## A static site' docs/build-your-site.md` and
      `grep -n '^## Static site' docs/cli.md` each print one line.
- [ ] `docs/plans/static-site.md` is deleted in the commit that ticks the last
      criterion.
