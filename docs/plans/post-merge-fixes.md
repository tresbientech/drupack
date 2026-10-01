# Plan: fixes found during the libc, Node and PHP routing merges

> Source: the follow-ups of the 2026-09-29 session. No PRD; each phase is one fix.

## Architectural decisions

- Phases 1 to 5 touch disjoint files and can run in parallel worktrees.
- Phase 6 changes WordPal's own repository, then the `mercury-demo-wordpal` branch.
- Phase 7 waits until the `mercury-demo-wordpal` branch merges, since only then does Mercury Demo carry Node.
- Both Caddyfiles are fixed files; every value they read comes from a quoted `{$DRUPACK_RUNTIME_*}` environment reference.

---

## Phase 1: static Caddyfiles read their values from the environment

### What to build

`launch.php` and `serve.php` render a Caddyfile per start with `renderTemplate`.
The engine writes its copy to a predictable name in the shared temporary
directory, and the docroot is filled unquoted, so a path with a space breaks both
servers.

Both Caddyfiles become fixed files. Each value is a quoted `{$DRUPACK_RUNTIME_*}`
reference, which Caddy fills from the environment when it loads the file.
`import "guards.caddy"` resolves beside each Caddyfile. `launch.php` and
`serve.php` set every variable from one list, stop on an empty value, and start
the server on the fixed file. `renderTemplate` and its callers' writes go. The
double-quote refusal on `--data-dir` and `--files-dir` stays, since Caddy splices
the value before it reads quotes. The `mercury-demo-wordpal` branch's `168e71e`
then has nothing left to carry.

### Acceptance criteria

- [x] An engine case serves a Project folder whose path holds a space, and `/` answers 200.
- [x] A writable case starts a site whose Site data path holds a space, and `/user/login` answers 200.
- [x] An engine case starts a server and finds no `drupack-*.Caddyfile` in the temporary directory, and a site case finds no `Caddyfile` in Site data.
- [x] `git grep -n renderTemplate -- application engine` and `grep -n __DRUPACK_ engine/Caddyfile application/Caddyfile` find nothing.
- [x] `grep -n '{\$DRUPACK' engine/Caddyfile application/Caddyfile` shows every reference inside double quotes.
- [x] `python3 -m unittest discover -s tests/conformance -p test_environment.py` passes, with the new variables and their readers listed.

---

## Phase 2: the Engine clears an inherited application directory

### What to build

The Engine launcher sets no `DRUPACK_RUNTIME_APP_DIR`, but it passes one it
inherits. The Runtime then takes the site path: it changes into that directory
and refuses the `php` word. The Engine launcher unsets the variable before it
runs the Runtime.

### Acceptance criteria

- [x] An engine case sets `DRUPACK_RUNTIME_APP_DIR` to a directory, and `drupack php SCRIPT` runs the script in the reader's directory.
- [x] The same case serves a Project folder, and `/` answers 200.
- [x] `python3 -m unittest discover -s tests/conformance -p test_environment.py` passes, with the Engine launcher named where the contract needs it.

---

## Phase 3: the Windows cache race between two releases

### What to build

`test_concurrent_starts_of_different_releases_each_run_their_own_runtime`
failed on Windows with `frankenphp.exe` missing after unpack. `prepare` releases
the root lock before the launcher calls `HoldUsage`. In that window another
release's `Activate` runs `removeOthers`, finds no usage marker, and deletes the
entry. `RemoveAll` can also delete files before it meets a marker opened
between its check and its removal.

The fix holds the usage marker before the root lock releases, on the cold and
the warm path. `removeOthers` renames an entry to a staging name before it
deletes it, so a held entry fails the rename and stays whole.

### Acceptance criteria

- [x] A Go test in the runtime package interleaves one release's prepare with another's activate at the lock boundary, and the first entry keeps every declared file.
- [x] A Go test shows `removeOthers` leaves an entry whose marker another handle holds.
- [x] `(cd launcher && go test ./...)` passes.
- [x] The Windows CI job passes the concurrent-releases case, pending the next tag build.

---

## Phase 4: the development server serves writable sites

### What to build

`build/dev/dev-server.sh` exports no `DRUPACK_RUNTIME_LAUNCHER`, and a writable
site runs `lay-app` through it on every start. The dev entry script gains a
stand-in for that word. `lay-app` copies the mounted application into Site data
and keeps each writable directory's entries. `lay-app --check` answers yes. The
backlog entry for this question leaves.

### Acceptance criteria

- [x] `build/dev/dev-server.sh` starts a site built from a contract naming a writable directory, and `/` answers 200.
- [x] A file written into a writable directory survives a restart of the dev server.
- [x] `git grep -n "development server and sites with writable" docs/backlog.md` finds nothing.

---

## Phase 5: stale lines about two runtimes in one file

### What to build

Three passages still describe a Linux file that carries both runtimes:

- `docs/backlog.md` line 53 says musl is the only libc a release ships.
- ADR 0016 line 53 says a job packs both runtimes.
- ADR 0018 line 43 names the option of linking both runtimes in one job.

The first two change to one runtime per Linux file. ADR 0018's line records an
option weighed when that decision was taken, so it stays.

### Acceptance criteria

- [x] `git grep -n "only libc a release ships" docs` finds nothing.
- [x] `git grep -n "packs both" docs/adr/0016-*.md` finds nothing.

---

## Phase 6: WordPal applies its recipe from its own package

### What to build

WordPal's site setup applies `PROJECT/recipes/wordpal` and stops when the
directory is missing. It changes to read the recipe from WordPal's installed
package directory. WordPal's kernel tests follow.

On the `mercury-demo-wordpal` branch, the demo then drops its seven scaffold
file mappings and the scaffold allowance for `drupal/wordpal`, and pins the new
WordPal commit.

### Acceptance criteria

- [x] WordPal's `SiteSetupTest` passes with no `recipes/wordpal` in the fixture project.
- [x] `grep -c "recipes/wordpal" examples/mercury-demo/composer.json` prints 0 on the branch.
- [x] The branch's `WordPalConversion` site case passes.

---

## Phase 7: Node cases after Mercury Demo carries Node

### What to build

Once the `mercury-demo-wordpal` branch merges, both sites QA builds carry Node.
The case for a site without Node then always skips. The Node fixture becomes a
copy of Mercury Demo without Node. QA builds Mercury Demo with both libcs, so
the musl refusal runs on Mercury's own musl file.

### Acceptance criteria

- [ ] `bash build/qa.sh` runs `test_a_site_without_node_answers_node_as_an_unknown_command` and it passes.
- [ ] `bash build/qa.sh` runs `test_a_musl_build_names_the_glibc_build` against Mercury Demo's musl file and it passes.
- [ ] `bash build/qa.sh` passes, and `.git/qa-green` records it.
