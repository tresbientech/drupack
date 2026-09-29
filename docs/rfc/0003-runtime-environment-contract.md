# RFC 0003: a smaller, declared environment contract between a start's processes

Proposed on 2026-09-28. Not implemented.

## Goal

Fewer `DRUPACK_RUNTIME_*` variables cross between a start's processes, and
every one that still crosses is declared once. A test fails when the code and
the declaration disagree.

## Problem

A start runs four processes: the launcher, the runtime, `launch.php`, then the
server. The engine executable runs `serve.php` in place of
`launch.php`. Nineteen variables carry state across those hops, and no file
declares them:

| Variable | Named in |
|---|---|
| `APP_DIR` | `launcher/main.go`, `runtime/entrypoint.go`, `launch.php`, `settings.php` |
| `BINARY` | `entrypoint.go`, `launch.php`, `serve.php` |
| `BIND`, `DOCROOT`, `PORT` | `launch.php`, `serve.php`, both Caddyfiles |
| `ID` | `entrypoint.go`, `launch.php`, `serve.php`, both Caddyfiles |
| `DATA_DIR` | `launch.php`, `settings.php`, `build/site-settings.php` |
| `FILES_DIR` | `launch.php`, `application/Caddyfile`, two support classes |
| `LOG_PATH` | `launch.php`, `application/Caddyfile` |
| `HOST` | `launch.php`, `settings.php` |
| `NAME` | `launcher/main.go`, `entrypoint.go`, `process.php` |
| `SITE_VERSION`, `LAUNCHER` | `launcher/main.go` and one reader |
| `URL`, `OPEN`, `BROWSER`, `CWD`, `DRUSH` | `entrypoint.go`, `launch.php` |
| `CONSOLE_OWNED` | `launcher/launch_windows.go`, `launch.php` |

Caddy replaces a `{$VAR}` placeholder with the variable's text before it parses
the Caddyfile, and an unset variable becomes an empty string. Caddy still
starts. A variable renamed in `launch.php` but not in the Caddyfile gives a
server that binds and then serves the wrong root or writes no log. The backlog
entry "The variables a start passes between its own processes" names the same gap.

## Proposal

Two parts, in this order: shrink the contract, then declare what remains.

### Part 1: the entry points write the server's Caddyfile

`launch.php` and `serve.php` compute every value their Caddyfile reads, then
export it only so Caddy can read it back. Each writes the Caddyfile itself
instead, from its template, the way `launch.php` already writes `settings.php`.

- Each template names its values as tokens, `__DRUPACK_PORT__` and the like, as
  the settings template does.
- One renderer, shared through `process.php`, replaces every token and refuses
  to write a file that still holds one.
- `launch.php` writes the site's Caddyfile into Site data's `runtime/`
  directory, under the serving lease, on every start.
- `serve.php` writes the folder's Caddyfile to the system temporary directory,
  under a name derived from the folder and the port, so a restart overwrites it.
- The runtime reads the path from its arguments: `php-server PATH`, as
  `folder-server PATH` does today.
- The template names `guards.caddy` by its absolute path in the application,
  since Caddy resolves an import from the Caddyfile's own directory.

`BIND`, `PORT`, `DOCROOT` and `LOG_PATH` stop crossing. `ID` and `FILES_DIR`
keep their exports for the readers outside Caddy: the runtime's readiness poll
and the files classes that run inside each request.

`launch.php` sets `OPEN` only when a browser opens, so the runtime reads a
non-empty `OPEN` as the flag, and `BROWSER` goes.

Fourteen variables remain: `APP_DIR`, `BINARY`, `CONSOLE_OWNED`, `CWD`,
`DATA_DIR`, `DRUSH`, `FILES_DIR`, `HOST`, `ID`, `LAUNCHER`, `NAME`, `OPEN`,
`SITE_VERSION` and `URL`. Each carries a fact the next process cannot derive.

### Part 2: a declaration file and its test

`runtime/environment.txt`, in the style of `runtime/php-extensions.txt`. One
line per variable, with keyed fields, so a diff shows one line and a malformed
line names its missing key:

```
APP_DIR        writers=launcher/main.go,application/launch.php  readers=runtime/entrypoint.go,application/launch.php,application/settings.php
CONSOLE_OWNED  writers=launcher/launch_windows.go               readers=application/launch.php
```

`tests/conformance/test_environment.py` reads the file and every tracked source
file under `application/`, `build/`, `engine/`, `launcher/` and `runtime/`,
tests excluded. It finds writes and reads by pattern, per file type:

| File type | Write | Read |
|---|---|---|
| Go | `os.Setenv("X"`, `"X=` in an `Env` slice | `os.Getenv("X"` |
| PHP | `putenv("X=`, `putenv('X=` | `getenv('X')`, `environment('X')` |
| shell | `X=` at a line start, `export X` | `$X`, `${X}` |

It fails when:

- a file writes or reads a variable the declaration lacks;
- a declared writer does not write the variable, or a declared reader does not read it;
- a line declares no writer or no reader;
- a template still holds a `{$DRUPACK_RUNTIME_` placeholder.

Each failure names the variable, the file and the line, and says which edit
fixes it. A missing declaration prints a line to paste, with the writers the
test found filled in.

## What it hides

Part 1 hides the server's configuration values inside the two processes that
compute them. Part 2 hides nothing at runtime: it moves the knowledge of who
writes and who reads into one file.

## Dependency strategy

In-process. The renderer is a string function tested on its own. The contract
test reads files from the checkout and starts no process.

## Testing strategy

New tests:

- the renderer replaces every token, and refuses a template with a token it was not given a value for;
- the renderer refuses a value that holds a double quote, the check `launch.php` already makes for `--data-dir` and `--files-dir`;
- the contract test, run by `qa.sh` beside `test_harness.py`;
- a conformance assertion that a start leaves its Caddyfile in Site data's
  `runtime/` directory, holding the port it serves on.

The existing conformance suite covers the served behavior Part 1 must keep:
the listener, the private paths, the public files route, the log file, a Site
data path with a space, and the engine executable's cases.

| Existing test | Replacement |
|---|---|
| none covers the contract | not applicable |

## Debug story

When the server serves the wrong root or writes no log six months from now,
the engineer opens the Caddyfile in Site data's `runtime/` directory, which is
the exact text Caddy parsed. When a variable goes missing between processes,
the contract test names it, the file and the line.

## Considered options

Four designs competed, each under one constraint:

- Minimal artifact: one Python test holding a map from each variable to the
  files that name it. It cannot tell a write from a read, and a comment that
  names a variable hides a deleted `putenv()`.
- Maximum flexibility: an INI section per variable with form, secret and scope,
  a generated reference, and a startup check shipped in the payload. Five
  hand-kept fields per variable, two of them read by nothing but the reference.
- The maintainer adding a variable: the declaration file and test of Part 2,
  over all nineteen names.
- Shrinking the contract: Part 1. It also proposed moving `DRUSH` into argv.
  That changes the option parser [ADR 0014](../adr/0014-the-parser-owns-the-command-line.md)
  governs to save one name, so `DRUSH` stays.

Recommendation: Part 1, then Part 2. Part 1 removes the silent-empty failure at
its source. Part 2 guards the fourteen names that must cross.

## Consequences

- A start writes one more file into Site data, and the engine one into the temporary directory.
- The two Caddyfiles become templates, and `guards.caddy` stays shared by both.
- Adding a variable means one declaration line, and the test says so when it is missing.
- The backlog entry "The variables a start passes between its own processes" closes.
