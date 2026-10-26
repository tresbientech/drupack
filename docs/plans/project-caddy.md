# Plan: A project adds its own Caddy rules

**Spec:** docs/prd/project-caddy.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: one file, `drupack.caddy`, at the project root beside `composer.json`.
- **D2**: both Caddyfiles import it inside the route after `guards.caddy` and before
  the mercure import.
- **D3**: `DRUPACK_RUNTIME_PROJECT_SNIPPET` holds the file's absolute path or
  `none.caddy`, set by `projectSnippetEnvironment(string $project): array` in
  `application/process.php`.
- **D4**: read at start, no `--watch`; a change takes a restart.
- **D5**: a broken snippet fails the start; the word adapts the config before `run`,
  prints Caddy's error and names the snippet.
- **D11**: `guards.caddy` refuses `/drupack.caddy` by name.
- **D6**: `none.caddy` names both uses.
- **D7**: engine conformance tests for a working and a broken snippet.
- **D8**: the variant fixture carries a snippet and `ProjectRules` asserts it.
- **D9**: a PHP unit test for the environment function.
- **D10**: a docs section, ADR 0048, amendment lines in ADRs 0022 and 0047.
- **Tests**: the PHP unit files run as `php application/tests/launch_test.php a b`
  with `application/vendor` linked to a built site's vendor; `bash build/unit-tests.sh`
  runs the Python unit files; the conformance cases run on the QA host with
  `bash build/qa.sh`.

---

## Phase 1: The engine serves a project's snippet

**User stories**: 1
**Decisions**: D1, D2, D3, D6, D7 (working snippet), D9, D11

### What to build

A `drupack.caddy` at a project's root takes effect on the engine. Both Caddyfiles
gain the import after the guards. The start sets the environment value through the
new function, called from the engine start with the project folder and from the
packaged start with the application directory. `none.caddy` names both uses. The
engine test writes a snippet adding a header, a redirect and a `respond` on a
guarded path, and asserts each answer.

### Acceptance criteria

- [ ] `php application/tests/launch_test.php a b` passes with the two new cases for
      `projectSnippetEnvironment()`.
- [ ] `bash build/unit-tests.sh` passes.
- [ ] The local php-server loop against `engine/Caddyfile` with a `drupack.caddy` in
      the probe project answers the header on `/user/login` and `/core/misc/drupal.js`,
      the redirect, and 403 on the guarded path; with no file, the same requests
      answer as before.
- [ ] `test_a_project_snippet_runs_after_the_guards` in
      `tests/conformance/engine_cases.py` passes on the QA host.

---

## Phase 2: A broken snippet fails the start by name

**User stories**: 3
**Decisions**: D5, D7 (broken snippet)

### What to build

A snippet the Caddyfile adapter rejects stops the start with a non-zero exit, Caddy's
adapter error and a line naming the snippet's path. The server word adapts the config
once before `run`, since Caddy's `run` drops its buffered log on that error. The
engine test writes such a snippet and asserts the failure.

### Acceptance criteria

- [ ] `gofmt -l runtime/entrypoint.go` prints nothing; the runtime builds in the QA
      run, which is the first compile of the word.
- [ ] `test_a_broken_project_snippet_stops_the_start` asserts Caddy's error text and
      the snippet's path in the start's output.
- [ ] `test_a_broken_project_snippet_stops_the_start` in
      `tests/conformance/engine_cases.py` passes on the QA host.

---

## Phase 3: A packaged site carries the snippet

**User stories**: 2
**Decisions**: D8

### What to build

The variant fixture gains a `drupack.caddy` setting a response header. A new case
file `tests/conformance/project_rules_cases.py` with class `ProjectRules` asserts
the header on a page and 403 on `/sites/default/settings.php`. `build/qa.sh` adds
`-k ProjectRules` to the variant run.

### Acceptance criteria

- [ ] `bash build/variant-fixture.sh <demo> <dir>` writes `<dir>/drupack.caddy`.
- [ ] `ProjectRules` passes on the variant build on the QA host.

---

## Phase 4: Docs and ADR

**User stories**: 1, 2, 3
**Decisions**: D4, D10

### What to build

`docs/build-your-site.md` gains "A site's own Caddy rules" after the live-updates
section: the file, its place in the route, what it can and cannot do, the restart,
and the failure message. ADR 0048 records the decision, the restart choice and the
considered options; ADRs 0022 and 0047 gain an amendment line.

### Acceptance criteria

- [ ] `grep -n "A site's own Caddy rules" docs/build-your-site.md` finds the section.
- [ ] `ls docs/adr/0048-*.md` finds the ADR, and `grep -l "ADR 0048" docs/adr/0022-*.md
      docs/adr/0047-*.md` lists both.
- [ ] The writing gate passes on the added Markdown lines.
