# A project adds its own Caddy rules

Accepted on 2026-10-26. Amends [ADR 0022](0022-engine-executable.md) and
[ADR 0047](0047-request-guards-match-htaccess.md).

## Context

Both Caddyfiles import only the snippets Drupack ships, chosen by values the
start sets. A site author's rules from `web/.htaccess`, such as a header, a
redirect or an extra refusal, had no home: Caddy reads no `.htaccess`, and
Drupal runs after the guards and the static file server.

## Decision

- A project keeps one snippet, `drupack.caddy`, at its root beside
  `composer.json`. The engine reads the folder's file. A packaged site reads the
  copy in its payload, which `build/app-payload.sh` packs with the project root.
- Both Caddyfiles import it inside the route after `guards.caddy` and before the
  mercure snippet and `php_server`. A refused request is answered before the
  snippet runs. A rewrite the snippet makes is not checked again; only the
  project's author writes the file.
- The aggregate file server moves from `guards.caddy` to `engine/Caddyfile`,
  after the snippet. A `file_server` ends the request, where the header
  directives do not, so a snippet's header reaches an aggregate on the engine
  as it does in a packaged site's files route.
- The start sets `DRUPACK_RUNTIME_PROJECT_SNIPPET` to the file's absolute path
  when it exists, else to `none.caddy`, through `projectSnippetEnvironment()`.
- The file is read at start. The server word hands Caddy `run` with no
  `--watch`; a change, a creation or a removal takes a restart.
- The server word adapts the config before `run`, without Caddy's logger. On an
  adapter error it prints Caddy's message, then a line naming the project's
  snippet, and exits 1. Caddy's `run` logs an adapter error into its early-start
  buffer after flushing it, so a bare `run` exited in silence.
- `guards.caddy` refuses `/drupack.caddy` by name, as it refuses
  `composer.json`, for a project whose docroot is its root.
- `tests/conformance/engine_cases.py` covers a working and a broken snippet.
  `build/variant-fixture.sh` packs one into the variant demo, which
  `project_rules_cases.py` asserts.

## Considered options

- Importing the snippet before the guards. Apache's power to loosen a rule,
  at the cost of ADR 0047's parity becoming a default a project may undo.
- Reading the snippet from Site data on a packaged site. An install edits its
  rules without a rebuild, and a directory Drupal writes to shapes the server.
- Live reload through Caddy's `--watch`. Caddy 2.11.7's watcher returns on an
  adapter error, and the import target is fixed at start, so a file created
  later waits for a restart anyway.
- A one-second poller in the server word, about 40 lines of Go. The owner chose
  restart-only on 2026-10-25.
- A glob import with no environment value. A pattern that matches nothing warns
  on every adaptation.

## Consequences

- A rule on a guarded path is dead text. The docs say so.
- A project cannot serve a file named `drupack.caddy` from its docroot.
- Every start adapts the Caddyfile twice, once in the word and once in Caddy's
  `run`.
- A start on an adapter error in a shipped snippet now prints the message too.
