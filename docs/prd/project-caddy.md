# A project adds its own Caddy rules

## Problem Statement

A site author has rules Apache took from `web/.htaccess`: a response header, a
redirect, a rewrite, a path refused beyond Drupal's own list. Drupack's Caddy
reads no `.htaccess` and imports only the snippets it ships, chosen by values
the start sets. Nothing in a project reaches the Caddyfile. The author's only
place for such a rule is Drupal itself, which runs after every request guard
and after the static file server, so a header on a static file or a redirect
ahead of Drupal has no home.

## Solution

A project keeps one Caddyfile snippet, `drupack.caddy`, at its root beside
`composer.json`. The engine executable imports it when it serves the folder,
and a packaged site imports the copy its build packed. The snippet runs inside
the request route after Drupack's guards and before Drupal, so a refused
request is answered before a project's rule runs. The start reads the file
once; a change takes a restart. A start with a broken snippet fails with
Caddy's own adapter error and a line naming the file.

## Out of Scope

- Live reload, through Caddy's `--watch` or a poller of our own. Caddy's
  watcher stops on an adapter error and misses a file created after the start.
- A hook before the guards, or in the global options or site block.
- A copy in Site data an install edits without a rebuild.
- A `validate` word that checks the snippet without a start.
- Limits on what a snippet may do, such as refusing `reverse_proxy`.

## User Stories

1. As a site author who develops with the engine, I want a `drupack.caddy` in
   my project to add headers, redirects and refusals, so that rules I kept in
   `.htaccess` keep working under Drupack.
2. As a site author who packages a site, I want the same file to ship inside
   the package, so that the rules I tested with the engine hold once packed.
3. As a site author with a typo in the snippet, I want the start to fail with
   the file and line, so that I fix it without reading Caddy's source.

## Implementation Decisions

- D1: A project's rules live in one file, `drupack.caddy`, at the project
  root, the directory holding `composer.json`. Evidence: `build/app-payload.sh`
  packs the whole application directory with `tar -C "$application" .`, so the
  file reaches a packaged site with no build change; `engine/serve.php` names
  the folder through `projectFolder()`.
- D11: `guards.caddy` refuses `/drupack.caddy` by name, as it refuses
  `composer.json`, so a project whose docroot is its root never serves its
  rules. Evidence: `@protectedName` in `application/guards.caddy` lists the
  names refused by name alone.
- D2: Both Caddyfiles import the snippet inside their route block after
  `import "guards.caddy"` and before the mercure import. Written order inside a
  route is the run order, so every guard answers first. Evidence:
  `application/Caddyfile` lines 48 to 51 and `engine/Caddyfile` lines 44 to 47
  hold the two imports in that order.
- D3: The start sets `DRUPACK_RUNTIME_PROJECT_SNIPPET` to the file's absolute
  path when it exists, else to `none.caddy`, through one function
  `projectSnippetEnvironment(string $project): array` in
  `application/process.php`, which `engine/process.php` links. The engine start
  passes the project folder and the packaged start passes `application()`.
  Evidence: `mercureServerEnvironment()` in `application/mercure.php` sets the
  mercure snippet the same way; `exportServerEnvironment()` in
  `application/process.php` refuses a value holding a double quote, so a
  project path with one refuses the start with a message naming the variable.
- D4: The file is read at start. The server word hands Caddy `run --config
  --adapter caddyfile` with no `--watch`, and a change, a creation or a removal
  takes a restart. Evidence: `serveCaddyfile()` in `runtime/entrypoint.go`.
- D5: A start whose snippet fails the Caddyfile adapter exits non-zero, and
  its output carries Caddy's adapter error followed by a line naming the
  project's snippet path. The server word adapts the config once before it
  hands Caddy `run`, through the Caddyfile adapter alone, and prints the error
  itself. Evidence: Caddy's `cmdRun` in `cmd/commandfuncs.go` buffers its
  early log, flushes the buffer on the adapter error, then logs the error into
  the buffer, so a bare `run` exits 1 in silence; `validate` prints the error.
  `caddycmd.LoadConfig` logs at INFO through the default logger to the original
  stderr, so the word calls the adapter directly. For an unknown directive
  Caddy's message names the enclosing `route` and its import chain, not the
  snippet's line, so the word adds the line that names the snippet.
- D6: `application/none.caddy` keeps one comment naming both uses: no mercure
  hub, and no project snippet. Evidence: `application/none.caddy` holds the
  mercure comment alone.
- D7: `tests/conformance/engine_cases.py` gains a test that writes a
  `drupack.caddy` into the copied project adding a response header, a redirect
  and a `respond` on a path the guards refuse, then asserts the header on a
  page and a static file, the redirect's status and location, and 403 on the
  refused path. A second test writes a snippet with a syntax error and asserts
  the start fails with output naming `drupack.caddy`. Evidence: the class
  copies the application into `cls.project` in `setUpClass`, and the composer
  settings test asserts a refusal message in `result.stderr`.
- D8: `build/variant-fixture.sh` adds a `drupack.caddy` to the variant demo
  setting a response header, and a new `tests/conformance/project_rules_cases.py`
  class `ProjectRules` asserts the header on a page and 403 on
  `/sites/default/settings.php`. `build/qa.sh` runs it on the variant build
  beside `NodeRefusals` and `MercureHub`. Evidence: `build/qa.sh` line 75
  selects cases on the variant musl file with `-k`.
- D9: `application/tests/launch_test.php` covers `projectSnippetEnvironment()`
  for a present and an absent file. Evidence: the file tests
  `serverEnvironment()` and `TlsChoice::serverEnvironment()` the same way.
- D10: `docs/build-your-site.md` gains a section "A site's own Caddy rules"
  after "A site that pushes live updates". A new ADR 0048 records the decision
  and the parked reload, and amends ADR 0047 (the guards run first) and ADR
  0022 (the engine reads the folder's file), each of which gains an amendment
  line. Evidence: ADR 0022 carries amendment lines for 0044 and 0047.

### Considered options

- Importing the snippet before the guards. It gives a project Apache's power to
  loosen a rule. It lost because ADR 0047's parity would become a default a
  project may undo.
- Reading the snippet from Site data on a packaged site. An install could edit
  its rules without a rebuild. It lost because a directory Drupal writes to
  would shape the server, and the engine and the package would read different
  places.
- Live reload through Caddy's `--watch`. One flag. It lost because the watcher
  in Caddy 2.11.7 returns on an adapter error and the import target is fixed
  at start, so a file created later waits for a restart anyway.
- A poller of our own in the server word. About 40 lines of Go. It lost to
  restart-only by the owner's choice on 2026-10-25.
- A glob import, `import "<root>/drupack.cadd[y]"`, with no environment value.
  A pattern that matches nothing only warns. It lost because the warning
  repeats on every adaptation and the value in the mercure shape already exists.

## Testing Decisions

- A good test requests the server and reads the answer; none reads the
  Caddyfile or the environment the start exported.
- Tested: the engine's import of a project snippet, the refusal of a broken
  one, the packaged import through the variant fixture, and the environment
  function.
- Prior art: `test_the_guards_reproduce_the_htaccess_rules` in
  `tests/conformance/engine_cases.py` for marker files and requests;
  `mercure_cases.py` for a case run on the variant build;
  `application/tests/launch_test.php` for environment functions.

## Further Notes

The guards answer a refused path before the snippet runs, so a project rule
on such a path is dead text, and a rewrite the snippet makes is not checked
again. The docs section says so. The aggregate file server moves from
`guards.caddy` to `engine/Caddyfile` after the snippet, since a `file_server`
ends the request and a snippet's header would miss an aggregate on the engine.
