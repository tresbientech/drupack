# RFC 0003: a declared environment contract between a start's processes

Proposed on 2026-09-28. Not implemented.

## Goal

Every `DRUPACK_RUNTIME_*` variable is declared once, with the files that write
it and the files that read it. A test fails when the code and the declaration
disagree.

## Problem

A start runs four processes: the launcher, the runtime, `launch.php`, then the
server. The engine executable adds `serve.php` and `php.php`. Nineteen
variables carry state across those hops, and no file declares them:

| Variable | Named in |
|---|---|
| `APP_DIR` | `launcher/main.go`, `runtime/entrypoint.go`, `launch.php`, `settings.php` |
| `BINARY` | `entrypoint.go`, `launch.php`, `serve.php`, `php.php` |
| `BIND`, `DOCROOT`, `PORT` | `launch.php`, `serve.php`, both Caddyfiles |
| `ID` | `entrypoint.go`, `launch.php`, `serve.php`, both Caddyfiles |
| `DATA_DIR` | `launch.php`, `settings.php`, `build/site-settings.php` |
| `FILES_DIR`, `LOG_PATH` | `launch.php`, `application/Caddyfile` |
| `HOST` | `launch.php`, `settings.php` |
| `NAME` | `launcher/main.go`, `entrypoint.go`, `process.php` |
| `SITE_VERSION`, `LAUNCHER` | `launcher/main.go` and one reader |
| `URL`, `OPEN`, `BROWSER`, `CWD`, `DRUSH` | `entrypoint.go`, `launch.php` |
| `CONSOLE_OWNED` | `launcher/launch_windows.go`, `launch.php` |

`application/support/` and `build/` read some of them too.

A Caddyfile placeholder that names no variable resolves to an empty string.
Caddy still starts. A variable renamed in `launch.php` but not in the Caddyfile
gives a server that binds and then serves the wrong root or no log. The
backlog entry "The variables a start passes between its own processes" names
the same gap.

## Proposed interface

A declaration file, `runtime/environment.txt`, in the style of
`runtime/php-extensions.txt`:

```
# name            form  writers                              readers
APP_DIR           path  launcher/main.go application/launch.php  runtime/entrypoint.go application/launch.php application/settings.php
BIND              text  application/launch.php engine/serve.php  application/Caddyfile engine/Caddyfile
CONSOLE_OWNED     flag  launcher/launch_windows.go           application/launch.php
```

`form` records the value's shape: `path` in Drupack's canonical form, `flag`
for `1` or absent, `text` otherwise. The test does not check the form. It tells
a writer which conversion the readers expect.

A test, `tests/conformance/test_environment.py`, reads the file and the
repository. It fails when:

- a source file names a `DRUPACK_RUNTIME_*` variable the declaration lacks;
- a declared writer file does not set the variable, or a declared reader does not read it;
- a declared variable has no writer or no reader.

The test finds writes and reads by pattern, per file type:

| File type | Write | Read |
|---|---|---|
| Go | `os.Setenv("X"`, `"X=` in an `Env` slice | `os.Getenv("X"` |
| PHP | `putenv("X=`, `putenv('X=` | `getenv('X')`, `environment('X')` |
| Caddyfile | none | `{$X}` |
| shell | `X=` at a line start, `export X` | `$X`, `${X}` |

## What it hides

Nothing at runtime. The declaration moves the knowledge of who writes and who
reads out of four languages and into one file. This RFC is the thinnest of the
three architecture RFCs: it draws a boundary rather than deepening a module.

## Dependency strategy

In-process. The test reads files from the checkout and starts no process.

## Testing strategy

The contract test runs in `qa.sh` beside `test_harness.py`, in under a second.
No existing test covers the contract, so none is replaced.

| Existing test | Replacement |
|---|---|
| none | not applicable |

The first version of the test runs against the current tree before the
declaration lands. It fails once per undeclared variable, which is the list the
declaration starts from.

## Debug story

When a variable goes missing six months from now, the engineer runs the
contract test. It names the variable and the file that stopped writing or
reading it.

## Considered options

- A table in a Markdown file checked by the same test. Markdown tables wrap
  and the test parses them less reliably than a whitespace-separated file.
- A generated constant per language from one source. It adds a build step to
  four builds for nineteen names.
- Fewer variables. `launch.php` could write a Caddyfile instead of exporting
  `BIND`, `PORT`, `DOCROOT`, `LOG_PATH` and `FILES_DIR`. That moves the
  contract into a template and keeps its size. It stays open.

Recommendation: the declaration file and the test.

## Consequences

- Adding a variable means one declaration line, and the test says so when it is missing.
- The backlog entry "The variables a start passes between its own processes" closes.
