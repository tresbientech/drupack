# PRD: The launcher routes the reader's words

Source: design settled on 2026-10-05, after an architecture survey and four
competing designs.

## Problem Statement

`init()` in `runtime/entrypoint.go` routes every command line before
FrankenPHP's `main()` runs. That covers the reader's words and the PHP
re-entries. It rewrites `os.Args` and sets `DRUPACK_RUNTIME_DRUSH` and
`DRUPACK_RUNTIME_STOP`, which `launch.php` reads to decide its mode again.

- The builds copy the file into FrankenPHP's main package, so no Go test
  reaches `init()`. Only the conformance suite does, against a built executable.
- Two routers handle the reader's words. The launcher's `engineArguments`
  serves the Engine, and `init()` serves a packaged site.
- `entrypoint.go` carries a second usage text. `launch_test.php` reads the Go
  source as text to keep its options equal to `options()`.
- FrankenPHP 1.13.0 changed the `$argv` that `php-cli` hands PHP 8.5. Only a
  full candidate build on GitHub found it.

## Solution

The reader's words already pass through the launcher, a Go module with its own
tests. One pure launcher function routes them, and `launch.php` reads its mode
word from argv, as `engine/serve.php` already does. `init()` keeps only the
words that other programs send it. `build/qa.sh` checks the `$argv` shape on
every run.

## User Stories

1. As a maintainer, I want the reader-word routing under `go test`, so that a routing change fails a unit test.
2. As a maintainer, I want one router for both executables, so that a new word lands in one place.
3. As a maintainer, I want `launch.php` to read its mode from its own argv, so that no environment flag carries routing state.
4. As a maintainer, I want one usage text, so that `--help` cannot drift from the parser.
5. As a maintainer, I want QA to fail when `php-cli` hands PHP a different `$argv`, so that a FrankenPHP bump breaks locally first.
6. As a reader, I want every command, option, message and exit status of `docs/cli.md` unchanged, so that my scripts keep working.
7. As a reader, I want `drupack frobnicate` to answer `Unknown command` with the usage, so that a typo explains itself.
8. As a reader of a musl build, I want `node` to name the glibc build, so that I know which file carries Node.
9. As a reader, I want `drupack node` on a site without Node to answer `Unknown command`, as it does today.
10. As a maintainer, I want the dev server to keep starting, so that `build/dev/dev-server.sh` still serves a site.

## Implementation Decisions

Launcher:

- D1. A pure `runtimeArguments(args, application, engine, node)` in the
  launcher's main package replaces `engineArguments`. It reads no file and
  writes no environment. `node` is true when the file carries a Node manifest.
- D2. These pass to the runtime unchanged:
  - `php-cli …`, for a site alone
  - exactly `--version` or `-v`
  - `node`, `npm` or `npx`, when the file carries Node
  - `php …`, for the Engine alone
- D3. Every other argv becomes `argv0 php-cli <app>/launch.php <args…>` for a
  site, or `argv0 php-cli <app>/serve.php <args…>` for the Engine. The words
  stay, `start` included.

`launch.php`:

- D4. `command(array $arguments, array $site): array` returns the mode and the
  remaining arguments. `$site` is the decoded `site.json`, for D6. `start`, `drush` and `stop` set the mode. No word, or a leading
  option, means `start`.
- D5. `-h` or `--help` as the first argument prints `HELP` and exits 0.
- D6. A Node word prints the musl message when `site.json` names `node`, and
  exits 1. The message keeps today's text, naming the glibc build.
- D7. Any other word prints `Unknown command: <word>`, a blank line and `HELP`
  to stderr, then exits 1.
- D8. `HELP` takes over the full usage text from `entrypoint.go`: the commands,
  `node`, `clean` and the examples. The help test reads its `Options:` block
  alone, as the deleted entrypoint test did.

Runtime:

- D9. `init()` keeps four things:
  - `DRUPACK_RUNTIME_BINARY`, `DRUPACK_RUNTIME_CWD` and the chdir
  - `php` for the Engine, through `phpArguments`
  - `php-server`, `browser-open` and `--version`
  - `node`, `npm` and `npx`, through `runNode` and its signal forwarding
- D10. Every other argv reaches FrankenPHP unchanged. The unknown-command branch
  goes, since only the launcher and the PHP callers build a runtime argv.
- D11. These go: `usage`, `siteCarriesNode`, `runNode`'s return for a site
  without Node, and the `start`, `drush`, `stop` and help branches.
- D12. `DRUPACK_RUNTIME_DRUSH` and `DRUPACK_RUNTIME_STOP` go: their writes, their
  reads in `launch.php`, and their lines in `runtime/environment.txt`.

Callers and records:

- D13. `build/dev/dev-entry.sh` passes bare options straight to the runtime. It
  changes to `php-cli /data/runtime/app/launch.php`.
- D14. `build/qa.sh` runs `php-cli <probe> a b`, and the probe asserts that
  `$argv` is `[<probe>, 'a', 'b']`.
- D15. An ADR records the routing and amends ADR 0014: the command line is
  described in three places, not four.

## Testing Decisions

A good test feeds an argv and reads the argv, mode or message that comes back.
None reads internal state.

- `launcher/route_test.go`: one table over `runtimeArguments`, one row per D2
  and D3 case, for a site and for the Engine. `detach_test.go` is the prior art
  in that package.
- `launch_test.php`: cases for `command()` covering each mode word, no word,
  help, an unknown word and a Node word with and without `site.json`'s `node`.
  The existing `options()` cases are the prior art.
- The `$argv` probe of D14 runs through `application/tests/cases.php`.

Existing tests:

| Test | Fate |
|---|---|
| launch_test: the entrypoint usage names every option the parser accepts | deleted with its subject; the help-constant test covers `HELP`, the only usage left |
| launch_test: the help constant names every option the parser accepts | kept, reading the `Options:` block |
| launch_test: docs/cli.md names every option the parser accepts | kept |
| argument_cases: an unknown command prints the usage | kept; `launch.php` answers |
| argument_cases: php is an unknown command | kept |
| node_cases: NodeRefusals | kept; `launch.php` answers |
| launcher_cases: the cold and warm `--help` cases | kept |

## Considered options

- A pure `routeFor()` in a third file copied into FrankenPHP's package, like
  `runtime/watch`. It is testable, but keeps two routers, the environment flags,
  and adds a copy line to three build scripts.
- A shared `route` package with a declarative word table that drives routing
  and usage, imported by the runtime and the launcher. It unifies the most. It
  also couples two modules that the repo keeps apart, through an import path
  inside FrankenPHP's tree.
- A routing file like the first, built around one `php-cli` argv builder. It
  claimed to absorb the next FrankenPHP `$argv` change. FrankenPHP's C side
  passes the whole argv to PHP, so no reshaping of `os.Args` undoes that.
- Node moved into the launcher too. On Windows, `launch()` does not forward
  SIGTERM to its child, which `runNode` does.

## Out of Scope

- The PHP callers that build a `php-cli` argv by hand: `launch.php`,
  `process.php`, `serve.php`, `seed.sh` and `qa.sh`.
- Moving `DRUPACK_RUNTIME_CWD` into the launcher.
- The four backlog candidates listed on 2026-10-05.
- A cold `--help` still unpacks the release, as the backlog records.
